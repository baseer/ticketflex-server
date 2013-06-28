<?php
App::import('Controller','JSON');
class TicketsController extends JSONController {
	public $uses = array('Event','Ticket', 'User');
	
	/**
	 * List tickets. You can filter tickets by providing GET parameters user_id, event_id, and status.
	 * The QR codes of tickets will only be included if the GET parameter user_id is provided and it is the user_id of the
	 * current logged in user.
	 *
	 * Respond with the list of tickets.
	 */
	public function index(){
		$conditions = array();
		$contain =	array(
			'Event'=>array('Image'),
			'User'=>$this->User->publicFields,
		);
		$fields = $this->Ticket->publicFields;
		
		if (!empty($_GET['user_id'])){
			$conditions['Ticket.user_id'] = $_GET['user_id'];
			$user = $this->requireUser($_GET['user_id']);
			// If the logged in user is the owner of these tickets, include the QR codes.
			if ($user){
				$fields = array_merge($fields, $this->Ticket->privateFields);
			}
		}
		// Filter by event_id if provided.
		if (!empty($_GET['event_id'])){
			$conditions['Ticket.event_id'] = $_GET['event_id'];
		}
		// Filter by ticket status if provided.
		if (!empty($_GET['status'])){
			$conditions['Ticket.status'] = $_GET['status'];
		}
		
		$findOptions = array(
			'contain'=>$contain,
			'conditions'=>$conditions,
			'fields'=>array_merge($fields, array('Event.*'))
		);
		$this->setResponseJson($this->Ticket->find('all', $findOptions));
	}
	
	/**
	 * Buy a new ticket for the given $eventID, if the event is not full.
	 * Respond with the new ticket record.
	 */
	public function buy($eventID){
		$user = $this->requireUser();
		if ($user){
			$event = $this->Event->findById($eventID);
			if (!$event){
				$this->setErrorJson(array(
					'message'=>$this->invalidTicketMessage($eventID),
					'friendly_message'=>$this->invalidTicketFriendlyMessage()
				));
			}
			else {
				//$numRemainingTickets = $this->Event->numRemainingTickets($eventID);
				$totalTickets = $this->Ticket->find('count', array('conditions'=>array('event_id'=>$eventID)));
				
				// If the event has already reached capacity, throw an error.
				if ((int)$event['Event']['capacity'] <= $totalTickets){
					$this->setErrorJson(array(
						'message'=>"Event with id $eventID has no remaining tickets.",
						'friendly_message'=>"This event is full."
					));
				}
				// Otherwise, try to create a ticket.
				else {
					// If the user already has a ticket, throw an error.
					$userHasTicket = $this->Ticket->find('count',
						array('conditions'=>array('user_id'=>$user['User']['id'], 'event_id'=>$eventID)));
					if ($userHasTicket >= 1){
						$this->setErrorJson(array(
							'message'=>"Cannot buy ticket because user with id {$user['User']['id']} already has a ticket for event with id $eventID.",
							'friendly_message'=>"You already have a ticket."
						));
					}
					// Otherwise, create a new ticket.
					else {
						$saveData = array(	
							'event_id'=>$eventID,
							'user_id'=>$user['User']['id'],
							'qr_code'=>$this->Ticket->generateQRCode(),
							'status'=>'active'
						);
						$ticket = $this->Ticket->save($saveData);
						if ($ticket){
							$this->setResponseJson($this->Ticket->findById($this->Ticket->id));
						}
						else {
							$this->setErrorJson(array(
								'message'=>"Database Error: Could not buy ticket for user with id {$user['User']['id']} and event with id $eventID",
								'friendly_message'=>"We could not get a ticket at this time."
							));
						}
						/*$ticket = null;
						$isFull = false;
						// If the event has not fulfilled it's entire capacity yet, create a new ticket.
						if ($event['Event']['capacity'] == null || $totalTickets < $event['Event']['capacity']){
							$saveData = array(	
								'event_id'=>$eventID,
								'user_id'=>$user['User']['id'],
								'qr_code'=>$this->Ticket->generateQRCode(),
								'status'=>'active'
							);
							$ticket = $this->Ticket->save($saveData);
						}
						// The event has reached it's capacity, buy an existing ticket if one is available.
						else {
							// Get the first ticket that was on sale - ticket sellers are treated on a first-come
							// first-serve basis.
							$onSaleTicket = $this->Ticket->find('first',
								array(
									'order'=>array('modified ASC'),
									'conditions'=>array('event_id'=>$eventID, 'status'=>'on-sale')));
							if ($onSaleTicket){							
								$saveData = array(	
									'id'=>$onSaleTicket['Ticket']['id'],
									'user_id'=>$user['User']['id'],
									'qr_code'=>$this->Ticket->generateQRCode(),
									'status'=>'active'
								);
								$ticket = $this->Ticket->save($saveData);
							}
							else {
								$isFull = true;
							}							
						}
						if ($ticket){
							$this->setResponseJson($this->Ticket->findById($this->Ticket->id));
						}
						else if ($isFull){
							$this->setErrorJson(array(
								'message'=>"Could not buy ticket for user with id {$user['User']['id']} and event with id $eventID. No tickets are on sale.",
								'friendly_message'=>"We could not get a ticket because the event is full."
							));
						}
						else {
							$this->setErrorJson(array(
								'message'=>"Database Error: Could not buy ticket for user with id {$user['User']['id']} and event with id $eventID",
								'friendly_message'=>"We could not get a ticket at this time."
							));
						}*/
					}
				}
			}
		}
		else {
			$this->setErrorJson($this->requireUserError());
		}
	}
	
	/**
	 * Buy an existing ticket from another ticket holder, given their ticket id.
	 * The ticket must have a status of 'on-sale'.
	 * Respond with the new ticket once it has been transferred to the user.
	 */
	public function buyExisting($ticketID){
		$ticket = $this->requireTicket($ticketID);
		if ($ticket){
			$user = $this->requireUser();
			if ($user){
				// If the ticket is on-sale, transfer the ticket to the current user.
				if ($ticket['Ticket']['status'] == 'on-sale'){
					$success = $this->Ticket->save(array(
						'id'		=> $ticketID,
						'qr_code'	=> $this->Ticket->generateQRCode(),
						'status'	=> 'active',
						'user_id'	=> $user['User']['id'],
					));
					if ($success){
						$this->setResponseJson($this->Ticket->findById($this->Ticket->id));
					}
					else {
						$this->setErrorJson(array(
							'message'=>"Database error: Could not transfer ticket to user id {$user['User']['id']}.",
							'friendly_message'=>"We could not buy a ticket at this time."
						));
					}
				}
				// Otherwise, throw an error.
				else {
					$this->setErrorJson(array(
						'message'=>"Ticket with id $ticketID is not on sale.",
						'friendly_message'=>"That ticket is not on sale.",
					));
				}
			}
			else {
				$this->setErrorJson($this->requireUserError());
			}
		}
	}
	
	/**
	 * Helper method to return a generic access token error response.
	 */
	private function requireUserError(){	
		return array(
			'message'=>"Invalid access token.",
			'friendly_message'=>"Please login to buy tickets."
		);
	}
	
	/**
	 * Method used internally to ensure that the given $ticketID points to an actual ticket record.
	 * Returns the ticket if $ticketID is valid. False otherwise.
	 */
	private function requireTicket($ticketID){
		$ticket = $this->Ticket->findById($ticketID);
		if (!$ticket) {
			$this->setErrorJson(array(
				'message'=>$this->invalidTicketMessage($ticketID),
				'friendly_message'=>$this->invalidTicketFriendlyMessage()
			));
		}
		return $ticket;
	}
	
	/**
	 * Sell a ticket for the given price. The price must be at most equal to the event price.
	 * Respond with the ticket record.
	 */
	public function sell($ticketID, $price){		
		$errorJson = array(
			'message'=>"Could not put ticket $ticketID on sale.",
			'friendly_message'=>"Sorry, we could not put your ticket up for sale at this time.",
		);
		$price = (float)$price;
		$ticket = $this->requireTicket($ticketID);
		$eventPrice = (float)$ticket['Event']['price'];
		// If the selling price is empty, or less than or equal to 0, throw an error.
		if (empty($price) || $price <= 0){
			$this->setErrorJson(array(
				'message'=>"Invalid price $price",
				'friendly_message'=>"Please enter a valid price.",
			));
		}
		// If the selected price is higher than the original event price, throw an error.
		else if ($price > $eventPrice){
			$this->setErrorJson(array(
				'message'=>"Selling price (\$$price)cannot be higher than event price of \$$eventPrice.",
				'friendly_message'=>"Ticket price cannot be more than \$$eventPrice.",
			));
		}
		// Otherwise, put the ticket up for sale for the selected price.
		else {
			$this->changeStatus($ticketID, 'on-sale', $errorJson, $price);
		}
	}
	
	/**
	 * Take back a ticket that has previously been put on sale.
	 * Respond with the ticket record.
	 */
	public function unsell($ticketID){
		$errorJson = array(
			'message'=>"Could not unsell ticket $ticketID.",
			'friendly_message'=>"We could not get your ticket back.",
		);
		$this->changeStatus($ticketID, 'active', $errorJson);
	}
	
	/**
	 * This method is used internally by sell() to change the status field of a ticket and set a new price.
	 * Requires the user to be the owner of the ticket.
	 */	
	private function changeStatus($ticketID, $status, $errorJson, $newPrice=null){
		$ticket = $this->requireTicket($ticketID);
		if (!$ticket) return;
		$user = $this->requireUser($ticket['User']['id']);
		if (!$user)	return;
		
		$saveData = array('id'=>$ticketID, 'status'=>$status);
		if ($newPrice != null){
			$saveData['price'] = $newPrice;
		}
		$statusChanged = $this->Ticket->save($saveData);
		if ($statusChanged)	$this->setResponseJson($this->Ticket->findById($ticketID));
		else {
			$this->setErrorJson($errorJson);
		}
	}
	
	/**
	 * Respond with the ticket given by $ticketID.
	 * The QR code will be included in the response if the user is the owner of the ticket.
	 */
	public function view($ticketID){
		$ticket = $this->requireTicket($ticketID);
		if ($ticket){
			$user = $this->requireUser($ticket['User']['id']);
			// If the current user is not the owner of the ticket, remove private information.
			if (!$user){
				foreach ($ticket['Ticket'] as $field=>$value){
					if (in_array($field, $this->Ticket->privateFields)){
						unset($ticket['Ticket'][$field]);
					}
				}
				foreach ($ticket['User'] as $field=>$value){
					if (in_array($field, $this->User->privateFields)){
						unset($ticket['User'][$field]);
					}
				}
			}
			$this->setResponseJson($ticket);
		}
	}
	
	/**
	 * Given a QR code, Respond with the corresponding ticket.
	 * The user must be the creator of the event that that ticket is for.
	 */
	public function viewByQrCode($qrCode){
		$user = $this->requireUser();
		if (!$user){
			$this->setErrorJson($this->requireUserError());
		}
		else {
			$ticket = $this->Ticket->findByQrCode($qrCode);
			if (!$ticket) {
				$this->setErrorJson(array(
					'message'=>"Could not find ticket with qr code: $qrCode",
					'friendly_message'=>$this->invalidTicketFriendlyMessage()
				));
			}
			else {
				if (empty($ticket['Event']['creator_id']) || $ticket['Event']['creator_id'] != $user['User']['id']){
					$this->setErrorJson(array(
						'message' => "Cannot retrieve ticket because the user with id {$user['User']['id']} is not the event organizer.",
						'friendly_message' => "You must be the event organizer to scan a QR code."
					));
				}
				else {
					$this->setResponseJson($ticket);
				}
			}
		}
	}
	
	/**
	 * This method is used by both admit() and decline() since both require similar logic.
	 * The ticket given by $ticketID will have its status field updated to $newStatus.
	 * The user must be the creator in order to change the status.
	 *
	 * Respond with the ticket record.
	 */
	private function adminAction($ticketID, $newStatus){
		$ticket = $this->requireTicket($ticketID);
		if ($ticket){
			if (!empty($ticket['Event']['creator_id'])){
				$adminUserID = $ticket['Event']['creator_id'];
				$user = $this->requireUser($adminUserID);
				if ($user){
					if ($newStatus != null){
						$result = $this->Ticket->save(array('id'=>$ticketID, 'status'=>$newStatus));
					}
					else {
						$result = true;
					}
					if ($result){
						$this->setResponseJson($this->Ticket->findById($ticketID));
					}
					else {
						$this->setUnexpectedErrorJson();
					}
				}
			}
			else {
				$this->setUnexpectedErrorJson();
			}
		}
	}
	
	/**
	 * Helper method that returns a boolean indicating whether the logged in user is allowed to admit or decline a ticket.
	 * Only event organizers can change admit/decline tickets for their events, as long as the ticket is not put up for
	 * sale.
	 */
	private function canChangeTicketStatus($ticketID){
		$ticket = $this->requireTicket($ticketID);
		if ($ticket){
			if (empty($ticket['Ticket']['status']))
				return false;
			if ($ticket['Ticket']['status'] != 'active')
				return "That ticket cannot be admitted because it is currently on sale.";
			if (empty($ticket['Event']['creator_id']))
				return false;
			$creatorID = $ticket['Event']['creator_id'];
			$user = $this->requireUser($creatorID);
			
			return (bool)$user;
		}
		return false;
	}
	
	/**
	 * Admit a ticket into the event. This will change the ticket's status field to 'admitted'.
	 * Respond with the ticket record.
	 */
	public function admit($ticketID){
		$canChangeTicketStatus = $this->canChangeTicketStatus($ticketID);
		if ($canChangeTicketStatus === true){
			$this->adminAction($ticketID, 'admitted');
		}
		else {
			$this->throwTicketStatusChangeError($canChangeTicketStatus);
		}
	}
	
	/**
	 * Helper method that returns the error response that should be thrown when the user does not have permission to
	 * admit/decline a ticket.
	 */
	private function throwTicketStatusChangeError($canChangeTicketStatus){
		if (is_string($canChangeTicketStatus))
			$friendlyMessage = $canChangeTicketStatus;
		else 
			$friendlyMessage = "We could not admit that ticket at this time.";
		
		$this->setErrorJson(array(
			'message'=>"Current user does not have rights to update ticket with id $ticketID",
			'friendly_message'=>$friendlyMessage
		));
	}
	/**
	 * Decline a ticket. Currently, declining a ticket does not change the ticket's status field at all.
	 * The end result is no change, but this endpoint should be hit in case we want to record ticket declines in the future.
	 * This means, the user can still be admitted later even if they have been declined once.
	 *
	 * Respond with the ticket record.
	 */
	public function decline($ticketID){
		$canChangeTicketStatus = $this->canChangeTicketStatus($ticketID);
		if ($canChangeTicketStatus === true){
			$this->adminAction($ticketID, null);
		}
		else {
			$this->throwTicketStatusChangeError($canChangeTicketStatus);
		}
	}
	
	private function invalidTicketMessage($ticketID){
		return "Could not find ticket with id $ticketID";
	}
	private function invalidTicketFriendlyMessage(){
		return "The ticket you requested was not found.";
	}
}
