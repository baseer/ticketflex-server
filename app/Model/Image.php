<?php
class Image extends AppModel {
	public $hasMany = array('Event');
	
	/** 
	 * Every time an image record is returned, add the url to this image for easy access.
	 * The url to any image record is /Images/image/<imageID>
	 */
	public function afterFind($results, $primary=false){
	//var_dump($results);
		foreach ($results as $i=>$record){
			//unset($results[$i]['Image']['id']);
			if (!empty($results[$i]['Image']) && is_array($results[$i]['Image'])){
				$results[$i]['Image']['url'] = Router::url(array('controller'=>'Images','action'=>'image',$results[$i]['Image']['id']), true);
			}
		}
		if (!empty($results['id']))
			$results['url'] = Router::url(array('controller'=>'Images','action'=>'image',$results['id']), true);
		return $results;
	}
}