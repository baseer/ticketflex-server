<?php
App::uses('AppModel', 'Model');

class Facebook extends AppModel {
	const CURL_TIMEOUT = 28; // This is the internal cURL error code that is thrown when timeouts occur.
	public static $connection;
	public $useTable = false; // Disable CakePHP model introspection since the OpenGraph model does not use a SQL database.
	public $recursive = -1;
	public static $httpHeaders = array();
	private $paginateCount = 0; // Used to store the total number of results.
	public static $accessToken = null;
	
	public $defaultFilters = array(
		'q', // search query
		'order_by',
		'order',
		'n', // num_results
		'o',  // offset
	);
	
	public function __construct($id = false, $table = null, $ds = null) {
		if (gettype(self::$connection) != 'resource'){
			self::$connection = curl_init();
			curl_setopt(self::$connection, CURLOPT_RETURNTRANSFER, true);
			curl_setopt(self::$connection, CURLOPT_CONNECTTIMEOUT, Configure::read('Facebook.OpenGraph.timeout'));
		}
		// curl_close(Facebook::$connection) is called in AppController::beforeRender(), after all requests to Facebook are done.
		return parent::__construct($id, $table, $ds);
	}
	
	public function get($url = null, $data = array(), $options = array()){
		curl_setopt(self::$connection, CURLOPT_HTTPGET, true);
		$query = http_build_query($data);
		$query = rtrim($query, '=');
		if (!empty($query)){
			if (strpos($url, '?') === false)	$url .= '?';
			else								$url .= '&';
			$url .= $query;
		}
		
		$response = self::request($url, $options);
		return self::processResponse($response, $options);
	}
	
	public function post($url = null, $data = array(), $options = array()){
		curl_setopt(self::$connection, CURLOPT_POST, true);
		curl_setopt(self::$connection, CURLOPT_POSTFIELDS, $data);
		
		$response = self::request($url, $options);
		return self::processResponse($response, $options);
	}
	
	public function delete($url, $options = array()){
		curl_setopt(self::$connection, CURLOPT_CUSTOMREQUEST, 'DELETE');
		
		$response = self::request($url, $options);
		return self::processResponse($response, $options);
	}
	
	private static function processAccessToken($url, $options){
		if (!empty($options['access_token'])){
			$accessToken = false; // We will set this below, if available
			
			// If 'access_token' is true, use the existing access_token from the session (if available)
			if ($options['access_token'] === true){	
				// Get the user from session since and use the access_token if it exists
				$accessToken = self::$accessToken;
			}
			
			// If 'access_token' is already provided as a string, use it instead
			if (is_string($options['access_token']))
				$accessToken = $options['access_token'];
			
			// If we have an access_token to use, add it as a GET parameter
			if (!empty($accessToken)){
				if (strpos($url, '?') === false)	$url .= '?';
				else								$url .= '&';
				$url .= "access_token=$accessToken";
			}
		}
		return $url;
	}
	
	private static function isValidURL($url){
		return (bool)filter_var($url, FILTER_VALIDATE_URL, FILTER_FLAG_PATH_REQUIRED);
	}
	
	private static function request(&$url, &$options){
		$originalUrl = $url;
		$url = self::processAccessToken($url, $options);
		
		$prependURL = '';
		if (isset($options['prependURL']))		$prependURL = $options['prependURL'];
		// If it's not a valid url, prepend http://graph.facebook.com/ to make it valid.
		else if (!self::isValidURL($url)){
			$prependURL = Configure::read('Facebook.OpenGraph.URL');
		}
		
		$fullURL = $prependURL.$url;
		if (self::isValidURL($fullURL)){
			curl_setopt(self::$connection, CURLOPT_URL, $fullURL);
			
			$httpHeadersRaw = array();
			foreach (self::$httpHeaders as $header=>$value){
				$httpHeadersRaw[] = $header.': '.$value;
			}
			curl_setopt(self::$connection, CURLOPT_HTTPHEADER, $httpHeadersRaw);
			$rawResponse = curl_exec(self::$connection);
			self::handleConnectionError(self::$connection);
		}
		else {
			throw new InternalErrorException('Invalid URL to connect to');
		}
		
		return $rawResponse;
	}
	
	/* Redirect the user to a 500 page if there was an error.
	 * Usually used in cases where we can't connect to the OpenGraph API.
	 */
	public static function handleConnectionError($curlHandle){
		if (curl_errno($curlHandle)){
			$message = curl_error($curlHandle);
			throw new InternalErrorException($message);
		}
	}
		
	public static function processResponse($response, $options=array()){
		if (!empty($options['raw_response']) && $options['raw_response'] === true)
			return $response;
		else{
			// Strip out all control characters since they can cause json decoding issues
			// Specifically, a JSON_ERROR_CTRL_CHAR would be thrown if control characters are present.
			$response = preg_replace('/[[:cntrl:]]/', '', $response);
			return json_decode($response, false);
		}
	}
	
	public static function isError($response){
		return (is_object($response) && !empty($response->error)) || empty($response);
	}
	
	public static function isAuthError($response){
		return 	Facebook::isError($response) && !empty($response->error->class) && 
				in_array($response['error']['class'], array('INVALID-ACCESS-TOKEN',
															'ACCESS-TOKEN-REQUIRED',
															'EXPIRED-ACCESS-TOKEN'));
	}
	
	public static function sanitizeID($id){
		return preg_replace('/[^A-Za-z0-9-]/', '', $id);
	}
	
	public function paginate($conditions, $fields, $order, $limit, $page = 1, $recursive = null, $extra = array()) {
		// Anything in 'conditions' will contain GET parameters, so just add them to $parameters.
		$parameters = $conditions;
		
		// Get the sorting parameters, if any.
		// 'sort', and 'direction' in cake correspond to 'order_by', and 'order' (respectively) in the Open Graph API.
		if (!empty($extra['sort'])){
			$parameters['order_by'] = $extra['sort']; // field name (price, item_condition, etc.)
			if (!empty($extra['direction']))
				$parameters['order'] = strtolower($extra['direction']); // asc or desc
		}
		
		// Create the GET parameters for limit and page number
		// (these cakePHP parameters correspond to 'n' and 'o' in the Open Graph API)
		if (!empty($limit)){
			$parameters['n'] = $limit;
			// Page number
			if (!empty($page)){
				$parameters['o'] = $limit * ($page - 1);
			}
		}
		
		// Remove those parameters that aren't allowed:
		// the ones that aren't listed in $this->filters (specific to model) nor $this->defaultFilters (general filters).
		$filters = $this->defaultFilters;
		if (!empty($this->filters))			$filters = array_merge($filters, $this->filters);
		$parameters = array_intersect_key($parameters, array_combine($filters, $filters));
		
		$results = $this->getAll($parameters);
		// Set the paginateCount variable so we know how many results we've received.
		if (!empty($results['query_info']['total_num_results']))
			$this->paginateCount = $results['query_info']['total_num_results'];
			
		return $results;
	}
	
	public function paginateCount($conditions = null, $recursive = 0, $extra = array()){
		return $this->paginateCount;
	}
}