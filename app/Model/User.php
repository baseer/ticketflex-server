<?php
class User extends AppModel {
	public $publicFields = array('id','twitter_id','facebook_id','name','created');
	public $privateFields = array('modified', 'facebook_access_token', 'access_token');
}