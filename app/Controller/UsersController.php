<?php
App::import('Controller','JSON');
App::uses('Facebook','Model');
App::uses('Security','Utility');
class UsersController extends JSONController {
	public $uses = array('Facebook', 'FacebookUser');
	
	/**
	 * Login with Facebook
	 * Given a Facebook access token, contact Facebook to get their name, and Facebook id.
	 * If Facebook returns a successful response, return a TicketFlex User object (creating one if necessary).
	 */
	public function facebookLogin($facebookToken){
		Facebook::$accessToken = $facebookToken;
		// Get the Facebook user object.
		$facebookUser = $this->FacebookUser->getMe();
		// If there's been an error from Facebook, return the error response from Facebook.
		if (Facebook::isError($facebookUser)){
			$error = $facebookUser->error;
			$this->set(array('error'=>$facebookUser->error));
			$this->set('_serialize', array('error'));
		}
		// Otherwise, return a TicketFlex user.
		else {
			$userData = array(
				'name'=>$facebookUser->name,
				'facebook_id'=>$facebookUser->id,
				'facebook_access_token'=>$facebookToken,
			);
			$existingUser = $this->User->findByFacebookId($facebookUser->id);
			
			// If the Facebook id does not exist in the users table, create a new entry in the users table.
			if (!$existingUser){
				$accessToken = '';
				while ($accessToken === '' || $this->User->findByAccessToken($accessToken))
					$accessToken = Security::hash(uniqid().rand(), null, true);
				$userData['access_token'] = $accessToken;
			}
			// Otherwise, return the existing user from the users table.
			else {
				$userData['id'] = $existingUser['User']['id'];
				$accessToken = $existingUser['User']['access_token'];
			}
			
			$this->User->saveAll($userData);
			$this->setResponseJson($this->User->findById($this->User->id));
		}
	}
}