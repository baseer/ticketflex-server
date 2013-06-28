<?php
class JSONController extends AppController {
	public function beforeFilter(){
		//debug($this);
		$this->viewClass = 'Json';
		parent::beforeFilter();
	}
	/**
	 * Set a successful response.
	 */
	function setResponseJson($response){
		$this->setJson($response, 'response');
	}
	
	/**
	 * Set an error response.
	 */
	function setErrorJson($error){
		//$this->response->statusCode(500);
		$this->setJson($error, 'error');
	}
	
	/**
	 * Set an unexpected error response - use it when you don't have/want to provide extra details.
	 */
	function setUnexpectedErrorJson(){
		$this->setErrorJson(array('message'=>'Unexpected error', 'friendly_message'=>"Sorry, that last action could not be completed."));
	}
	/**
	 * Internal method used by both setResponseJson() and setErrorJson().
	 */
	private function setJson($json, $key){
		$this->set(array($key=>$json));
		$this->set('_serialize', array($key));	
	}
	/**
	 * Convenient method to ensure that the user has logged in.
	 * Return the user record if they are, and false if they're not.
	 * If $requireUserID is passed in, the user record is only returned if the user logged in has an id equal to
	 * $requireUserID. False otherwise.
	 */
	function requireUser($requireUserID=null){
		if (!empty($_GET['access_token']))
			$accessToken = $_GET['access_token'];
		else {
			$this->setErrorJson(array('message' => "Access token required", 'friendly_message'=>"Please login to continue."));
			return false;
		}
		
		$user = $this->User->findByAccessToken($accessToken);
		if (!$user){
			$this->setErrorJson(array('message' => "Invalid access token", 'friendly_message'=>"Please login to continue."));
			return false;
		}
		
		if ($requireUserID !== null && $user['User']['id'] != $requireUserID){
			$this->setErrorJson(array(	'message' => "Invalid Access Token. Given User ID: {$user['User']['id']} Required User ID: $requireUserID",
										'friendly_message'=>"Please login to continue."));
			return false;
		}
		return $user;
	}
	
	/**
	 * Get the current user record.
	 */
	function getCurrentUser(){
		if (!empty($_GET['access_token']))
			return $this->User->findByAccessToken($_GET['access_token']);
		return false;
	}
}