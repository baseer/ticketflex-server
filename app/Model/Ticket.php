<?php
class Ticket extends AppModel {
	public $belongsTo = array('Event', 'User');
	public $publicFields = array('id', 'event_id', 'user_id', 'status', 'price', 'created');
	public $privateFields = array('qr_code', 'modified');
	public $order="Event.start_time ASC"; 
	
	public function generateQRCode(){
		$QRCode = null;
		while ($QRCode == null || $this->findByQrCode($QRCode)){
			$QRCode = $this->generateRandomString(12);
		}
		return $QRCode;
	}
	private function generateRandomString($length){
		$characters = '0123456789abcdefghijklmnopqrstuvwxyz';
		$string = '';
		for ($c=0; $c<$length; $c++){
			$string .= $characters[mt_rand(0, strlen($characters)-1)];
		}
		return $string;	
	}
}