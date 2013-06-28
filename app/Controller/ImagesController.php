<?php
App::import('Controller','JSON');
class ImagesController extends JSONController {
	/**
	 * Respond with the image given by $imageID.
	 * Currently, the images table is only used for event images. Find the $imageID using an endpoint that responds with
	 * an event record(s).
	 */
	public function image($imageID){
		$image = $this->Image->find('first',
									array(	'conditions'=>array('Image.id'=>$imageID),
											'fields'=>array('Image.image','Image.mime')));
		if ($image){
			$imageRaw = $image['Image']['image'];
			if (empty($imageRaw)){
				$this->setErrorJson(array(
					'message'=>"Image with id $imageID is not a valid image.",
					'friendly_message'=>"This is not a valid image.",
				));
			}
			else {
				$mime = $image['Image']['mime'];
				$validMimes = array('image/jpeg','image/png','image/gif');
				// If the image has a valid mime type, go ahead and respond with it.
				if (in_array($mime, $validMimes, true)){
					
					if (!empty($_GET['width']))			$width = (int)$_GET['width'];
					if (!empty($_GET['height']))		$height = (int)$_GET['height'];
					
					// If width and/or height was provided as GET parameters, crop and resize the image.
					// If only one of the width and height parameters is provided, the aspect ratio of the original image
					// will be maintained (no cropping required), and the image will simply be resized to match the width
					// OR height requested.
					if ((!empty($width) && $width >0) || (!empty($height) && $height > 0)){
						$imageObj = imagecreatefromstring($imageRaw);
						if (!$imageObj){
							exit;
						}
						if (empty($width) && empty($height)){
							$width = imagesx($imageObj);
							$height = imagesy($imageObj);
						}
						else if (empty($width)) {
							$width = imagesx($imageObj)*((float)$height/imagesy($imageObj));
						}
						else if (empty($height)) {
							$height = imagesy($imageObj)*((float)$width/imagesx($imageObj));
						}

						// First, we will take the largest center crop of the image with the aspect ratio given by width/height.
						$aspectRatio = ((float)$width)/((float)$height);
						$croppedLengthX = min(imagesx($imageObj), (float)imagesy($imageObj)*$aspectRatio);
						$croppedLengthY = (int)((float)$croppedLengthX/(float)$aspectRatio);
						$croppedImageObj = imagecreatetruecolor($croppedLengthX, $croppedLengthY);
						$src_x = (imagesx($imageObj) - $croppedLengthX)/2.0;
						$src_y = (imagesy($imageObj) - $croppedLengthY)/2.0;
						imagecopy($croppedImageObj, $imageObj, 0, 0, $src_x, $src_y, $croppedLengthX, $croppedLengthY);

						// Now that we cropped the image with the correct aspect ratio, we can simply resize it to
						// the width and height that the user provided.
						$scaledImageObj = imagecreatetruecolor($width, $height);
						imagecopyresampled($scaledImageObj, $croppedImageObj, 0,0,0,0, $width, $height, $croppedLengthX, $croppedLengthY);
						
						// Output the image.
						header('Content-Type: '.$mime);
						if ($mime == 'image/png')			imagepng($scaledImageObj, null, 9);
						else if ($mime == 'image/jpeg')		imagejpeg($scaledImageObj, null, 90);
						else if ($mime == 'image/gif')		imagegif($scaledImageObj, null);
					}
					// If no cropping/resizing was requested, just output the image.
					else {						
						header('Content-Type: '.$mime);
						echo $imageRaw;
					}
					exit;
				}
				else {
					$this->setErrorJson(array(
						'message'=>"Image with id $imageID has an invalid mime type of $mime. It must be one of: ".
									implode(",", $validMimes),
						'friendly_message'=>"Invalid image."
					));
				}
			}
		}
		else {
			$this->setErrorJson(array(
				'message'=>"Invalid image with id: $imageID",
				'friendly_message'=>"Image not found."
			));
		}
	}
}