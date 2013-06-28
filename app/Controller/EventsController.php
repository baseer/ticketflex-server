<?php
App::import('Controller','JSON');
class EventsController extends JSONController {
	public $uses = array('Event','Ticket','Image');
	/**
	 * Respond with a list of all events, and their associated event images. Order from earliest to latest event start time.
	 */
	public function index(){
		$findOptions = array(
			'recursive'=>0,
			'contain'=>array(
				'Image'
			),
			'order'=>array('Event.start_time')
		);
		$response = $this->Event->find('all', $findOptions);
		$this->setResponseJson($response);
	}
	
	/**
	 * Respond with a list of all events that the current logged in user has created, along with the event images.
	 * Same sorting as index().
	 */
	public function adminIndex(){
		$user = $this->requireUser();
		if (!$user){
			$this->setErrorJson(array(
				'message'=>'Access token required to administer events.',
				'friendly_message'=>'Please login to administer your events.'
			));
		}
		else {
			$findOptions = array(
				'recursive'=>0,
				'contain'=>array(
					'Image'
				),
				'order'=>array('Event.start_time'),
				'conditions'=>array('Event.creator_id'=>$user['User']['id'])
			);
			$response = $this->Event->find('all', $findOptions);
			$this->setResponseJson($response);
		}
	}
	
	/**
	 * Create an event or edit an existing event. To edit, pass the event ID into $id.
	 * A multipart POST request is required if you are uploading an image.
	 * Editable fields of the event record are: name, description, location, start_time, end_time, price, and capacity, and
	 * 		image.
	 * image is required to be of mime type image/jpeg.
	 */
	public function edit($id=null){
		$user = $this->requireUser();
		if ($user && !empty($this->data)){
			$saveData = array();
			// Indicate which fields of the event record are editable using POST field-value pairs.
			$editableFields = array(
				'name', 'description', 'location', 'start_time', 'end_time', 'price', 'capacity'
			);
			$timeFields = array('start_time','end_time');
			
			$validSoFar = true;
			// If we're editing an existing event, ensure that the event exists and belongs to the logged in user first.
			if ($id != null){
				$event = $this->Event->findById($id);
				if ($event){
					$user = $this->requireUser($event['Event']['creator_id']);
					if (!$user){
						$this->setErrorJson(array(
							'friendly_message'=>"You cannot edit someone else's event.",
							'message'=>"Event $value does not belong to {$event['creator_id']}."
						));
						$validSoFar = false;
					}
					else {
						if (!isset($saveData['Event']))		$saveData['Event'] = array();
						$saveData['Event']['id'] = $id;
					}
				}
				else {
					$this->setErrorJson(array(
						'friendly_message'=>'The event you are trying to edit is not available.',
						'message'=>"Invalid event id: $value"
					));
					$validSoFar = false;
				}
			}
			
			// Set the POST fields that exist in $editableFields into $saveData.
			if ($validSoFar){
				foreach ($this->data as $name=>$value){
					if (in_array($name, $editableFields)){
						if (!isset($saveData['Event']))		$saveData['Event'] = array();
						if (in_array($name, $timeFields))
							$saveValue = date("Y-m-d h:i:s", strtotime($value));
						else
							$saveValue = $value;
						$saveData['Event'][$name] = $saveValue;
					}
				}
			}
			
			// Set the image received into $saveData.
			if ($validSoFar && !empty($_FILES['image'])){
				if ($_FILES['image']['error'] == UPLOAD_ERR_OK && is_uploaded_file($_FILES['image']['tmp_name'])){
					$image = file_get_contents($_FILES['image']['tmp_name']);
					$saveData['Image'] = array('image'=>$image, 'mime'=>'image/jpeg');
				}
				else {
					$this->setErrorJson(array(	'message'=>"Invalid Image: Error code {$_FILES['image']['error']}",
												'friendly_message'=>"Invalid Image"));
					$validSoFar = false;
				}
			}
			// If we're creating a new event, and the image is not provided, throw an error response.
			else if ($id == null){
				$this->setErrorJson(array(	'message'=>"Image not provided.",
											'friendly_message'=>"Please select an event image.",));
				$validSoFar = false;
			}
			
			// If everything is valid, create/edit the event.
			if ($validSoFar){
				$saveData['Event']['creator_id'] = $user['User']['id'];
				// saveAll() is also responsible for validating the fields provided in $saveData.
				// If fields are missing, $result will be false and the event won't be saved.
				// See the Event model for validation rules.
				$result = $this->Event->saveAll($saveData);
				
				// If we could not save the event, most likely due to missing fields, throw an error response.
				if (!$result){
					$error = array(
						'friendly_message'=>'Please fill in all fields correctly.',
						'message'=>'Could not save the event with data:'
					);
					foreach ($saveData['Event'] as $name=>$value){
						$error['message'] .= "\n$name: $value";
					}
					$this->setErrorJson($error);
				}
				// Otherwise, respond with the event record.
				else {
					$response = $this->Event->findById($this->Event->id);
					$response = $this->Event->addTicketCounts($response);
					$this->setResponseJson($response);
				}
			}
		}
		// If they're not logged in, throw an error response.
		else if (!$user){
			$this->setErrorJson(array(
				'message' => "Access token required.",
				'friendly_message' => "Please login to edit this event.",
			));
		}
	}
	
	/**
	 * Respond with the event record given by the event ID $id, along with the event image.
	 * If the user is logged in, and has a ticket for the given event, then also attach their ticket into the response.
	 * If the user is the event creator, also attach the list of tickets.
	 */
	public function view($id){
		$creatorID = $this->Event->field('creator_id', array('Event.id'=>$id));
		
		$findOptions = array(	'conditions'=>array('Event.id'=>$id),
								'contain'=>array("Image"));
		if ($user = $this->getCurrentUser()){
			// If the logged in user is the creator of this event, show the tickets for this event.
			if ($creatorID === $user['User']['id']){
				unset($findOptions['recursive']);
				$findOptions['contain']['Ticket'] = array(
					'User.id','User.name','User.facebook_id','User.twitter_id','User.created',
				);
			}
		}
		$event = $this->Event->find('first', $findOptions);
		if ($event){
			$event = $this->Event->addTicketCounts($event);
			$response = $event;
			// If the logged in user has a ticket for this event, attach it to the response.
			$myTicket = $this->Ticket->findByUserIdAndEventId($user['User']['id'],$event['Event']['id']);
			if ($myTicket)
				$response['MyTicket'] = $myTicket['Ticket'];
			$this->setResponseJson($response);
		}
		else {
			$this->setErrorJson(array(
				'message'=>"Invalid event with id: $id",
				'friendly_message'=>"The event you're looking for was not found."
			));
		}
	}
	
	/*
	// To get the event image, see the ImagesController instead of using this method.
	public function image($eventID){
		$event = $this->Event->find('first',
									array(	'conditions'=>array('Event.id'=>$eventID),
											'fields'=>array('Image.image','Image.mime')));
		if ($event){
			$image = $event['Image']['image'];
			if (empty($image)){
				$this->setErrorJson(array(
					'message'=>"Event with id $eventID does not have an image.",
					'friendly_message'=>"This event does not have an image.",
				));
			}
			else {
				$mime = $event['Image']['mime'];
				$validMimes = array('image/jpeg','image/png','image/gif');
				if (in_array($mime, $validMimes, true)){
					header('Content-Type: '.$mime);
					echo $image;
					exit;
				}
				else {
					$this->setErrorJson(array(
						'message'=>"Event with id $eventID has an invalid mime type of $mime. It must be one of: ".
									implode(",", $validMimes),
						'friendly_message'=>"Event image not found."
					));
				}
			}
		}
		else {
			$this->setErrorJson(array(
				'message'=>"Invalid event with id: $eventID",
				'friendly_message'=>"The event you're looking for was not found."
			));
		}
	}*/
}