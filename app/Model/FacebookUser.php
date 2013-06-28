<?php
App::uses('Facebook', 'Model');

class FacebookUser extends Facebook {
	public function getMe(){
		return $this->get('/me', array(), array('access_token'=>true));
	}
}