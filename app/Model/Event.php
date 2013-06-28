<?php
class Event extends AppModel {
	public $belongsTo = array('Image');
	public $hasMany = array('Ticket');
	public $order="Event.start_time ASC"; 
	
	public $validate = array(
		'name'=>array(
			'rule'=>'notEmpty',
			'required'=>true
		),
		'description'=>array(
			'rule'=>'notEmpty',
			'required'=>true
		),
		'location'=>array(
			'rule'=>'notEmpty',
			'required'=>true
		),
		'start_time'=>array(
			'rule'=>'notEmpty',
			'required'=>true
		),
		'price'=>array(
			'rule'=>'notEmpty',
			'required'=>true
		),
		'capacity'=>array(
			'rule'=>'notEmpty',
			'required'=>true
		),
	);
	
	/**
	 * Given the event response, add the fields num_active_tickets, and num_remaining_tickets.
	 */
	public function addTicketCounts($event){
		$event['Event']['num_active_tickets'] = $this->numActiveTickets($event['Event']['id']);
		$event['Event']['num_remaining_tickets'] = $this->numRemainingTickets($event['Event']['id']);
		return $event;
	}
	
	/**
	 * 
	 * Return number of tickets with status=active.
	 */
	public function numActiveTickets($eventID){
		return $this->Ticket->find('count', array('conditions'=>array('status'=>'active', 'event_id'=>$eventID)));
	}
	
	/**
	 * Return number of existing tickets plus how many are put up for sale.
	 */
	public function numTotalTickets($eventID){
		return $this->Ticket->find('count', array('conditions'=>array('event_id'=>$eventID)));
	}	
	
	/**
	 * Return number of tickets put up for sale.
	 */
	public function numOnSaleTickets($eventID){
		return $this->Ticket->find('count', array('conditions'=>array('status'=>'on-sale', 'event_id'=>$eventID)));
	}
	
	/**
	 * Return number of new tickets are remaining plus how many are put up for sale.
	 */
	public function numRemainingTickets($eventID){
		$event = $this->findById($eventID);
		$numTotalTickets = $this->numTotalTickets($eventID);
		return ($event['Event']['capacity'] - $numTotalTickets) + $this->numOnSaleTickets($eventID);
	}
}