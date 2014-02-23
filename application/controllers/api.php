<?php

class Api extends CI_Controller {

	const RESOURCE_FOLDER = "resources/";
	const DEFAULT_RESOURCE = "resources/0/";

	function __construct() {
		parent::__construct();
		date_default_timezone_set('GMT'); //sets proper timezone
		$this->db->query("SET time_zone='+0:00'");
		//echo date( 'Y-m-d H:i:s', time());
		//TODO must set timezone on the server too. mayb we'll set it here?
		//load model and libraries
		//$this->load->library('session');
		$this->load->library('errorCode');
		$this->load->library('util');
		$this->load->model('session');
		//$this->ensureValidSession();
	}

	public function index() {
	}

	public function ensureValidSession() {
		$headers = $this->input->request_headers();

		//for some reason the capitalization of the header keys is all over the place.
		//loop thru just to be safe
		foreach($headers as $key => $value) {
			if(strtolower($key) == 'gjk-token') {
				$session_id = $headers[$key];
				break;
			}
		}

		if(!$this->session->isValidSessionId($session_id)) {
			echo $this->createErrorJSON(errorCode::INVALID_SESSION, $this->errorcode->getErrorMessage(errorcode::INVALID_SESSION));
			exit(1);
		}
	}

	//TODO add a rate limit
	/**
	 * Reads JSON input from post body. Needs to have signature & session token later.
	* The following JSON fields are:
	* miluNumber - user's miluNumber OR email?? : String (required)
	* password - user's password : String (required)
	*
	* returns json string
	*
	* Sample input:
	* {"username": "kach3@gmail.com", "password": "password"}
	*
	*
	* Sample success output:
	* {"user":{"id":"6","email":"kach3@gmail.com","first_name":"Kachi","last_name":"Nwaobasi","image":"resources\/6\/images\/img20140213064951.jpg","bio":"Bio motherfucker"},"configData":[],"chats":[],"success":true}
	*
	* Sample failure output:
	* {"success":false,"errorCode":7,"message":"Invalid username\/password combination"}
	*
	*/
	public function login() {
		//TODO verify token and signature
		$requiredFields = array('email', 'password');
		$json = json_decode(file_get_contents('php://input'), true);
		if($this->areValuesSet($json, $requiredFields)) {
			$this->load->model('User', 'user');
			$user = $this->user->login($json['email'], $json['password']);

			if(!$this->errorcode->isError($user)) {
				if(isset($user['reset_password']) && $user['reset_password'])
					echo $this->createSuccessJSON(array("user"=>$user));
				else
					echo $this->createSuccessJSON(array("user"=>$user, "configData"=>array(), "chats"=>array()));
			} else
				echo $this->createErrorJSON($user, $this->errorcode->getErrorMessage($user));
		} else
			echo $this->createErrorJSON(errorcode::MISSING_DATA, $this->errorcode->getErrorMessage(errorcode::MISSING_DATA));
	}


	/**
	 * Sample input:
	 * {"id":33,"old_password":"password", "new_password":"newpassword"}
	 *
	 *
	 * Sample success output:
	 * {"success":true}
	 *
	 * Sample failure output:
	 * {"success":false,"errorCode":7,"message":"Invalid username\/password combination"}
	 *
	 **/
	public function changePassword() {
		$requiredFields = array('id', 'old_password', 'new_password');
		$json = json_decode(file_get_contents('php://input'), true);
		if($this->areValuesSet($json, $requiredFields)) {
			$this->load->model('Entity', 'entity');
			$success = $this->entity->changePassword($json['id'], $json['old_password'], $json['new_password']);

			if(!$this->errorcode->isError($success)) {
				echo $this->createSuccessJSON(array());
			} else {
				echo $this->createErrorJSON($success, $this->errorcode->getErrorMessage($success));
			}
		} else
			echo $this->createErrorJSON(errorcode::MISSING_DATA, $this->errorcode->getErrorMessage(errorcode::MISSING_DATA));

	}

	/**
	 * Sample input:
	 * {"username":"felixn1227@gmail.com"}
	 *
	 *
	 * Sample success output:
	 * {"success":true}
	 *
	 *
	 **/
	public function forgotPassword() {
		$requiredFields = array('username');
		$json = json_decode(file_get_contents('php://input'), true);
		if($this->areValuesSet($json, $requiredFields)) {
			$this->load->model('Entity', 'entity');
			$result = $this->entity->setTempPassword($json['username']);
			if(!$this->errorcode->isError($result)) {
				$this->load->library('mailer');
				$this->mailer->sendTempPassword($result['email'], $result['temp_password']);
				echo $this->createSuccessJSON(array());
			} else
				echo $this->createErrorJSON($result, $this->errorcode->getErrorMessage($result));
		} else
			echo $this->createErrorJSON(errorcode::MISSING_DATA, $this->errorcode->getErrorMessage(errorcode::MISSING_DATA));
	}
	
	
	/**
	 * 
	 * Resets the user's password. Must provide a temporary password with this call. This will force the user to log in again
	 * 
	 * Sample input:
	 * {"username":"10000","temp_password":"password", "password":"newpassword"}
	 *
	 *
	 * Sample success output:
	 * {"success":true}
	 *
	 * Sample failure output:
	 * {"success":false,"errorCode":7,"message":"Invalid username\/password combination"}
	 *
	 **/
	public function resetPassword() {
		$requiredFields = array('username', 'temp_password', 'password');
		$json = json_decode(file_get_contents('php://input'), true);
		if($this->areValuesSet($json, $requiredFields)) {
			$this->load->model('Entity', 'entity');
			$success = $this->entity->resetPassword($json['username'], $json['temp_password'], $json['password']);
		
			if(!$this->errorcode->isError($success)) {
				$user = $this->entity->login($json['username'], $json['password']);
				if(!$this->errorcode->isError($user)) {
					$this->load->model('Photo', 'photo');
					$user['image'] = '';//$this->util->getDefaultImage($user['broad_user_type']);
					if($profile_image = $this->photo->getProfilePicture($user['id']))
						$user['image'] = $profile_image;
					echo $this->createSuccessJSON(array("entity"=>$user, "configData"=>array("actions"=>$this->createActionsJSON($user['entity_type']), "datatypes"=>$this->getAllDatatypes(), "moods"=>$this->getAllMoods(), "rsvps"=>$this->getAllRsvps())));
				} else
					echo $this->createErrorJSON($user, $this->errorcode->getErrorMessage($user));
			} else {
				echo $this->createErrorJSON($success, $this->errorcode->getErrorMessage($success));
			}
		} else
			echo $this->createErrorJSON(errorcode::MISSING_DATA, $this->errorcode->getErrorMessage(errorcode::MISSING_DATA));
		
	}

	/**
	  * Sample input:
	  * {"email": "kach3@gmail.com", "password": "password", "first_name": "Kachi", "last_name": "Nwaobasi", "bio" : "Bio doggie"}
	  *
	  * Sample success output:
	  * {"user":{"id":"6","email":"kach3@gmail.com","first_name":"Kachi","last_name":"Nwaobasi","bio":"Bio doggie","image":"resources\/6\/images\/img20140213064951.jpg"},"configData":[],"chats":[],"success":true}
	  *
	  * Sample failure output:
	  * {"success":false,"errorCode":3,"message":"The email provided is already in use"}
	  */
	public function register() {		
		$requiredFields = array('email', 'password', 'first_name', 'last_name');
		$json = json_decode($this->input->post('string'), true);
		
		if($this->areValuesSet($json, $requiredFields)) {
			$this->db->trans_start();
			$this->load->model('User', 'user');
			$user = $this->user->register($json, $tableType);
			$profile_image = '';
			if(!$this->errorcode->isError($user)) {
				$user['image'] = '';

				if(count($_FILES) > 0) {
					$this->load->library('upload');
					$this->load->library('util');
					$dirName = self::RESOURCE_FOLDER . $user['id'] . "/images" . "/";
					if(!file_exists($dirName))
						mkdir($dirName , 0755, true);

					if(isset($_FILES['image'])) {
						$filePath = pathinfo($_FILES['image']['name']);

						$config = array('file_name'=>"img" . date('YmdHis', time()) . "." . $filePath['extension'], 'allowed_types' => "*", 'upload_path' => $dirName,
								'max_size' => '0', 'overwrite' => FALSE);
						$this->upload->initialize($config);
						if($this->upload->do_upload("image")) {
							$filename = $config['upload_path'] . $config['file_name'];
							if($this->user->updatePhoto($user['id'], $filename))
								$user['image'] = $filename;
							else {
								//we couldn't load the file to the database, so delete it and put error message
								unlink($filename);
								$user['message'] = $this->errorcode->getErrorMessage(errorcode::PHOTO_UPLOAD); //could not uplaod photo
							}
						} else
							$user['message'] = $this->errorcode->getErrorMessage(errorcode::PHOTO_UPLOAD); //could not uplaod photo
					}
				}

				$this->db->trans_complete();
				if ($this->db->trans_status() === FALSE)
					echo $this->createErrorJSON(errorcode::REGISTRATION_FAILED, $this->errorcode->getErrorMessage(errorCode::REGISTRATION_FAILED));
				else
					echo $this->createSuccessJSON(array("user"=>$user, "configData"=>array(), "chats"=>array()));
			} else {
				echo $this->createErrorJSON($user, $this->errorcode->getErrorMessage($user));
				$this->db->trans_complete();
			}
		}
		else
			echo $this->createErrorJSON(errorcode::MISSING_DATA, $this->errorcode->getErrorMessage(errorcode::MISSING_DATA));
			
	}

	/*
	 * Sample input:
	*
	* {"tags":{"social_interest":{"added":[{"tag_id":12}],"removed":[{"tag_id":20}]}},"id":25,"first_name":"Fred","birthday":691304400363,"lon":-74.005419,"occupation":"Mobile Developer","address":"400 W 14th St, New York, NY 10014, USA","last_name":"Johnson","about":"Just trying to change the world.","gender":"F","entity_type":1,"lat":40.741022}
	*
	* Sample output:
	* {"updated_info":{"id":"25","phone_number":"61788810393","log_location":"1"},"success":true}
	*/
	public function editProfile() {
		$json = json_decode($this->input->post('string'), true);
		$requiredFields = array('id', 'entity_type');
		if($this->areValuesSet($json, $requiredFields)) {
			$this->db->trans_start(); //start editing

			$this->load->model('Photo', 'photo');
			//modify photos if necessary
			if(count($_FILES) > 0) {
				$this->load->library('upload');
				$this->load->library('util');
				$dirName = self::RESOURCE_FOLDER . $json['id'] . "/images" . "/";
				if(!file_exists($dirName))
					mkdir($dirName , 0755, true);
				$filePath = pathinfo($_FILES['file_0']['name']);
					
				$config = array('file_name'=>"img" . date('YmdHis', time()) . "." . $filePath['extension'], 'allowed_types' => "*", 'upload_path' => $dirName,
						'max_size' => '0', 'overwrite' => FALSE);
				$this->upload->initialize($config);
				if($this->upload->do_upload("file_0")) {
					$filename = $config['upload_path'] . $config['file_name'];
					$size = getimagesize($filename);
					$photoData = array('owner_id'=>$json['id'], 'image'=>$filename, 'is_profile'=>true, 'width'=>$size[0], 'height'=>$size[1]);
					$this->photo->removeProfilePicture($json['id']);
					$this->photo->addPhoto('photo_entity', $photoData);
				}
					
			} else if(array_key_exists('photo', $json) && !isset($json['photo'])) {
				$this->photo->removeProfilePicture($json['id']);
				unset($json['photo']);
			}

			//actually modify the entity;
			$this->load->model('Entity', 'entity');
			$this->load->model('Usertype', 'userType');
			$tableType = $this->userType->getBroadUserTypeIdById($json['entity_type']);
			$modelName = $this->util->getModelName($tableType);
			$this->load->model($modelName, 'info');



			$entity = $this->info->editInfo($json);

			$this->db->trans_complete();
			if ($this->db->trans_status() === FALSE) {
				if(isset($filename))
					unlink($filename);
				echo $this->createErrorJSON(errorcode::UPDATE_FAILED, $this->errorcode->getErrorMessage(errorcode::UPDATE_FAILED));
			}
			else
				echo $this->createSuccessJSON(array("user_profile"=>$entity));

		} else
			echo $this->createErrorJSON(errorcode::MISSING_DATA, $this->errorcode->getErrorMessage(errorcode::MISSING_DATA));

	}

	public function editAccount() {
		$json = json_decode(file_get_contents('php://input'), true);
		$requiredFields = array('id', 'entity_type');
		if($this->areValuesSet($json, $requiredFields)) {
			$this->db->trans_start();

			$this->load->model('Usertype', 'userType');
			$tableType = $this->userType->getBroadUserTypeIdById($json['entity_type']);
			$modelName = $this->util->getModelName($tableType);
			$this->load->model($modelName, 'info');

			$entity = $this->info->editAccount($json);

			$this->db->trans_complete();

			if($this->db->trans_status() === FALSE)
				echo $this->createErrorJSON(errorcode::UPDATE_FAILED, $this->errorcode->getErrorMessage(errorcode::UPDATE_FAILED));
			else
				echo $this->createSuccessJSON(array("entity"=>$entity));
		} else
			echo $this->createErrorJSON(errorcode::MISSING_DATA, $this->errorcode->getErrorMessage(errorcode::MISSING_DATA));
	}

	/**
	 * Sample input:
	 * {"id":24, "lat" : 70.32, "lon": 80.64, "source": "CELL"}
	 *
	 * Sample outputs:
	 * {"location_info":{"checkin_list":[{"id":"28","type":"EVENT","type_id":"2","name":"House Warming","lat":"40.8187607","lon":"-73.9401166","image":"resources\/events\/event28\/img20120921223307.jpg","distance":"233.0390931217229"}],"location":{"id":"24","lat":"40.819355010986","lon":"-73.937461853027"}},"success":true}
	 *
	 * {"location_info":{"checked_into":{"place_id":"28","type":"EVENT","place_type":"2","name":"House Warming","lat":"40.8187607","lon":"-73.9401166","image":"resources\/events\/event28\/img20120921223307.jpg","distance":"233.0390931217229","count":"10","last_checkin_time":"2012-09-24 01:23:06"},"location":{"id":"24","lat":"40.819355010986","lon":"-73.937461853027"}},"success":true}
	 *
	 */
	public function updateLocation() {

		//$this->util->getHaversineSQLString(40.818923950195, -73.940849304199, "lat", "lon", "m");
		//$this->util->getHaversineSQLString(40.819355010986, -73.937461853027, "lat", "lon", "m");
		$json = json_decode(file_get_contents('php://input'), true);
		$requiredFields = array('id', 'lat', 'lon', 'source');
		if($this->areValuesSet($json, $requiredFields)) {
			$this->load->model('Location', 'loc');

			echo $this->createSuccessJSON(array("location_info"=>($currLoc = $this->loc->addLocationIfNeccessary($json)) ? $currLoc: NULL));
		} else
			echo $this->createErrorJSON(errorcode::MISSING_DATA, $this->errorcode->getErrorMessage(errorcode::MISSING_DATA));
	}

	public function createActionsJSON($specific_user_type) {
		$this->load->model('user_action', 'ua');
		$sqlActions = $this->ua->getUserActions($specific_user_type);
		$userActions = array();
		if($sqlActions) {
			foreach($sqlActions as $action) {
				$userActions[] = $action['datatype'];
				// 				if(isset($userActions[$action['to_entity']])) {
				// 					if($prevDataType == $action['datatype'] && $prevToEntity == $action['to_entity'])
				// 					$userActions[$action['to_entity']][(count($userActions[$action['to_entity']]) - 1)]['transfer_type'][] = $action['transfer_type'];
				// 					else
				// 					$userActions[$action['to_entity']][] = array('datatype'=>$action['datatype'], 'transfer_type'=>array($action['transfer_type']));
				// 				} else
				// 				$userActions[$action['to_entity']] = array(array('datatype'=>$action['datatype'], 'transfer_type'=>array($action['transfer_type'])));

				// 				$prevDataType = $action['datatype'];
				// 				$prevToEntity = $action['to_entity'];
			}
		}
		return $userActions;
	}

	public function getAllMoods() {
		$this->load->model('mood', 'mood');
		return $this->mood->getAllMoods();
	}

	public function getAllDataTypes() {
		$this->load->model('datatype');
		return $this->datatype->getAllDatatypes();
	}

	public function getAllRsvps() {
		$this->load->model('rsvp_state');
		return $this->rsvp_state->getAllStates();
	}

	/*
	 *Sample input:
	*{"milu_number": 10000}
	*
	* Sample output:
	* {"contact":{"first_name":"Felix","last_name":"Nwaobasi","birthday":"1988-12-27 10:00:00","lat":null,"lon":null,"image":"resources\/10000\/images\/img20111213083907.png"},"success":true}
	*/
	public function userExists() {
		$json = json_decode(file_get_contents('php://input'), true);
		$requiredFields = array('milu_number');
		if($this->areValuesSet($json, $requiredFields)) {
			$this->load->model('Entity', 'entity');
			echo (($potentialContact = $this->entity->getEntityByMiluNumber($json['milu_number']))) ? $this->createSuccessJSON(array("contact"=>$potentialContact)) : $this->createSuccessJSON(array("contact"=>NULL));
		} else
			echo $this->createErrorJSON(errorcode::MISSING_DATA, $this->errorcode->getErrorMessage(errorcode::MISSING_DATA));
	}

	public function verifyNumber() {
		//TODO complete implementing
		$json = json_decode(file_get_contents('php://input'), true);
		$requiredFields = array('id', 'milu_number');
		if($this->areValuesSet($json, $requiredFields)) {
			$this->load->model('Entity', 'entity');
			echo ($this->createSuccessJSON(array("match?"=>$this->entity->verifyWithMiluNumber($json))));
		} else
			echo $this->createErrorJSON(errorcode::MISSING_DATA, $this->errorcode->getErrorMessage(errorcode::MISSING_DATA));

	}

	/*if milu number is included, assume its personal
	 *if not assume its impersonal
	*if we get here, then assume that milu numbers match
	*if number included, create PENDING(only for personal)
	*if they dont, create an impersonal friendship (no pending, automatic)
	*if the user is not just an average person*/
	public function createRelationship() {
		$json = json_decode(file_get_contents('php://input'), true);
		$requiredFields = array('id', 'their_id');
		if($this->areValuesSet($json, $requiredFields)) {
			$this->load->model('Relationship_general', 'rel');
			if(!$this->errorcode->isError($couldCreate = $this->rel->createRelationship($json)))
				echo $this->createSuccessJSON(array());
			else
				echo $this->createErrorJSON($couldCreate, $this->errorcode->getErrorMessage($couldCreate));
		}
		else
			echo $this->createErrorJSON(errorcode::MISSING_DATA, $this->errorcode->getErrorMessage(errorcode::MISSING_DATA));
	}

	//returns true
	public function confirmRelationship() {
		$json = json_decode(file_get_contents('php://input'), true);
		$requiredFields = array('id', 'their_id', 'response');
		if($this->areValuesSet($json, $requiredFields)) {
			$this->load->model('Relationship_general', 'rel');
			if(!$this->errorcode->isError($couldConfirm = $this->rel->confirmRelationship($json)))
				echo $this->createSuccessJSON(array());
			else
				echo $this->createErrorJSON($couldCreate, $this->errorcode->getErrorMessage($couldCreate));
		}
		else
			echo $this->createErrorJSON(errorcode::MISSING_DATA, $this->errorcode->getErrorMessage(errorcode::MISSING_DATA));
	}

	/*
	 * Retrieves all the contacts for a given user
	*
	* Sample input:
	* {"id": 24, "entity_type": 1}
	*
	* Sample output:
	* {"contacts":[{"rank":"1","first_name":"Felix","last_name":"Nwaobasi","image":"resources\/0\/images\/user_image.png","alias":"PENDING","root":"1"},{"rank":"1","first_name":"Yetti","last_name":"Ajayi-Obe","image":"resources\/10008\/images\/img20111220220623.png","alias":"PENDING","root":"1"},{"rank":"2","first_name":"Charles","last_name":"Curry","image":"resources\/0\/images\/user_image.png","alias":"PERSONAL","root":"0"},{"rank":"2","first_name":"John","last_name":"Doe","image":"resources\/0\/images\/user_image.png","alias":"PERSONAL","root":"1"},{"rank":"2","first_name":"Tester","last_name":"Testing","image":"resources\/0\/images\/user_image.png","alias":"IMPERSONAL","root":"0"}],"success":true}
	*
	*/
	public function getContacts() {
		$json = json_decode(file_get_contents('php://input'), true);
		$requiredFields = array('id');
		if($this->areValuesSet($json, $requiredFields)) {
			$this->load->model('Relationship_general', 'rel');
			$contactArray = $this->rel->getContacts($json['id'], isset($json['broad_user_type']) ? $json['broad_user_type'] : null,
					isset($json['my_state']) ? $json['my_state'] : 15, isset($json['their_state']) ? $json['their_state'] : 11, isset($json['separate_pending']) ? $json['separate_pending'] : true);
			echo $this->createSuccessJSON(array("contacts"=>$contactArray));
		} else
			echo $this->createErrorJSON(errorcode::MISSING_DATA, $this->errorcode->getErrorMessage(errorcode::MISSING_DATA));
	}



	public function searchEntities() {
		$json = json_decode(file_get_contents('php://input'), true);
		$requiredFields = array('id', 'query', 'type');
		if($this->areValuesSet($json, $requiredFields)) {
			$this->load->model('Entity', 'entity');
			$offset = isset($json['offset']) ? $json['offset'] : 0;
			$limit = isset($json['limit']) ? $json['limit'] : 20;
			$searchArray = $this->entity->queryEntities($json['id'], $json['query'], $json['type'], $offset, $limit);
			echo $this->createSuccessJSON(array("results"=>$searchArray));
		} else
			echo $this->createErrorJSON(errorcode::MISSING_DATA, $this->errorcode->getErrorMessage(errorcode::MISSING_DATA));
	}

	//very similar to the function above except searchVenues searches only for businesses that match the name and the address!
	//did not want to include it in the above function and make it messy
	public function searchVenues() {
		$json = json_decode(file_get_contents('php://input'), true);
		$requiredFields = array('id', 'query');
		if($this->areValuesSet($json, $requiredFields)) {
			$this->load->model('Business_info', 'business');
			$offset = isset($json['offset']) ? $json['offset'] : 0;
			$limit = isset($json['limit']) ? $json['limit'] : 20;
			$searchArray = $this->business->searchBasicInfo($json['id'], $json['query'], $offset, $limit, true);
			echo $this->createSuccessJSON(array("results"=>$searchArray));
		} else
			echo $this->createErrorJSON(errorcode::MISSING_DATA, $this->errorcode->getErrorMessage(errorcode::MISSING_DATA));
	}

	public function searchContacts() {
		$json = json_decode(file_get_contents('php://input'), true);
		$requiredFields = array('id', 'query');
		if($this->areValuesSet($json, $requiredFields)) {
			$this->load->model('Entity', 'entity');

			$searchArray = $this->entity->queryContacts($json['id'], $json['query'], isset($json['filter']) ?  $json['filter'] : null);
			echo $this->createSuccessJSON(array("results"=>$searchArray));
		} else
			echo $this->createErrorJSON(errorcode::MISSING_DATA, $this->errorcode->getErrorMessage(errorcode::MISSING_DATA));
	}

	/**
	 *
	 * Sample input:
	 * {"id": 24, "recipient_id": 24, "type":"accept"}
	 *
	 *
	 */
	public function notifyContact() {
		$json = json_decode(file_get_contents('php://input'), true);
		$requiredFields = array('id', 'recipient_id', 'type');
		if($this->areValuesSet($json, $requiredFields) ) {
			$this->load->model('Entity', 'entity');

			if(count($participants = $this->entity->getEntitiesFromIds(array($json['recipient_id']), true)) > 0)	 {
				//var_dump($participants);
				$this->load->library('pushmanager');
				$entityPartition = $this->pushmanager->partitionPhoneUsers($participants);

				$entityOfInterestArray = $this->entity->getEntitiesFromIds(array($json['id']), false);

				$contentArray = array();
				$contentArray['type'] = $json['type'];
				$contentArray['entity'] = $entityOfInterestArray[0];

				$this->pushmanager->sendMessage(array(constants::PARAM_COLLAPSE_KEY=>pushmanager::KEY_CONTACT, constants::PARAM_DATA=>array(pushmanager::MSG_TYPE=>pushmanager::KEY_CONTACT, pushmanager::MSG_CONTENT=>json_encode($contentArray)), constants::PARAM_REGISTRATION_IDS=>$entityPartition["ANDROID"]), "");
			}

			echo $this->createSuccessJSON(array());
		} else
			echo $this->createErrorJSON(errorcode::MISSING_DATA, $this->errorcode->getErrorMessage(errorcode::MISSING_DATA));
	}


	/*
	 *
	* Sample input:
	*
	* {"id":24,"max_lat":"86.28387","zoom":"4","max_lon":"-57.44027","entity_type":1,"filter":"all","min_lon":"-141.81528","min_lat":"9.654747"}
	*
	* Sample output:
	*
	* {"nodes":[{"type":"cluster","node":{"ids":["44","47","45","24","43","42"],"persons":2,"businesses":4,"organizations":0,"lat":41.444713396427,"lon":-72.838429810775}},{"type":"single","node":{"id":"39","milu_number":"10009","name":"Willard","lat":"34.0522222","lon":"-118.2427778","image":"resources\/10009\/images\/img20120131050234.png","entity_type":"2","broad_type":"1"}},{"type":"single","node":{"id":"41","milu_number":"10011","name":"Barack","lat":"38.8951118","lon":"-77.0363658","image":"resources\/10011\/images\/img20120131053719.png","entity_type":"3","broad_type":"1"}}],"success":true}
	*
	*/
	public function getNodes() {
		$json = json_decode(file_get_contents('php://input'), true);
		$requiredFields = array('id', 'entity_type', 'min_lat', 'min_lon', 'max_lat', 'max_lon', 'zoom', 'filter');
		if($this->areValuesSet($json, $requiredFields)) {
			$this->load->model('Relationship_general', 'rel');
			$nodes = $this->rel->getEntitiesOnMap($json);
			echo $this->createSuccessJSON(array("nodes"=>$nodes));
		} else
			echo $this->createErrorJSON(errorcode::MISSING_DATA, $this->errorcode->getErrorMessage(errorcode::MISSING_DATA));
	}

	/**
	 * Sample input:
	 * 
	 * {"id": 24, "datatype": "CONTENT", "is_public": true, "content": "This is a test message", "recipients":[42, 39, 41], "description_values": "blah blah"}
	 * 
	 */
	public function sendMessage() {
			
		$json = json_decode($this->input->post('string'), true);
		//TODO incorporate mood id somewhere in here
		$requiredFields = array('id', 'datatype', 'is_public');

		$this->load->library('upload');  // NOTE: always load the library outside the loop

		$this->load->model('Message', 'mess');
		if($this->areValuesSet($json, $requiredFields)) {
			$filenames = array();

			for($i=0; $i<count($_FILES); $i++) {


				$dir = self::RESOURCE_FOLDER . $json['id'] . "/" .  strtolower(message::getTableFromDatatypeAlias($json['datatype'])) . "/";
				$filePath = pathinfo($_FILES['file_' . $i]['name']);
				if(!file_exists($dir))
					mkdir($dir, 0755, true);
				$config = array('file_name'=>"img" . date('YmdHis', time()) . "." . $filePath['extension'], 'allowed_types' => "*", 'upload_path' => $dir,
						'max_size' => '0', 'overwrite' => FALSE);

				$this->upload->initialize($config);
				if($this->upload->do_upload("file_" . $i)) {
					//TODO UH! do something when files fail. for all file uploading. register, event creation, live stream, and message sending
					$filenames[$i] = $config['upload_path'] . $config['file_name'];
				} else {
					echo $this->createErrorJSON(errorcode::FILE_NOT_UPLOADED, $this->errorcode->getErrorMessage(errorcode::FILE_NOT_UPLOADED));
					return;
				}
			}

			$this->db->trans_start();

			if(count($filenames) > 0)
				$json['attachments'] = $filenames;

			$interactionInfo = $this->mess->distributeMessage($json);
			$interactionInfo['attachments'] = $filenames;
			$this->db->trans_complete();
			//technically the first check isn't even needed, since if successCode = error, then that means
			//transaction failed. Just put it there to be safe for now.
			if ($this->errorcode->isError($interactionInfo) || $this->db->trans_status() === FALSE)
				echo $this->createErrorJSON(errorcode::MESSAGE_FAILED, $this->errorcode->getErrorMessage(errorCode::MESSAGE_FAILED));
			else
				echo $this->createSuccessJSON(array("interaction_info"=>$interactionInfo));
		} else
			echo $this->createErrorJSON(errorcode::MISSING_DATA, $this->errorcode->getErrorMessage(errorcode::MISSING_DATA));
			
	}
	/**
	 * Sample input:
	 * 
	 * {"id":24,"message_id":18,"updates":{"shopping_list_updates":{"pickup_time":1391101200000},"added":[{"num_available":5,"num_requested":5,"price":0,"order":4,"preference":"BRAND","item":"Condoms"},{"num_available":1,"num_requested":1,"price":0,"order":5,"preference":"BRAND","item":"prego sauce"}],"deleted":[3],"message_updates":{"description_values":"{\"items\":\"Brown Cinnamon Pop Tarts, Milk, Progresso Chicken Soup, Condoms, prego sauce\",\"pickup_time\":1391101200000}"},"modified":[{"num_available":1,"id":1,"num_requested":1,"price":0,"order":1,"preference":"PRICE","item":"Brown Cinnamon Pop Tarts"},{"num_available":1,"id":2,"num_requested":1,"price":0,"order":2,"preference":"PRICE","item":"Milk"},{"num_available":1,"id":4,"num_requested":2,"price":0,"order":3,"preference":"BRAND","item":"Progresso Chicken Soup"}]},"datatype":"SHOPPING_LIST"}
	 */
	public function editMessage() {	
		$json = json_decode($this->input->post('string'), true);
		$requiredFields = array('id', 'message_id', 'datatype', 'updates');
		

		$this->load->library('upload');  // NOTE: always load the library outside the loop
		
		$this->load->model('Message', 'mess');
		if($this->areValuesSet($json, $requiredFields)) {
			$filenames = array();
		
			for($i=0; $i<count($_FILES); $i++) {
		
		
				$dir = self::RESOURCE_FOLDER . $json['id'] . "/" .  strtolower(message::getTableFromDatatypeAlias($json['datatype'])) . "/";
				$filePath = pathinfo($_FILES['file_' . $i]['name']);
				if(!file_exists($dir))
					mkdir($dir, 0755, true);
				$config = array('file_name'=>"img" . date('YmdHis', time()) . "." . $filePath['extension'], 'allowed_types' => "*", 'upload_path' => $dir,
						'max_size' => '0', 'overwrite' => FALSE);
		
				$this->upload->initialize($config);
				if($this->upload->do_upload("file_" . $i)) {
					//TODO UH! do something when files fail. for all file uploading. register, event creation, live stream, and message sending
					$filenames[$i] = $config['upload_path'] . $config['file_name'];
				} else {
					echo $this->createErrorJSON(errorcode::FILE_NOT_UPLOADED, $this->errorcode->getErrorMessage(errorcode::FILE_NOT_UPLOADED));
					return;
				}
			}
		
			$this->db->trans_start();
		
			if(count($filenames) > 0)
				$json['attachments'] = $filenames;
		
			$interactionInfo = $this->mess->editMessage($json);
			$this->db->trans_complete();
			//technically the first check isn't even needed, since if successCode = error, then that means
			//transaction failed. Just put it there to be safe for now.
			if ($this->errorcode->isError($interactionInfo) || $this->db->trans_status() === FALSE)
				echo $this->createErrorJSON(errorcode::UPDATE_MESSAGE_FAILED, $this->errorcode->getErrorMessage(errorCode::UPDATE_MESSAGE_FAILED));
			else
				echo $this->createSuccessJSON(array("interaction_info"=>$interactionInfo));
		} else
			echo $this->createErrorJSON(errorcode::MISSING_DATA, $this->errorcode->getErrorMessage(errorcode::MISSING_DATA));
		
	}

	/**
	 *
	 * Sample input:
	 * {"id":24}
	 *
	 * Sample output:
	 * {"interactions":[{"name":"Charles  Curry","image":"","sender_id":"47","entity_type":"1","datatype":"CONTENT","is_public":"1","description_values":"","moment_flag":"0","date":"1333288020000","new":"0"},{"name":"CNN","image":"resources\/10015\/images\/img20120201044851.png","sender_id":"45","entity_type":"4","datatype":"JOB_POSTING","is_public":"1","description_values":"{\"position\":\"Hotshot Android Developer\"}","moment_flag":"0","date":"1351541583000","new":"1"},{"name":"Walmart","image":"resources\/10012\/images\/img20120201034447.png","sender_id":"42","entity_type":"4","datatype":"FLYER","is_public":"1","description_values":"{\"location\":\"\",\"title\":\"Trap or DIE!!\"}","moment_flag":"0","date":"1351537558000","new":"4"}],"success":true}
	 */
	public function getInteractions() {
		$json = json_decode(file_get_contents('php://input'), true);
		$requiredFields = array('id');
		if($this->areValuesSet($json, $requiredFields)) {
			$this->load->model('Message', 'mess');

			$this->db->trans_start();
			$interactions = $this->mess->getInteractions($json['id']);
			$this->db->trans_complete();

			if ($this->db->trans_status() === TRUE)
				echo $this->createSuccessJSON(array("interactions"=>$interactions));
			else
				echo $this->createErrorJSON(errorcode::INTERACTION_NOT_RECEIVED, $this->errorcode->getErrorMessage(errorcode::INTERACTION_NOT_RECEIVED));

		} else
			echo $this->createErrorJSON(errorcode::MISSING_DATA, $this->errorcode->getErrorMessage(errorcode::MISSING_DATA));
	}


	/**
	 *
	 * Sample input:
	 * {"id":24, "their_id": 47}
	 *
	 * Sample output:
	 * {"interactions":[{"id":"21","sender_id":"24","sender_name":"Felix Nwaobasi","sender_entity_type":"1","recipient_id":"47","recipient_name":"Charles  Curry","datatype":"CONTENT","is_public":"1","moment_flag":"0","description_values":"{\"content\":\"This is a message for [Charles  Curry]. Dope\"}","date":"1351534324000","table_id":"25","sender_image":"resources\/10000\/images\/img20111213083907.png"},{"id":"12","sender_id":"47","sender_name":"Charles  Curry","sender_entity_type":"1","recipient_id":"24","recipient_name":"Felix Nwaobasi","datatype":"CONTENT","is_public":"1","moment_flag":"0","description_values":"","date":"1333288020000","table_id":"11","sender_image":""},{"id":"13","sender_id":"47","sender_name":"Charles  Curry","sender_entity_type":"1","recipient_id":"24","recipient_name":"Felix Nwaobasi","datatype":"CONTENT","is_public":"1","moment_flag":"0","description_values":"","date":"1333288020000","table_id":"12","sender_image":""},{"id":"4","sender_id":"24","sender_name":"Felix Nwaobasi","sender_entity_type":"1","recipient_id":"47","recipient_name":"Charles  Curry","datatype":"CONTENT","is_public":"1","moment_flag":"0","description_values":"","date":"1333057054000","table_id":"3","sender_image":"resources\/10000\/images\/img20111213083907.png"}],"success":true}
	 */
	public function getInteractionsWithUser() {
		$json = json_decode(file_get_contents('php://input'), true);
		$requiredFields = array('id', 'their_id');
		if($this->areValuesSet($json, $requiredFields)) {
			$this->load->model('Message', 'mess');
			echo $this->createSuccessJSON(array("interactions"=>$this->mess->getInteractionsWithUser($json['id'], $json['their_id'], isset($json['last_message_id']) ? $json['last_message_id'] : -1)));
		} else
			echo $this->createErrorJSON(errorcode::MISSING_DATA, $this->errorcode->getErrorMessage(errorcode::MISSING_DATA));
	}


	/**
	 *
	 * Sample input:
	 * {"message_id" : 21, "datatype": "CONTENT", "last_message_id": 20}
	 *
	 * last_message_id is only used for CONTENT threads
	 *
	 * Sample output:
	 * {"interaction":{"items":[{"sender_id":"24","sender_name":"Felix Nwaobasi","entity_type":"1","root_message_id":null,"date":"1351534324000","content":"This is a message for [Charles  Curry]. Dope","mood_id":"1","image":"resources\/10000\/images\/img20111213083907.png"}]},"success":true}
	 *
	 */
	public function getSingleInteraction() {
		$json = json_decode(file_get_contents('php://input'), true);
		$requiredFields = array('message_id', 'datatype');
		if($this->areValuesSet($json, $requiredFields)) {
			$this->load->model('Message', 'mess');
			echo $this->createSuccessJSON(array("interaction"=>$this->mess->getInteractionItem($json['message_id'], $json['datatype'], isset($json['last_message_id']) ? $json['last_message_id'] : -1)));
		} else
			echo $this->createErrorJSON(errorcode::MISSING_DATA, $this->errorcode->getErrorMessage(errorcode::MISSING_DATA));
	}

	/**
	 *
	 * Sample input:
	 * {"id":24, "their_id": 65, "message_id" : 406, "datatype": "CONTENT"}
	 *
	 * last_message_id is only used for CONTENT threads
	 *
	 * Sample output:
	 * {"interaction":{"items":[{"sender_id":"24","sender_name":"Felix Nwaobasi","entity_type":"1","root_message_id":null,"date":"1351534324000","content":"This is a message for [Charles  Curry]. Dope","mood_id":"1","image":"resources\/10000\/images\/img20111213083907.png"}]},"success":true}
	 *
	 */
	public function getCompleteInteraction() {
		$json = json_decode(file_get_contents('php://input'), true);
		$requiredFields = array('id', 'their_id', 'message_id', 'datatype');
		if($this->areValuesSet($json, $requiredFields)) {
			$this->load->model('Message', 'mess');
			echo $this->createSuccessJSON(array("complete_interaction"=>$this->mess->getCompleteInteractionWithUser($json['id'], $json['their_id'], 
					$json['message_id'], $json['datatype'], isset($json['last_message_id']) ? $json['last_message_id'] : -1)));
		} else
			echo $this->createErrorJSON(errorcode::MISSING_DATA, $this->errorcode->getErrorMessage(errorcode::MISSING_DATA));		
	}
	
	public function getMomentStatusForInteraction() {
		$json = json_decode(file_get_contents('php://input'), true);
		$requiredFields = array('id', 'message_id');
		if($this->areValuesSet($json, $requiredFields)) {
			$this->load->model('Moment', 'moment');
			echo $this->createSuccessJSON(array("moment_status"=>$this->moment->getMomentFlagForInteraction($json['id'], $json['message_id'])));
		} else
			echo $this->createErrorJSON(errorcode::MISSING_DATA, $this->errorcode->getErrorMessage(errorcode::MISSING_DATA));
	}

	/**
	 *
	 * Sample input:
	 * {"name": "Felix Nwaobasi", "recipients":[43, 24, 47, 44, 26, 41]}
	 */
	public function notifyInteractionRecipients() {
		$json = json_decode(file_get_contents('php://input'), true);
		$requiredFields = array('interaction', 'recipients');
		if($this->areValuesSet($json, $requiredFields) ) {
			$this->load->model('Entity', 'entity');

			if(count($participants = $this->entity->getEntitiesFromIds($json['recipients'], true)) > 0)	 {

				$this->load->library('pushmanager');
				$entityPartition = $this->pushmanager->partitionPhoneUsers($participants);
				
				$key = isset($json['editor_info']) ? pushmanager::KEY_EDITED_INTERACTION : pushmanager::KEY_INTERACTION;
				$content = isset($json['editor_info']) ? array("interaction"=>$json['interaction'], "editor_info"=>$json['editor_info']) : $json['interaction'];
				
				$this->pushmanager->sendMessage(array(constants::PARAM_COLLAPSE_KEY=>$key, constants::PARAM_DATA=>array(pushmanager::MSG_TYPE=>$key, pushmanager::MSG_CONTENT=>json_encode($content)), constants::PARAM_REGISTRATION_IDS=>$entityPartition["ANDROID"]), "");
				//print_r($content);
			}

			echo $this->createSuccessJSON(array());
		} else
			echo $this->createErrorJSON(errorcode::MISSING_DATA, $this->errorcode->getErrorMessage(errorcode::MISSING_DATA));
	}


	/**
	 *
	 * Sample input:
	 * {"id":24,"entity_type":1, "ids": [{"id": 24, "type": "ENTITY" }, {"id": 39, "type": "ENTITY"}, {"id":32, "type": "EVENT"}, {"id":33, "type": "EVENT"},  {"id":134, "type": "EVENT"}, {"id": 42, "type": "ENTITY"},{ "id": 41, "type": "ENTITY"}], "all": false, "time_newest":0, "time_oldest":0}
	 *
	 * Sample output:
	 * {"stream":[{"sender_id":"24","sender_name":"Felix Nwaobasi","entity_type":"1","alias":"CONTENT","date":"1332939327000","content":"Hello world. Be great! Exude #BlackExcellence [CNN] [Willard Smith] [Barack Obama] [MTV]","mood_id":"1","image":"resources\/10000\/images\/img20111213083907.png"},{"sender_id":"24","sender_name":"Felix Nwaobasi","entity_type":"1","alias":"CONTENT","date":"1332922433000","content":"Hello World","mood_id":null,"image":"resources\/10000\/images\/img20111213083907.png"},{"sender_id":"24","sender_name":"Felix Nwaobasi","entity_type":"1","alias":"CONTENT","date":"1332922379000","content":"Hello World","mood_id":null,"image":"resources\/10000\/images\/img20111213083907.png"},{"sender_id":"24","sender_name":"Felix Nwaobasi","entity_type":"1","alias":"CONTENT","date":"1332922354000","content":"This is just a test message","mood_id":"1","image":"resources\/10000\/images\/img20111213083907.png"}],"success":true}
	 */
	public function getStream() {
		//time of first item in list. time of last. find everything newer than the first and older than the last.
		$json = json_decode(file_get_contents('php://input'), true);
		$requiredFields = array('id', 'entity_type', 'ids', 'all');
		if($this->areValuesSet($json, $requiredFields)) {
			$this->load->model('Message', 'mess');
			$stream = $this->mess->getStream($json);
			echo $this->createSuccessJSON(array("stream"=>$stream));
		} else
			echo $this->createErrorJSON(errorcode::MISSING_DATA, $this->errorcode->getErrorMessage(errorcode::MISSING_DATA));
	}


	/**
	 * Sample input:
	 *
	 * {"id":24,"entity_type":1, "ids": [{"id": 24, "type": "ENTITY" }, {"id": 39, "type": "ENTITY"}, {"id":32, "type": "EVENT"}, {"id":33, "type": "EVENT"},  {"id":134, "type": "EVENT"}, {"id": 42, "type": "ENTITY"},{ "id": 41, "type": "ENTITY"}], "all": false}
	 *
	 *
	 * Sample output:
	 * {"cluster":[{"id":"41","name":"Barack Obama","entity_type":"3","image":"resources\/10011\/images\/img20120131053719.png"},{"id":"32","name":"Christmas Celebration","entity_type":"8","image":"resources\/events\/event32\/img20121225070839.jpg"},{"id":"24","name":"Felix Nwaobasi","entity_type":"1","image":"resources\/24\/images\/img20121225070632.jpg"},{"id":"33","name":"Superbowl Party!!!!","entity_type":"8","image":""},{"id":"42","name":"Walmart","entity_type":"4","image":"resources\/10012\/images\/img20120201034447.png"},{"id":"39","name":"Willard Smith","entity_type":"2","image":"resources\/10009\/images\/img20120131050234.png"}],"success":true}
	 *
	 */
	public function getClusterDetails() {
		$json = json_decode(file_get_contents('php://input'), true);
		$requiredFields = array('id', 'entity_type', 'ids', 'all');
		if($this->areValuesSet($json, $requiredFields)) {
			$this->load->model('Entity', 'entity');
			$clusterArray = $json['all'] ? $this->entity->getAllAssociatedEntities($json['id']) : $this->entity->getEntitiesFromIdsAndType($json['ids']);
			echo $this->createSuccessJSON(array("cluster"=>$clusterArray));
		} else
			echo $this->createErrorJSON(errorcode::MISSING_DATA, $this->errorcode->getErrorMessage(errorcode::MISSING_DATA));
	}

	/**
	 * @deprecated
	 *
	 * Sample input:
	 *
	 * {"id":24,"entity_type":1}
	 *
	 * {"user_profile":{"basic_info":{"id":"24","name":"Felix Nwaobasi","lat":null,"lon":null,"image":"resources\/10000\/images\/img20111213083907.png"},"misc_info":{"line_1_label":"gender","line_1_value":"male","line_2_label":"age","line_2_value":"599220000000","line_3_label":"about","line_3_value":null},"tags":[{"rank":"1","id":"20","name":"African History"},{"rank":"1","id":"10","name":"Baseball"},{"rank":"1","id":"25","name":"Cinema"},{"rank":"1","id":"23","name":"Civil Rights"},{"rank":"1","id":"2","name":"Current Affairs"},{"rank":"1","id":"11","name":"Football"},{"rank":"1","id":"3","name":"Gym"},{"rank":"1","id":"5","name":"Hip-Hop Music"},{"rank":"1","id":"9","name":"Politics"},{"rank":"1","id":"7","name":"Pop Music"},{"rank":"1","id":"6","name":"R&B Music"},{"rank":"1","id":"33","name":"Social Media"},{"rank":"1","id":"32","name":"Technology"},{"rank":"1","id":"35","name":"Videogames"},{"rank":"1","id":"36","name":"Women"},{"rank":"2","id":"11","name":"Android Development"},{"rank":"2","id":"1","name":"C\/C++"},{"rank":"2","id":"16","name":"Game Development"},{"rank":"2","id":"2","name":"Java"},{"rank":"2","id":"13","name":"Mobile Development"},{"rank":"2","id":"3","name":"MySQL"},{"rank":"2","id":"4","name":"PHP"},{"rank":"3","id":"3","name":"Baseball"},{"rank":"3","id":"8","name":"Creating Music"},{"rank":"3","id":"17","name":"Debating"},{"rank":"3","id":"16","name":"Eating"},{"rank":"3","id":"13","name":"Exercise"},{"rank":"3","id":"32","name":"Gaming"},{"rank":"3","id":"15","name":"Movies"},{"rank":"3","id":"29","name":"Programming"},{"rank":"3","id":"23","name":"Reading"},{"rank":"4","id":"9","name":"African-American"},{"rank":"4","id":"26","name":"Ambitious"},{"rank":"4","id":"11","name":"Caucasian"},{"rank":"4","id":"32","name":"Charming"},{"rank":"4","id":"7","name":"Curvaceous"},{"rank":"4","id":"37","name":"Feminine"},{"rank":"4","id":"24","name":"Funny"},{"rank":"4","id":"13","name":"Hispanic"},{"rank":"4","id":"23","name":"Intelligent"},{"rank":"4","id":"21","name":"Nice Smile"},{"rank":"4","id":"33","name":"Religious"},{"rank":"4","id":"30","name":"Romantic"},{"rank":"4","id":"25","name":"Sarcastic"}]},"success":true}
	 *
	 */

	public function getNotifications() {
		$json = json_decode(file_get_contents('php://input'), true);
		$requiredFields = array('id', 'last_notified');
		if($this->areValuesSet($json, $requiredFields)) {
			$this->load->model('Message', 'mess');
			$momentsNotifs = $this->mess->getMessagesAfterTime($json);
			echo $this->createSuccessJSON(array("notifications"=>array("moments"=>array("items"=>$momentsNotifs['items'], "total"=>$momentsNotifs['total'])), "last_notified"=>time()));
		} else
			echo $this->createErrorJSON(errorcode::MISSING_DATA, $this->errorcode->getErrorMessage(errorcode::MISSING_DATA));
	}

	/**
	 * Method that is used to either save a GCM registration id or delete a GCM registration id
	 *
	 * Sample input:
	 *
	 * {"id":24, "registration_id":"test_reg", "phone_type": "ANDROID"}
	 *
	 * {"id":24, "delete":true}
	 *
	 */
	public function updateGCMRegistration() {
		$json = json_decode(file_get_contents('php://input'), true);
		$saveRequiredFields = array('id', 'registration_id', 'phone_type');
		$deleteRequiredFields = array('id', 'delete');
		if($this->areValuesSet($json, $saveRequiredFields) || $this->areValuesSet($json, $deleteRequiredFields)) {
			$this->load->library('pushmanager');
			if(!isset($json['delete'])) {
				if($this->pushmanager->saveRegistrationId($json['id'], $json['registration_id'], $json['phone_type']))
					echo $this->createSuccessJSON(array());
				else
					echo $this->createErrorJSON(errorcode::PUSH_REG_NOT_SAVED, $this->errorcode->getErrorMessage(errorcode::PUSH_REG_NOT_SAVED));

			} else {
				if($this->pushmanager->deleteRegistrationId($json['id']))
					echo $this->createSuccessJSON(array());
				else
					echo $this->createErrorJSON(errorcode::PUSH_REG_NOT_DELETED, $this->errorcode->getErrorMessage(errorcode::PUSH_REG_NOT_DELETED));
			}
		} else
			echo $this->createErrorJSON(errorcode::MISSING_DATA, $this->errorcode->getErrorMessage(errorcode::MISSING_DATA));
	}

	/**
	 * Sample input:
	 *
	 * {"id":39,"entity_type":1}
	 *
	 * Sample output:
	 * {"user_profile":{"basic_info":{"id":"24","first_name":"Felix","last_name":"Nwaobasi","name":"Felix Nwaobasi","entity_type":"1","birthday":"599209200000","lat":"40.819082","lon":"-73.9405822","image":"resources\/10000\/images\/img20111213083907.png"},"misc_info":{"line_1_label":"gender","line_1_value":"male","line_2_label":"age","line_2_value":"599209200000","line_3_label":"about","line_3_value":"","line_4_label":"occupation","line_4_value":""},"tags":[{"rank":"1","title":"social interests","id":"20","name":"African History"},{"rank":"1","title":"social interests","id":"10","name":"Baseball"},{"rank":"1","title":"social interests","id":"25","name":"Cinema"},{"rank":"1","title":"social interests","id":"23","name":"Civil Rights"},{"rank":"1","title":"social interests","id":"2","name":"Current Affairs"},{"rank":"1","title":"social interests","id":"11","name":"Football"},{"rank":"1","title":"social interests","id":"3","name":"Gym"},{"rank":"1","title":"social interests","id":"5","name":"Hip-Hop Music"},{"rank":"1","title":"social interests","id":"9","name":"Politics"},{"rank":"1","title":"social interests","id":"7","name":"Pop Music"},{"rank":"1","title":"social interests","id":"6","name":"R&B Music"},{"rank":"1","title":"social interests","id":"33","name":"Social Media"},{"rank":"1","title":"social interests","id":"32","name":"Technology"},{"rank":"1","title":"social interests","id":"35","name":"Videogames"},{"rank":"1","title":"social interests","id":"36","name":"Women"},{"rank":"2","title":"business interests","id":"11","name":"Android Development"},{"rank":"2","title":"business interests","id":"1","name":"C\/C++"},{"rank":"2","title":"business interests","id":"16","name":"Game Development"},{"rank":"2","title":"business interests","id":"2","name":"Java"},{"rank":"2","title":"business interests","id":"13","name":"Mobile Development"},{"rank":"2","title":"business interests","id":"3","name":"MySQL"},{"rank":"2","title":"business interests","id":"4","name":"PHP"},{"rank":"3","title":"activities","id":"3","name":"Baseball"},{"rank":"3","title":"activities","id":"8","name":"Creating Music"},{"rank":"3","title":"activities","id":"17","name":"Debating"},{"rank":"3","title":"activities","id":"16","name":"Eating"},{"rank":"3","title":"activities","id":"13","name":"Exercise"},{"rank":"3","title":"activities","id":"32","name":"Gaming"},{"rank":"3","title":"activities","id":"15","name":"Movies"},{"rank":"3","title":"activities","id":"29","name":"Programming"},{"rank":"3","title":"activities","id":"23","name":"Reading"},{"rank":"4","title":"people","id":"9","name":"African-American"},{"rank":"4","title":"people","id":"26","name":"Ambitious"},{"rank":"4","title":"people","id":"11","name":"Caucasian"},{"rank":"4","title":"people","id":"32","name":"Charming"},{"rank":"4","title":"people","id":"7","name":"Curvaceous"},{"rank":"4","title":"people","id":"37","name":"Feminine"},{"rank":"4","title":"people","id":"24","name":"Funny"},{"rank":"4","title":"people","id":"13","name":"Hispanic"},{"rank":"4","title":"people","id":"23","name":"Intelligent"},{"rank":"4","title":"people","id":"21","name":"Nice Smile"},{"rank":"4","title":"people","id":"33","name":"Religious"},{"rank":"4","title":"people","id":"30","name":"Romantic"},{"rank":"4","title":"people","id":"25","name":"Sarcastic"}]},"success":true}
	 *
	 */
	public function getUserProfile() {
		$json = json_decode(file_get_contents('php://input'), true);
		$requiredFields = array('id', 'entity_type');
		if($this->areValuesSet($json, $requiredFields)) {
			$this->load->model('Usertype', 'userType');
			$tableType = $this->userType->getBroadUserTypeIdById($json["entity_type"]);
			$this->load->model('Entity', 'entity');

			$user = $this->entity->getProfile($json['id'], $tableType);
			if(!$this->errorcode->isError($user)) {
				echo $this->createSuccessJSON(array("user_profile"=>$user));
			} else {
				echo $this->createErrorJSON($user, $this->errorcode->getErrorMessage($user));
			}
		} else
			echo $this->createErrorJSON(errorcode::MISSING_DATA, $this->errorcode->getErrorMessage(errorcode::MISSING_DATA));
	}
	
	/**
	 * Sample input:
	 *
	 * {"id":24, "their_id": 39, "entity_type":1}
	 *
	 * Sample output:
	 * {"user_profile":{"basic_info":{"id":"24","first_name":"Kachi","last_name":"Nwaobasi","name":"Felix Nwaobasi","entity_type":"1","birthday":"599209200000","lat":"40.819082","lon":"-73.9405822","image":"resources\/10000\/images\/img20111213083907.png"},"misc_info":{"line_1_label":"gender","line_1_value":"male","line_2_label":"age","line_2_value":"599209200000","line_3_label":"about","line_3_value":"","line_4_label":"occupation","line_4_value":""},"tags":[{"rank":"1","title":"social interests","id":"20","name":"African History"},{"rank":"1","title":"social interests","id":"10","name":"Baseball"},{"rank":"1","title":"social interests","id":"25","name":"Cinema"},{"rank":"1","title":"social interests","id":"23","name":"Civil Rights"},{"rank":"1","title":"social interests","id":"2","name":"Current Affairs"},{"rank":"1","title":"social interests","id":"11","name":"Football"},{"rank":"1","title":"social interests","id":"3","name":"Gym"},{"rank":"1","title":"social interests","id":"5","name":"Hip-Hop Music"},{"rank":"1","title":"social interests","id":"9","name":"Politics"},{"rank":"1","title":"social interests","id":"7","name":"Pop Music"},{"rank":"1","title":"social interests","id":"6","name":"R&B Music"},{"rank":"1","title":"social interests","id":"33","name":"Social Media"},{"rank":"1","title":"social interests","id":"32","name":"Technology"},{"rank":"1","title":"social interests","id":"35","name":"Videogames"},{"rank":"1","title":"social interests","id":"36","name":"Women"},{"rank":"2","title":"business interests","id":"11","name":"Android Development"},{"rank":"2","title":"business interests","id":"1","name":"C\/C++"},{"rank":"2","title":"business interests","id":"16","name":"Game Development"},{"rank":"2","title":"business interests","id":"2","name":"Java"},{"rank":"2","title":"business interests","id":"13","name":"Mobile Development"},{"rank":"2","title":"business interests","id":"3","name":"MySQL"},{"rank":"2","title":"business interests","id":"4","name":"PHP"},{"rank":"3","title":"activities","id":"3","name":"Baseball"},{"rank":"3","title":"activities","id":"8","name":"Creating Music"},{"rank":"3","title":"activities","id":"17","name":"Debating"},{"rank":"3","title":"activities","id":"16","name":"Eating"},{"rank":"3","title":"activities","id":"13","name":"Exercise"},{"rank":"3","title":"activities","id":"32","name":"Gaming"},{"rank":"3","title":"activities","id":"15","name":"Movies"},{"rank":"3","title":"activities","id":"29","name":"Programming"},{"rank":"3","title":"activities","id":"23","name":"Reading"},{"rank":"4","title":"people","id":"9","name":"African-American"},{"rank":"4","title":"people","id":"26","name":"Ambitious"},{"rank":"4","title":"people","id":"11","name":"Caucasian"},{"rank":"4","title":"people","id":"32","name":"Charming"},{"rank":"4","title":"people","id":"7","name":"Curvaceous"},{"rank":"4","title":"people","id":"37","name":"Feminine"},{"rank":"4","title":"people","id":"24","name":"Funny"},{"rank":"4","title":"people","id":"13","name":"Hispanic"},{"rank":"4","title":"people","id":"23","name":"Intelligent"},{"rank":"4","title":"people","id":"21","name":"Nice Smile"},{"rank":"4","title":"people","id":"33","name":"Religious"},{"rank":"4","title":"people","id":"30","name":"Romantic"},{"rank":"4","title":"people","id":"25","name":"Sarcastic"}]},"success":true}
	 *
	 */
	public function getNewUserProfile() {
		$json = json_decode(file_get_contents('php://input'), true);
		$requiredFields = array('id', 'their_id', 'entity_type');
		if($this->areValuesSet($json, $requiredFields)) {
			$this->load->model('Usertype', 'userType');
			$tableType = $this->userType->getBroadUserTypeIdById($json["entity_type"]);
			
			$this->load->model('Relationship_general', 'rel');
			if($json['id'] == $json['their_id'] || $this->rel->doesRelationshipExist($json['id'], $json['their_id'])) {
				$this->load->model('Entity', 'entity');
				$user = $this->entity->getProfile($json['their_id'], $tableType);
				if(!$this->errorcode->isError($user))
					echo $this->createSuccessJSON(array("user_profile"=>$user));
				else 
					echo $this->createErrorJSON($user, $this->errorcode->getErrorMessage($user));
			} else
				echo $this->createErrorJSON(errorcode::BAD_USER_PERMISSION, $this->errorcode->getErrorMessage(errorcode::BAD_USER_PERMISSION));
		} else
			echo $this->createErrorJSON(errorcode::MISSING_DATA, $this->errorcode->getErrorMessage(errorcode::MISSING_DATA));
	}

	public function getTags() {
		$json = json_decode(file_get_contents('php://input'), true);
		$requiredFields = array('tag');
		if($this->areValuesSet($json, $requiredFields)) {

			$this->load->model('Tag', 'tag');
			echo $this->createSuccessJSON(array("tags"=>($this->tag->getTag($json['tag']))));


		} else
			echo $this->createErrorJSON(errorcode::MISSING_DATA, $this->errorcode->getErrorMessage(errorcode::MISSING_DATA));
	}

	public function createEvent() {
		$json = json_decode($this->input->post('string'), true);
		$requiredFields = array('name', 'lat', 'lon', 'geofence_lat', 'geofence_lon', 'geofence_radius', 'start_time', 'end_time', 'hashtag', 'allow_rating');
		if($this->areValuesSet($json, $requiredFields)) {
			$this->load->model('Event', 'event');


			//decided not to use transactions here.
			//if the event isnt created, then none of the other ones are created.
			//invitees array isnt important if this fails. that leaves tags and photos......
			//how important is it to you to delete the event if a photo and a tag doesnt get saved? Think about it.
			if($event = $this->event->createEvent($json)) {
				if(isset($json['invitees_array'])) {
					$this->load->model('Event_rsvp', 'rsvps');
					$this->rsvps->addRsvps($event['id'], $json['invitees_array']);
				}


				if(isset($json['tags'])) {
					$this->load->model('Tag', 'tag');
					$this->tag->modifyTags($event['id'], 'event', $json['tags'], null);
				}

				$event['image'] = "";
				if(count($_FILES) > 0) {
					$this->load->library('upload');  // NOTE: always load the library outside the loop
					$this->load->library('util');

					mkdir($dir = self::RESOURCE_FOLDER . "events/event" . $event['id'] . "/", 0755, true);
					$filePath = pathinfo($_FILES['file_0']['name']);

					$config = array('file_name'=>"img" . date('YmdHis', time()) . "." . $filePath['extension'], 'allowed_types' => "*", 'upload_path' => $dir,
							'max_size' => '0', 'overwrite' => FALSE);
					$this->upload->initialize($config);
					if($this->upload->do_upload("file_0")) {
						$filename = $config['upload_path'] . $config['file_name'];
						$size = getimagesize($filename);
						$photoData = array('event_id'=>$event['id'], 'image'=>$filename, 'is_flyer'=>true, 'width'=>$size[0], 'height'=>$size[1]);
						$this->load->model('Photo', 'photo');
						if($this->photo->addPhoto('photo_event', $photoData))
							$event['image'] = $filename;
					}

				}

				echo $this->createSuccessJSON(array("event"=>$event));
			} else
				echo $this->createErrorJSON(errorcode::EVENT_NOT_CREATED, $this->errorcode->getErrorMessage(errorCode::EVENT_NOT_CREATED));

		} else
			echo $this->createErrorJSON(errorcode::MISSING_DATA, $this->errorcode->getErrorMessage(errorcode::MISSING_DATA));

	}

	public function editEvent() {
		$json = json_decode($this->input->post('string'), true);
		$requiredFields = array('id', 'event_id');
		if($this->areValuesSet($json, $requiredFields)) {
			$this->db->trans_start(); //start editing

			$this->load->model('Photo', 'photo');
			//modify photos if necessary
			if(count($_FILES) > 0) {
				$this->load->library('upload');
				$this->load->library('util');

				$dirName = self::RESOURCE_FOLDER . "events/event" . $json['event_id'] . "/";
				if(!file_exists($dirName))
					mkdir($dirName , 0755, true);
				$filePath = pathinfo($_FILES['file_0']['name']);
					
				$config = array('file_name'=>"img" . date('YmdHis', time()) . "." . $filePath['extension'], 'allowed_types' => "*", 'upload_path' => $dirName,
						'max_size' => '0', 'overwrite' => FALSE);
				$this->upload->initialize($config);
				if($this->upload->do_upload("file_0")) {
					$filename = $config['upload_path'] . $config['file_name'];
					$size = getimagesize($filename);
					$photoData = array('event_id'=>$json['event_id'], 'image'=>$filename, 'is_flyer'=>true, 'width'=>$size[0], 'height'=>$size[1]);
					$this->photo->removeEventFlyer($json['event_id']);
					$this->photo->addPhoto('photo_event', $photoData);
				}
					
			} else if(array_key_exists('photo', $json) && !isset($json['photo'])) {
				$this->photo->removeEventFlyer($json['event_id']);
				unset($json['photo']);
			}

			//actually modify the event;
			$this->load->model('Event', 'event');
			$event = $this->event->editInfo($json);
			$this->db->trans_complete();
			if ($this->db->trans_status() === FALSE) {
				if(isset($filename))
					unlink($filename);
				echo $this->createErrorJSON(errorcode::UPDATE_EVENT_FAILED, $this->errorcode->getErrorMessage(errorcode::UPDATE_EVENT_FAILED));
			}
			else
				echo $this->createSuccessJSON(array("event"=>$event));
		} else
			echo $this->createErrorJSON(errorcode::MISSING_DATA, $this->errorcode->getErrorMessage(errorcode::MISSING_DATA));
	}

	/**
	 *
	 * Sample input:
	 * {"id": 24, "time_value": ["start"], "id_range": [0, 1], "time_range": [0, -1], "rsvp_alias": "INVITE", "include_mine": true, "limit":100  }
	 *
	 * or to get current events (put the current time for both values inside time range. i.e.
	 * {"id": 24, "time_value": ["start", "end"], "id_range": [0, 1], "time_range": [1352454803000, 1352454803000], "include_mine": true, "limit":100  }
	 */
	//
	public function getEvents() {
		$json = json_decode(file_get_contents('php://input'), true);
		$requiredFields = array('id', 'time_value', 'time_range');
		if($this->areValuesSet($json, $requiredFields)) {
			$this->load->model('Event', 'event');
			echo $this->createSuccessJSON(array("results"=>$this->event->fetchEvents($json)));
		} else
			echo $this->createErrorJSON(errorcode::MISSING_DATA, $this->errorcode->getErrorMessage(errorcode::MISSING_DATA));
		//fields AFTER_TODAY?, where RSVP=? only one that im going to? invited? or all, last_id,
		//boolean afterStart, int rsvpType, int limit
		//'last_event_id', 'end_range',
	}

	/**
	 *
	 * Sample input:
	 * {"id": 24, "event_id": 10}
	 *
	 * Sample output:
	 * {"event":{"info":[{"id":"10","name":"Eve's Birthday","hashtag":"EvesBirthday","lat":"40.8187356","lon":"-73.9400729","start_time":"1343768402000","end_time":"1343772133000","creator_name":"Felix Nwaobasi","is_mine":"1","details":"Birthday party for Eve!","allow_rating":"1","image":"resources\/events\/event10\/img20120731044313.jpg","stream_id":"-1","is_moment":"0","going":"1","invited":"0","rating_positive":null,"rating_negative":null,"rsvp":"2"}],"comments":[{"id":"3","entity_id":"46","name":"NSBE-WPI","comment":"Are you sure you don't want to become a black engineer for your birthday Eve?","date":"2012-08-23 18:43:24","image":"resources\/10016\/images\/img20120201045521.png"},{"id":"2","entity_id":"42","name":"Walmart","comment":"Get a special coupon from us at Walmart","date":"2012-08-23 18:42:41","image":"resources\/10012\/images\/img20120201034447.png"},{"id":"1","entity_id":"24","name":"Felix Nwaobasi","comment":"OMG EVE!!! You're 24!! I cannot wait to show up","date":"2012-08-23 18:41:33","image":"resources\/10000\/images\/img20111213083907.png"}],"tags":[{"id":"1","name":"Birthday"},{"id":"9","name":"Food"},{"id":"8","name":"Party"}]},"success":true}
	 *
	 */
	public function getEvent() {
		$json = json_decode(file_get_contents('php://input'), true);
		$requiredFields = array('id', 'event_id');
		if($this->areValuesSet($json, $requiredFields)) {
			$this->load->model('Event', 'event');
			$event = $this->event->fetchEvent($json);
			if(!$this->errorcode->isError($event))
				echo $this->createSuccessJSON(array("event"=>$event));
			else
				echo $this->createErrorJSON($event, $this->errorcode->getErrorMessage($event));
		} else
			echo $this->createErrorJSON(errorcode::MISSING_DATA, $this->errorcode->getErrorMessage(errorcode::MISSING_DATA));

	}
	
	public function notifyAttendeesOfEventLocationChange() {
		$json = json_decode(file_get_contents('php://input'), true);
		$requiredFields = array('id', 'event_ids');
		if($this->areValuesSet($json, $requiredFields) ) {
			$this->load->model('Checkin', 'checkin');
			$totalAttendees = array();
			foreach($json['event_ids'] as $eventId) {
				$attendees = $this->checkin->getAllEntitiesCheckedInAtPlace($json['id'], $eventId, "EVENT");
				$totalAttendees = array_merge($totalAttendees,$attendees);
			}
			$totalAttendees = array_unique($totalAttendees, SORT_REGULAR);
			
			if(count($totalAttendees) > 0)	 {
				$this->load->library('pushmanager');
		
				$entityPartition = $this->pushmanager->partitionPhoneUsers($totalAttendees);
				$message = array(constants::PARAM_COLLAPSE_KEY=>pushmanager::EVENT_LOCATION_CHANGE, constants::PARAM_DATA=>array(pushmanager::MSG_TYPE=>pushmanager::EVENT_LOCATION_CHANGE, 
						pushmanager::MSG_CONTENT=>json_encode(array("creator_id"=>$json['id']))), constants::PARAM_DELAY_WHILE_IDLE=>true, constants::PARAM_REGISTRATION_IDS=>$entityPartition["ANDROID"]);
				$this->pushmanager->sendMessage($message, "");
			}
		
			echo $this->createSuccessJSON(array());
		} else
			echo $this->createErrorJSON(errorcode::MISSING_DATA, $this->errorcode->getErrorMessage(errorcode::MISSING_DATA));
		
	}

	/**
	 * Sample input:
	 * {"id": 24,"event_id":28}
	 *
	 * Sample output:
	 * {"guest_list":[{"id":"24","name":"Kachi Nwaobasi","entity_type":"1","image":"resources\/24\/images\/img20130204001156.jpg","rsvp_state":"ATTEND","my_state":null,"their_state":null},{"id":"38","name":"Yetti Ajayi-Obe","entity_type":"1","image":"resources\/38\/images\/img20130304044630.jpg","rsvp_state":"ATTEND","my_state":"8","their_state":"8"}],"success":true}
	 *
	 */
	public function getEventGuestList() {
		$json = json_decode(file_get_contents('php://input'), true);
		$requiredFields = array('id', 'event_id');
		if($this->areValuesSet($json, $requiredFields)) {
			$this->load->model('Event', 'event');
			echo $this->createSuccessJSON(array("guest_list"=>$this->event->getGuestList($json['id'], $json['event_id'])));
		} else
			echo $this->createErrorJSON(errorcode::MISSING_DATA, $this->errorcode->getErrorMessage(errorcode::MISSING_DATA));
	}

	public function sendEventComment() {
		$json = json_decode(file_get_contents('php://input'), true);
		$requiredFields = array('id', 'event_id', 'comment', 'last_comment_id');
		if($this->areValuesSet($json, $requiredFields) ) {

			$json['entity_id'] = $json['id'];
			$this->load->model('Event_comment', 'comment');
			$this->db->trans_start();

			$this->comment->insertComment($json);
			$comments = $this->comment->getEventComments($json['event_id'], $json['last_comment_id'], 100);
			$this->db->trans_complete();
			if ($this->db->trans_status() === TRUE) {
				echo $this->createSuccessJSON(array("comments"=>$comments));
			} else {
				echo $this->createErrorJSON(errorcode::MESSAGE_FAILED, $this->errorcode->getErrorMessage(errorcode::MESSAGE_FAILED));
			}
		} else
			echo $this->createErrorJSON(errorcode::MISSING_DATA, $this->errorcode->getErrorMessage(errorcode::MISSING_DATA));
	}

	public function notifyEventInvitees() {
		$json = json_decode(file_get_contents('php://input'), true);
		$requiredFields = array('event', 'recipients');
		if($this->areValuesSet($json, $requiredFields) ) {
			$this->load->model('Entity', 'entity');
			if(count($participants = $this->entity->getEntitiesFromIds($json['recipients'], true)) > 0)	 {

				$this->load->library('pushmanager');
				$entityPartition = $this->pushmanager->partitionPhoneUsers($participants);

				$this->pushmanager->sendMessage(array(constants::PARAM_COLLAPSE_KEY=>pushmanager::KEY_EVENT_INVITE, constants::PARAM_DATA=>array(pushmanager::MSG_TYPE=>pushmanager::KEY_EVENT_INVITE, pushmanager::MSG_CONTENT=>json_encode($json['event'])), constants::PARAM_REGISTRATION_IDS=>$entityPartition["ANDROID"]), "");
			}

			echo $this->createSuccessJSON(array());
		} else
			echo $this->createErrorJSON(errorcode::MISSING_DATA, $this->errorcode->getErrorMessage(errorcode::MISSING_DATA));
	}

	/**
	 *
	 * Sample input:
	 * {"comment":"Trash","id":24,"alias":"COMMENT","event_id":27}
	 *
	 * Sample output
	 * {"item":{"id":"14","event_id":"27","entity_id":"24","name":"Felix Nwaobasi","stream_type":"COMMENT","comment":"New comment","date":"1348695926000","image":"resources\/10000\/images\/img20111213083907.png","media":null},"success":true}
	 *
	 */
	public function sendLiveStreamItem() {
		$json = json_decode($this->input->post('string'), true);
		$requiredFields = array('id', 'event_id', 'alias');

		if($this->areValuesSet($json, $requiredFields) ) {
			$this->load->model('Livestream_item', 'livestream');
			switch($json['alias']) {
				case Livestream_item::PHOTO:
				case Livestream_item::VIDEO:
					if(count($_FILES) > 0) {
						$this->load->library('upload');  // NOTE: always load the library outside the loop
						$this->load->library('util');
						if(!file_exists($dir = self::RESOURCE_FOLDER . "events/event" . $json['event_id'] . "/stream/". strtolower($json['alias']) . "/"))
							mkdir($dir, 0755, true);
						$filePath = pathinfo($_FILES['file_0']['name']);
							
						$config = array('file_name'=>"img" . date('YmdHis', time()) . "." . $filePath['extension'], 'allowed_types' => "*", 'upload_path' => $dir,
								'max_size' => '0', 'overwrite' => FALSE);
						$this->upload->initialize($config);
						if($this->upload->do_upload("file_0")) {
							$filename = $config['upload_path'] . $config['file_name'];
							$json['file'] = $filename;
						}
					}
					break;
			}

			//rename id to entity_id. We have it as id, because that's what its traditionally been.
			$json['entity_id'] = $json['id'];
			$this->db->trans_start();
			$stream_item = $this->livestream->insertNewItem($json);

			$this->db->trans_complete();
			if (!$this->errorcode->isError($stream_item) && $this->db->trans_status() === TRUE) {
				echo $this->createSuccessJSON(array("item"=>$stream_item));
			} else {
				echo $this->createErrorJSON($stream_item, $this->errorcode->getErrorMessage($stream_item));
			}


		} else
			echo $this->createErrorJSON(errorcode::MISSING_DATA, $this->errorcode->getErrorMessage(errorcode::MISSING_DATA));

	}

	/**
	 * Sample input:
	 * {"id": 24,"event_id":27}
	 *
	 * Sample output:
	 * {"info":{"id":"28","name":"House Warming","lat":"40.8187607","lon":"-73.9401166","image":"resources\/events\/event28\/img20120921223307.jpg","attendee_count":1,"my_rating":"7","avg":"8","avg_percent":"80%","rating_count":"2"},"stream":[{"id":"30","event_id":"28","entity_id":"24","name":"Felix Nwaobasi","stream_type":"RATING","comment":"Felix Nwaobasi has given this event a rating of 7","date":"1349025719000","image":"resources\/10000\/images\/img20111213083907.png","media":null},{"id":"29","event_id":"28","entity_id":"25","name":"Someone","stream_type":"RATING","comment":"Someone has given this event a rating of 9","date":"1349025688000","image":"resources\/0\/images\/user_image.png","media":null},{"id":"28","event_id":"28","entity_id":"24","name":"Felix Nwaobasi","stream_type":"RATING","comment":"Felix Nwaobasi has given this event a rating of 9","date":"1349025651000","image":"resources\/10000\/images\/img20111213083907.png","media":null},{"id":"25","event_id":"28","entity_id":"24","name":"Felix Nwaobasi","stream_type":"VIDEO","comment":"Felix Nwaobasi has added a video at this event","date":"1348730791000","image":"resources\/10000\/images\/img20111213083907.png","media":"resources\/events\/event28\/stream\/video\/img20120927072631.3gp"},{"id":"24","event_id":"28","entity_id":"24","name":"Felix Nwaobasi","stream_type":"PHOTO","comment":"Felix Nwaobasi has added a photo at this event","date":"1348729719000","image":"resources\/10000\/images\/img20111213083907.png","media":"resources\/events\/event28\/stream\/photo\/img20120927070839.jpg"},{"id":"23","event_id":"28","entity_id":"24","name":"Felix Nwaobasi","stream_type":"PHOTO","comment":"Felix Nwaobasi has added a photo at this event","date":"1348724894000","image":"resources\/10000\/images\/img20111213083907.png","media":"resources\/events\/event28\/stream\/photo\/img20120927054814.jpg"},{"id":"22","event_id":"28","entity_id":"24","name":"Felix Nwaobasi","stream_type":"COMMENT","comment":"Fucking finally man!","date":"1348724592000","image":"resources\/10000\/images\/img20111213083907.png","media":null},{"id":"21","event_id":"28","entity_id":"24","name":"Felix Nwaobasi","stream_type":"COMMENT","comment":"More","date":"1348713869000","image":"resources\/10000\/images\/img20111213083907.png","media":null},{"id":"20","event_id":"28","entity_id":"24","name":"Felix Nwaobasi","stream_type":"COMMENT","comment":"I'm hungry. Where's the food?","date":"1348713751000","image":"resources\/10000\/images\/img20111213083907.png","media":null},{"id":"19","event_id":"28","entity_id":"24","name":"Felix Nwaobasi","stream_type":"COMMENT","comment":"She's back!","date":"1348713528000","image":"resources\/10000\/images\/img20111213083907.png","media":null},{"id":"18","event_id":"28","entity_id":"24","name":"Felix Nwaobasi","stream_type":"COMMENT","comment":"I love this Ciara song!!","date":"1348713486000","image":"resources\/10000\/images\/img20111213083907.png","media":null},{"id":"17","event_id":"28","entity_id":"24","name":"Felix Nwaobasi","stream_type":"COMMENT","comment":"What the hell?","date":"1348713260000","image":"resources\/10000\/images\/img20111213083907.png","media":null},{"id":"16","event_id":"28","entity_id":"24","name":"Felix Nwaobasi","stream_type":"COMMENT","comment":"We had an error","date":"1348713159000","image":"resources\/10000\/images\/img20111213083907.png","media":null},{"id":"15","event_id":"28","entity_id":"24","name":"Felix Nwaobasi","stream_type":"COMMENT","comment":"Test comment. Local though.","date":"1348713092000","image":"resources\/10000\/images\/img20111213083907.png","media":null},{"id":"13","event_id":"28","entity_id":"24","name":"Felix Nwaobasi","stream_type":"ARRIVE","comment":"Felix Nwaobasi has entered the event","date":"1348520516000","image":"resources\/10000\/images\/img20111213083907.png","media":null}],"friends":[{"entity_id":"24","name":"Felix Nwaobasi","entity_type":"1","image":"resources\/10000\/images\/img20111213083907.png"}],"success":true}
	 *
	 */
	public function getLiveStream() {
		$json = json_decode(file_get_contents('php://input'), true);
		$requiredFields = array('id', 'event_id');
		if($this->areValuesSet($json, $requiredFields) ) {
			$this->load->model('Livestream_item', 'livestream');
			$this->load->model('Checkin', 'checkin');
			$this->load->model('Event', 'event');
			echo $this->createSuccessJSON(array("info"=>$this->event->getBasicInfo($json), "stream"=>$this->livestream->getStreamItems($json), "friends"=>$this->checkin->getAllEntitiesCheckedInAtPlace($json['id'], $json['event_id'], "EVENT", false)));
		} else
			echo $this->createErrorJSON(errorcode::MISSING_DATA, $this->errorcode->getErrorMessage(errorcode::MISSING_DATA));

	}

	public function notifyLiveStreamParticipants() {

		$json = json_decode(file_get_contents('php://input'), true);
		$requiredFields = array('id', 'event_id', 'hidden');
		if($this->areValuesSet($json, $requiredFields) ) {
			$this->load->model('Checkin', 'checkin');
			if(count($participants = $this->checkin->getAllEntitiesCheckedInAtPlace($json['id'], $json['event_id'], "EVENT")) > 0)	 {
				$this->load->library('pushmanager');

				$entityPartition = $this->pushmanager->partitionPhoneUsers($participants);
				$message = array(constants::PARAM_COLLAPSE_KEY=>pushmanager::KEY_LIVESTREAM, constants::PARAM_DATA=>array(pushmanager::MSG_TYPE=>pushmanager::KEY_LIVESTREAM), constants::PARAM_DELAY_WHILE_IDLE=>true, constants::PARAM_REGISTRATION_IDS=>$entityPartition["ANDROID"]);
				if($json['hidden']) {
					$this->load->model('Livestream_item', 'livestream');
					$message[constants::PARAM_DATA][pushmanager::MSG_CONTENT] = json_encode(array("hide"=>$this->livestream->getHiddenDepartureIdForEntity($json['id'])));
				}
				$this->pushmanager->sendMessage($message, "");
			}

			echo $this->createSuccessJSON(array());
		} else
			echo $this->createErrorJSON(errorcode::MISSING_DATA, $this->errorcode->getErrorMessage(errorcode::MISSING_DATA));

	}


	/**
	 *
	 * Sample input:
	 * {"id": 47,"event_id":28, "rsvp":2}
	 *
	 * Sample output:
	 * {"rsvp":{"event_id":"28","entity_id":"47","state":"2","is_moment":"0"},"success":true}
	 *
	 * Enter description here ...
	 */
	public function changeRsvp() {
		$json = json_decode(file_get_contents('php://input'), true);
		$requiredFields = array('id', 'event_id', 'rsvp');
		if($this->areValuesSet($json, $requiredFields) ) {
			$this->load->model('Event_rsvp', 'rsvp');
			echo $this->createSuccessJSON(array('rsvp'=>$this->rsvp->changeRsvp($json)));
		} else
			echo $this->createErrorJSON(errorcode::MISSING_DATA, $this->errorcode->getErrorMessage(errorcode::MISSING_DATA));
	}

	public function addMoment() {
		$json = json_decode(file_get_contents('php://input'), true);
		$requiredFields = array('id', 'moment_id', 'type');
		if($this->areValuesSet($json, $requiredFields) ) {
			$this->load->model('Moment', 'moment');
			$this->db->trans_start(); //we're doing this because we're updating. if the update fails, we can easily see by just checking the transaction code
			$this->moment->addMoment($json);
			$this->db->trans_complete();

			if ($this->db->trans_status() === FALSE)
				echo $this->createErrorJSON(errorcode::MOMENT_NOT_ADDED, $this->errorcode->getErrorMessage(errorCode::MOMENT_NOT_ADDED));
			else
				echo $this->createSuccessJSON(array());

		} else
			echo $this->createErrorJSON(errorcode::MISSING_DATA, $this->errorcode->getErrorMessage(errorcode::MISSING_DATA));
	}

	public function removeMoment() {
		$json = json_decode(file_get_contents('php://input'), true);
		$requiredFields = array('id', 'moment_id', 'type');
		if($this->areValuesSet($json, $requiredFields) ) {
			$this->load->model('Moment', 'moment');
			$this->db->trans_start(); //we're doing this because we're updating. if the update fails, we can easily see by just checking the transaction code
			$this->moment->removeMoment($json);
			$this->db->trans_complete();

			if ($this->db->trans_status() === FALSE)
				echo $this->createErrorJSON(errorcode::MOMENT_NOT_REMOVED, $this->errorcode->getErrorMessage(errorCode::MOMENT_NOT_REMOVED));
			else
				echo $this->createSuccessJSON(array());

		} else
			echo $this->createErrorJSON(errorcode::MISSING_DATA, $this->errorcode->getErrorMessage(errorcode::MISSING_DATA));
	}

	/**
	 * Sample input:
	 * {"id": 24, "time_range": [12312312124, 0]}
	 *
	 * {"moments":[{"moment_id":"109","moment_name":"Content","sender_id":"24","sender_name":"Felix Nwaobasi","recipient_id":"48","recipient_name":"Bunnie Bernab\u00e9","moment_type":"INTERACTION","datatype":"CONTENT","image":"","misc":"resources\/24\/images\/img20121225070632.jpg|resources\/48\/images\/img20121202180906.jpg","date":"1354493113000"}],"success":true}
	 *
	 */
	public function getMoments() {
		//TODO optional field. their_id. If not specified, get all moments.
		//in events this can be used for both creator and venue_id
		$json = json_decode(file_get_contents('php://input'), true);
		$requiredFields = array('id', 'time_range');
		if($this->areValuesSet($json, $requiredFields) ) {
			$this->load->model('Moment', 'moment');
			echo $this->createSuccessJSON(array("moments"=>$this->moment->fetchMoments($json)));
		} else
			echo $this->createErrorJSON(errorcode::MISSING_DATA, $this->errorcode->getErrorMessage(errorcode::MISSING_DATA));
	}


	/**
	 *
	 * Sample input:
	 * {"id":24,"business_id":44, "content":"Trash"}
	 *
	 * Sample output
	 * {"item":{"id":"14","event_id":"27","entity_id":"24","name":"Felix Nwaobasi","stream_type":"COMMENT","comment":"New comment","date":"1348695926000","image":"resources\/10000\/images\/img20111213083907.png","media":null},"success":true}
	 *
	 */
	public function sendLocationChatMessage() {
		$json = json_decode(file_get_contents('php://input'), true);
		$requiredFields = array('id', 'business_id', 'content');

		if($this->areValuesSet($json, $requiredFields)) {
			$this->load->model('Location_chat', 'loc_chat');
			//rename id to entity_id. We have it as id, because that's what its traditionally been.
			$json['entity_id'] = $json['id'];
			unset($json['id']); //remove id;
			$this->db->trans_start();
			$message = $this->loc_chat->insertMessage($json);

			$this->db->trans_complete();
			if (!$this->errorcode->isError($message) && $this->db->trans_status() === TRUE) {
				echo $this->createSuccessJSON(array("message"=>$message));
			} else {
				echo $this->createErrorJSON(errorCode::MESSAGE_FAILED, $this->errorcode->getErrorMessage(errorCode::MESSAGE_FAILED));
			}


		} else
			echo $this->createErrorJSON(errorcode::MISSING_DATA, $this->errorcode->getErrorMessage(errorcode::MISSING_DATA));

	}

	/**
	 * Sample input:
	 * {"id": 24, "ids":[51, 49], "flag":1}
	 *
	 *
	 *
	 */
	public function revealIdentity() {
		$json = json_decode(file_get_contents('php://input'), true);
		$requiredFields = array('id', 'ids', 'flag');
		if($this->areValuesSet($json, $requiredFields)) {
			$this->load->model('Location_chat', 'loc_chat');
			$this->loc_chat->revealIdentityToEntities($json);
			echo $this->createSuccessJSON(array());
		} else
			echo $this->createErrorJSON(errorcode::MISSING_DATA, $this->errorcode->getErrorMessage(errorcode::MISSING_DATA));
	}

	/**
	 * Sample input:
	 * {"id": 24,"business_id":44, "type":3}
	 *
	 * Sample output:
	 * {"info":{"id":"28","name":"House Warming","lat":"40.8187607","lon":"-73.9401166","image":"resources\/events\/event28\/img20120921223307.jpg","attendee_count":1,"my_rating":"7","avg":"8","avg_percent":"80%","rating_count":"2"},"stream":[{"id":"30","event_id":"28","entity_id":"24","name":"Felix Nwaobasi","stream_type":"RATING","comment":"Felix Nwaobasi has given this event a rating of 7","date":"1349025719000","image":"resources\/10000\/images\/img20111213083907.png","media":null},{"id":"29","event_id":"28","entity_id":"25","name":"Someone","stream_type":"RATING","comment":"Someone has given this event a rating of 9","date":"1349025688000","image":"resources\/0\/images\/user_image.png","media":null},{"id":"28","event_id":"28","entity_id":"24","name":"Felix Nwaobasi","stream_type":"RATING","comment":"Felix Nwaobasi has given this event a rating of 9","date":"1349025651000","image":"resources\/10000\/images\/img20111213083907.png","media":null},{"id":"25","event_id":"28","entity_id":"24","name":"Felix Nwaobasi","stream_type":"VIDEO","comment":"Felix Nwaobasi has added a video at this event","date":"1348730791000","image":"resources\/10000\/images\/img20111213083907.png","media":"resources\/events\/event28\/stream\/video\/img20120927072631.3gp"},{"id":"24","event_id":"28","entity_id":"24","name":"Felix Nwaobasi","stream_type":"PHOTO","comment":"Felix Nwaobasi has added a photo at this event","date":"1348729719000","image":"resources\/10000\/images\/img20111213083907.png","media":"resources\/events\/event28\/stream\/photo\/img20120927070839.jpg"},{"id":"23","event_id":"28","entity_id":"24","name":"Felix Nwaobasi","stream_type":"PHOTO","comment":"Felix Nwaobasi has added a photo at this event","date":"1348724894000","image":"resources\/10000\/images\/img20111213083907.png","media":"resources\/events\/event28\/stream\/photo\/img20120927054814.jpg"},{"id":"22","event_id":"28","entity_id":"24","name":"Felix Nwaobasi","stream_type":"COMMENT","comment":"Fucking finally man!","date":"1348724592000","image":"resources\/10000\/images\/img20111213083907.png","media":null},{"id":"21","event_id":"28","entity_id":"24","name":"Felix Nwaobasi","stream_type":"COMMENT","comment":"More","date":"1348713869000","image":"resources\/10000\/images\/img20111213083907.png","media":null},{"id":"20","event_id":"28","entity_id":"24","name":"Felix Nwaobasi","stream_type":"COMMENT","comment":"I'm hungry. Where's the food?","date":"1348713751000","image":"resources\/10000\/images\/img20111213083907.png","media":null},{"id":"19","event_id":"28","entity_id":"24","name":"Felix Nwaobasi","stream_type":"COMMENT","comment":"She's back!","date":"1348713528000","image":"resources\/10000\/images\/img20111213083907.png","media":null},{"id":"18","event_id":"28","entity_id":"24","name":"Felix Nwaobasi","stream_type":"COMMENT","comment":"I love this Ciara song!!","date":"1348713486000","image":"resources\/10000\/images\/img20111213083907.png","media":null},{"id":"17","event_id":"28","entity_id":"24","name":"Felix Nwaobasi","stream_type":"COMMENT","comment":"What the hell?","date":"1348713260000","image":"resources\/10000\/images\/img20111213083907.png","media":null},{"id":"16","event_id":"28","entity_id":"24","name":"Felix Nwaobasi","stream_type":"COMMENT","comment":"We had an error","date":"1348713159000","image":"resources\/10000\/images\/img20111213083907.png","media":null},{"id":"15","event_id":"28","entity_id":"24","name":"Felix Nwaobasi","stream_type":"COMMENT","comment":"Test comment. Local though.","date":"1348713092000","image":"resources\/10000\/images\/img20111213083907.png","media":null},{"id":"13","event_id":"28","entity_id":"24","name":"Felix Nwaobasi","stream_type":"ARRIVE","comment":"Felix Nwaobasi has entered the event","date":"1348520516000","image":"resources\/10000\/images\/img20111213083907.png","media":null}],"friends":[{"entity_id":"24","name":"Felix Nwaobasi","entity_type":"1","image":"resources\/10000\/images\/img20111213083907.png"}],"success":true}
	 *
	 */
	public function getLocationChatStream() {
		$json = json_decode(file_get_contents('php://input'), true);
		$requiredFields = array('id', 'business_id', 'type');
		if($this->areValuesSet($json, $requiredFields)) {

			$this->load->model('Checkin', 'checkin');
			$this->load->model('Location_chat', 'loc_chat');
			$chatData = array();

			if(($json['type'] & Location_chat::UPDATE_MESSAGE) > 0)
				$chatData["stream"] = $this->loc_chat->getMessages($json);
			if(($json['type'] & Location_chat::UPDATE_PARTICIPANT) > 0)
				$chatData['participants'] = $this->checkin->getAllEntitiesFromLocationChat($json['id'], $json['business_id'], checkin::TYPE_BUSINESS, isset($json['last_participant_update']) ? $json['last_participant_update']/1000 : 0, false);
			if(($json['type'] & Location_chat::UPDATE_REVEAL) > 0)
				$chatData['reveal'] = $this->checkin->getSpecificEntityFromLocationChat($json['id'], $json['business_id'], checkin::TYPE_BUSINESS, $json['participant_id'], false);
			echo $this->createSuccessJSON($chatData);
		} else
			echo $this->createErrorJSON(errorcode::MISSING_DATA, $this->errorcode->getErrorMessage(errorcode::MISSING_DATA));

	}

	public function notifyLocationChatParticipants() {
		$json = json_decode(file_get_contents('php://input'), true);
		$requiredFields = array('id', 'business_id', 'type');
		if($this->areValuesSet($json, $requiredFields) ) {
			$this->load->model('Checkin', 'checkin');
			$this->load->model('Location_chat', 'loc_chat');
			if(count($participants = ($json['type'] & Location_chat::UPDATE_REVEAL) == 0 ? $this->checkin->getAllEntitiesFromLocationChat($json['id'], $json['business_id'], checkin::TYPE_BUSINESS) :
					$this->checkin->getCertainEntitiesFromLocationChat($json['id'], $json['business_id'], checkin::TYPE_BUSINESS, $json['ids'])) > 0)	 {


				$this->load->library('pushmanager');
				$entityPartition = $this->pushmanager->partitionPhoneUsers($participants);
				$contentArray = array();
				if(($json['type'] & Location_chat::UPDATE_PARTICIPANT) > 0) {
					$contentArray['type'] = $json['type'];
					$contentArray['id'] = $json['id'];
					$contentArray['checked_in'] = $json['checked_in'];
				} else if(($json['type'] & Location_chat::UPDATE_REVEAL) > 0) {
					$contentArray['type'] = $json['type'];
					$contentArray['id'] = $json['id'];
				} else
					$contentArray['type'] = $json['type'];

				$this->pushmanager->sendMessage(array(constants::PARAM_COLLAPSE_KEY=>pushmanager::KEY_LOCATION_CHAT, constants::PARAM_DATA=>array(pushmanager::MSG_TYPE=>pushmanager::KEY_LOCATION_CHAT, pushmanager::MSG_CONTENT=>json_encode($contentArray)), constants::PARAM_DELAY_WHILE_IDLE=>true, constants::PARAM_REGISTRATION_IDS=>$entityPartition["ANDROID"]), "");
			}
			//print_r($participants);
			echo $this->createSuccessJSON(array());
		} else
			echo $this->createErrorJSON(errorcode::MISSING_DATA, $this->errorcode->getErrorMessage(errorcode::MISSING_DATA));

	}

	/**
	 * Sample input:
	 * {"title":"No Foreplay","id":24,"priority":"1","completed":false}
	 *
	 * Sample output:
	 * {"bucket_list_item":{"id":"25","entity_id":"24","title":"No Foreplay","priority":"1","completed":"0", "image":"","date_updated":"1365659667000"},"success":true}
	 *
	 */
	public function createBucketListItem() {
		$json = json_decode($this->input->post('string'), true);
		$requiredFields = array('id', 'title', 'priority', 'completed');
		if($this->areValuesSet($json, $requiredFields)) {

			if(count($_FILES) > 0) {
				$this->load->library('upload');  // NOTE: always load the library outside the loop
				$this->load->library('util');
					
				if(!file_exists($dir = self::RESOURCE_FOLDER . $json['id'] . "/bucket_list/"))
					mkdir($dir, 0755, true);
				$filePath = pathinfo($_FILES['file_0']['name']);
					
				$config = array('file_name'=>"img" . date('YmdHis', time()) . "." . $filePath['extension'], 'allowed_types' => "*", 'upload_path' => $dir,
						'max_size' => '0', 'overwrite' => FALSE);
				$this->upload->initialize($config);
				if($this->upload->do_upload("file_0")) {
					$filename = $config['upload_path'] . $config['file_name'];
					$json['image'] = $filename;
				}
			}

			$this->load->model('Bucket_list_item', 'bucket_list');
			$json['entity_id'] = $json['id'];
			unset($json['id']);

			$this->db->trans_start();
			$bucket_list_item = $this->bucket_list->insertNewItem($json);
			if(isset($json['tags'])) {
				$this->load->model('Tag', 'tag');
				$this->tag->modifyTags($bucket_list_item['id'], 'bucket_list_item', $json['tags'], null);
				$bucket_list_item['tags'] = $this->tag->getBucketListTags($bucket_list_item['id']);
			}

			$this->db->trans_complete();

			if ($this->db->trans_status() === FALSE) {
				if(isset($json['image']))
					unlink($json['image']);
				echo $this->createErrorJSON(errorcode::BUCKET_LIST_NOT_CREATED, $this->errorcode->getErrorMessage(errorCode::BUCKET_LIST_NOT_CREATED));
			} else
				echo $this->createSuccessJSON(array("bucket_list_item"=>$bucket_list_item));
		} else
			echo $this->createErrorJSON(errorcode::MISSING_DATA, $this->errorcode->getErrorMessage(errorcode::MISSING_DATA));

	}
	/**
	 * Sample input:
	 * {"id":24, "bucket_list_item_id":26, "title":"New Priority" "priority":2}
	 *
	 * Sample output:
	 * {"bucket_list_item":{"id":"26","entity_id":"24","title":"New Priority","priority":"2","completed":"0","image":"","date_updated":"1366945885000","tags":[]},"success":true}
	 *
	 */
	public function editBucketListItem() {
		$json = json_decode($this->input->post('string'), true);
		$requiredFields = array('id', 'bucket_list_item_id');
		if($this->areValuesSet($json, $requiredFields)) {

			$this->load->model('Photo', 'photo');
			//modify photos if necessary
			if(count($_FILES) > 0) {
				$this->load->library('upload');  // NOTE: always load the library outside the loop
				$this->load->library('util');
					
				if(!file_exists($dir = self::RESOURCE_FOLDER . $json['id'] . "/bucket_list/"))
					mkdir($dir, 0755, true);
				$filePath = pathinfo($_FILES['file_0']['name']);
					
				$config = array('file_name'=>"img" . date('YmdHis', time()) . "." . $filePath['extension'], 'allowed_types' => "*", 'upload_path' => $dir,
						'max_size' => '0', 'overwrite' => FALSE);
				$this->upload->initialize($config);
				if($this->upload->do_upload("file_0")) {
					$filename = $config['upload_path'] . $config['file_name'];
					$json['image'] = $filename;
				}
			} else if(array_key_exists('image', $json) && !isset($json['image'])) {
				//TODO delete the image from the server
			}
			//actually modify bucket list;
			$this->load->model('Bucket_list_item', 'bucket_list');
			$this->db->trans_start();
			$bucketListItem = $this->bucket_list->editInfo($json);
			$this->db->trans_complete();
			if ($this->db->trans_status() === FALSE) {
				if(isset($filename))
					unlink($filename);
				echo $this->createErrorJSON(errorcode::UPDATE_BUCKET_LIST_FAILED, $this->errorcode->getErrorMessage(errorcode::UPDATE_BUCKET_LIST_FAILED));
			}
			else
				echo $this->createSuccessJSON(array("bucket_list_item"=>$bucketListItem));
		} else
			echo $this->createErrorJSON(errorcode::MISSING_DATA, $this->errorcode->getErrorMessage(errorcode::MISSING_DATA));
	}


	/**
	 * Sample input:
	 * {"id":24, "last_id": 22, "last_updated":1365562252999 }
	 *
	 * Sample output:
	 * {"bucket_list_items":[{"id":"23","entity_id":"24","title":"No Foreplay","priority":"0","completed":"0","image":"resources\/24\/bucket_list\/img20130410024705.png","date_updated":"1365562025000","tags":[{"tag_id":"16","alias":"EAT","name":"Eating"},{"tag_id":"32","alias":"GAMING","name":"Gaming"},{"tag_id":"21","alias":"KAYAK","name":"Kayaking"},{"tag_id":"15","alias":"MOVIES","name":"Movies"},{"tag_id":"10","alias":"SKYDIVE","name":"Skydiving"},{"tag_id":"4","alias":"SOCCER","name":"Soccer"}]},{"id":"24","entity_id":"24","title":"Straight Sex","priority":"4","completed":"0","image":"resources\/24\/bucket_list\/img20130410025054.png","date_updated":"1365562254000","tags":[{"tag_id":"16","alias":"EAT","name":"Eating"},{"tag_id":"32","alias":"GAMING","name":"Gaming"},{"tag_id":"21","alias":"KAYAK","name":"Kayaking"},{"tag_id":"15","alias":"MOVIES","name":"Movies"},{"tag_id":"10","alias":"SKYDIVE","name":"Skydiving"},{"tag_id":"4","alias":"SOCCER","name":"Soccer"}]},{"id":"25","entity_id":"24","title":"Back of this Mercedes","priority":"5","completed":"0","image":"","date_updated":"1365659667000","tags":[]},{"id":"26","entity_id":"24","title":"Half On A Baby","priority":"6","completed":"0","image":"","date_updated":"1365659904000","tags":[]},{"id":"29","entity_id":"24","title":"Straight to It!","priority":"1","completed":"0","image":"","date_updated":"1365664634000","tags":[]}],"success":true}
	 */
	public function getBucketListItems() {
		$json = json_decode(file_get_contents('php://input'), true);
		$requiredFields = array('id');
		if($this->areValuesSet($json, $requiredFields) ) {
			$this->load->model('Bucket_list_item', 'bucket_list');
			echo $this->createSuccessJSON(array("bucket_list_items"=>$this->bucket_list->fetchBucketListItems($json)));
		} else
			echo $this->createErrorJSON(errorcode::MISSING_DATA, $this->errorcode->getErrorMessage(errorcode::MISSING_DATA));
	}

	public function prioritizeBucketList() {
		$json = json_decode(file_get_contents('php://input'), true);
		$requiredFields = array('id', 'priorities');
		if($this->areValuesSet($json, $requiredFields) ) {
			$this->load->model('Bucket_list_item', 'bucket_list');

			$this->db->trans_start();
			$bucketListItem = $this->bucket_list->manuallyPrioritizeItems($json['id'], $json['priorities']);
			$this->db->trans_complete();
			if ($this->db->trans_status() === FALSE) {
				echo $this->createErrorJSON(errorcode::UPDATE_BUCKET_LIST_FAILED, $this->errorcode->getErrorMessage(errorcode::UPDATE_BUCKET_LIST_FAILED));
			}
			else
				echo $this->createSuccessJSON(array());
		} else
			echo $this->createErrorJSON(errorcode::MISSING_DATA, $this->errorcode->getErrorMessage(errorcode::MISSING_DATA));
	}

	/**
	 * Sample input:
	 * {"id":24, "lat" : 40.819355010986, "lon": -73.937461853027, "source": "CELL"}
	 *
	 * Sample outputs:
	 * {"potential_checkins":[{"place_id":"28","type":"EVENT","place_type":"2","name":"House Warming","secondary_place_id":null,"secondary_name":null,"secondary_type":null,"lat":"40.818993","lon":"-73.94047","image":"resources\/events\/event28\/img20120921223307.jpg","distance":"256.3957206726275"},{"place_id":"55","type":"BUSINESS","place_type":"1","name":"McDonald's","secondary_place_id":null,"secondary_name":null,"secondary_type":null,"lat":"40.81787","lon":"-73.941303","image":"resources\/55\/images\/img20130216023304.jpg","distance":"363.0832997539609"}],"success":true}
	 *
	 */
	public function getPotentialCheckIns() {
		$json = json_decode(file_get_contents('php://input'), true);
		$requiredFields = array('id', 'lat', 'lon', 'source');
		if($this->areValuesSet($json, $requiredFields)) {
			$this->load->model('Checkin', 'checkin');
			echo $this->createSuccessJSON(array("potential_checkins"=>$this->checkin->fetchPotentialCheckIns($json['id'], $json['lat'], $json['lon'], $json['source'])));
		} else
			echo $this->createErrorJSON(errorcode::MISSING_DATA, $this->errorcode->getErrorMessage(errorcode::MISSING_DATA));

	}

	/**
	 * Sample input:
	 * {"id":24, "flag": 1, "lat" : 40.819355010986, "lon": -73.937461853027, "source": "CELL"}
	 *
	 *
	 * Sample output:
	 * {"checkins":[{"place_id":"27","type":"EVENT","place_type":"2","name":"WebMD Redecoration","lat":"40.740657","lon":"-74.002089","address":"111 8th Ave, New York, NY 10011, USA","count":"223","total_time":"0","last_checkin_time":"1369749347000","image":"resources\/events\/event27\/img20120921222242.png"},{"place_id":"28","type":"EVENT","place_type":"2","name":"House Warming","lat":"40.818993","lon":"-73.94047","address":"","count":"192","total_time":"0","last_checkin_time":"1369788087000","image":"resources\/events\/event28\/img20120921223307.jpg"},{"place_id":"54","type":"BUSINESS","place_type":"1","name":"WebMD","lat":"40.7406578","lon":"-74.0020894","address":"111 8th Ave, New York, NY 10011, USA","count":"45","total_time":"0","last_checkin_time":"1369749496000","image":"resources\/54\/images\/img20130215182730.png"},{"place_id":"55","type":"BUSINESS","place_type":"1","name":"McDonald's","lat":"40.81787","lon":"-73.941303","address":"2379 Adam Clayton Powell Jr Blvd, New York, NY 10030, USA","count":"38","total_time":"0","last_checkin_time":"1369745996000","image":"resources\/55\/images\/img20130216023304.jpg"},{"place_id":"29","type":"EVENT","place_type":"2","name":"Dance Lessons","lat":"42.3057484","lon":"-71.0654195","address":"","count":"25","total_time":"0","last_checkin_time":"1349582449000","image":"resources\/events\/event29\/img20121005220517.jpg"},{"place_id":"30","type":"EVENT","place_type":"2","name":"Roast Of @GPL","lat":"42.2732333","lon":"-71.0678064","address":"","count":"11","total_time":"0","last_checkin_time":"1349581845000","image":"resources\/events\/event30\/img20121006222435.jpg"},{"place_id":"43","type":"BUSINESS","place_type":"1","name":"MTV","lat":"40.7576068","lon":"-73.9861364","address":"1515 Broadway, New York, NY 10036, USA","count":"9","total_time":"0","last_checkin_time":"1368734848000","image":"resources\/10013\/images\/img20120201041800.png"},{"place_id":"44","type":"BUSINESS","place_type":"1","name":"WPI","lat":"42.2732046","lon":"-71.8084002","address":"100 Institute Road, Worcester, MA 01609, USA","count":"8","total_time":"0","last_checkin_time":"1366476645000","image":"resources\/10014\/images\/img20120201042335.png"},{"place_id":"56","type":"BUSINESS","place_type":"1","name":"McDonald's","lat":"40.8222148","lon":"-73.9531589","address":"3410 Broadway, New York, NY 10031, USA","count":"4","total_time":"0","last_checkin_time":"1363573043000","image":"resources\/56\/images\/img20130217022520.jpg"},{"place_id":"45","type":"BUSINESS","place_type":"1","name":"CNN","lat":"40.7682847","lon":"-73.9822985","address":"10 Columbus Circle, New York, NY 10023, USA","count":"3","total_time":"0","last_checkin_time":"1368751214000","image":"resources\/10015\/images\/img20120201044851.png"},{"place_id":"37","type":"EVENT","place_type":"2","name":"New Event 2","lat":"40.818926","lon":"-73.940472","address":"","count":"1","total_time":"0","last_checkin_time":"1362724027000","image":""}],"success":true}
	 */
	public function getCheckIns() {
		$json = json_decode(file_get_contents('php://input'), true);
		$requiredFields = array('id', 'flag');
		if($this->areValuesSet($json, $requiredFields)) {
			$this->load->model('Checkin', 'checkin');

			$checkIns = $this->checkin->fetchCheckInsOfType($json);

			if (!$this->errorcode->isError($checkIns))
				echo $this->createSuccessJSON(array("checkins"=>$checkIns));
			else
				echo $this->createErrorJSON($checkIns, $this->errorcode->getErrorMessage($checkIns));
		} else
			echo $this->createErrorJSON(errorcode::MISSING_DATA, $this->errorcode->getErrorMessage(errorcode::MISSING_DATA));
	}

	/**
	 * 
	 * Sample input:
	 * {"id":24, "place_id": -1, "place_type":1, "lat" : 40.819355010986, "lon": -73.937461853027, "source": "CELL"}
	 * 
	 * Sample output:
	 * {"location_info":{"checked_into":{"place_id":"55","type":"BUSINESS","place_type":"1","name":"McDonald's","secondary_place_id":null,"secondary_name":null,"secondary_type":null,"lat":"40.81787","lon":"-73.941303","hashtag":"","image":"resources\/55\/images\/img20130216023304.jpg","distance":"363.0832997539609","count":"43","last_checkin_time":1372895921000}},"success":true}
	 * 
	 * 
	 */
	public function changeCheckInStatus() {
		$json = json_decode(file_get_contents('php://input'), true);
		$requiredFields = array('id', 'place_type', 'place_id', 'checking_in', 'lat', 'lon', 'source');
		if($this->areValuesSet($json, $requiredFields)) {
			$this->load->model('Checkin', 'checkin');
			$this->load->model('Person_info', 'info');
			$checkInResults = array();
			$success;

			if(($currCheckIn = $this->info->getCurrentCheckIn($json['id'])) != NULL)
				$currCheckIn = $currCheckIn['current_checkin'];
				
				
			if(!$json['checking_in']) {
				if($currCheckIn != NULL) {
					$checkInRow = $this->checkin->getCheckIn($currCheckIn);
					//if we're currently checked into where we're trying to check out of, then check us out, else just return true
					$success =  ($checkInRow['place_type'] == $json['place_type'] && $checkInRow['place_id'] == $json['place_id']) ? $this->info->updateCurrentCheckIn($json['id'], $currCheckIn, NULL) : true;
					$checkInResults['checked_out'] = array("place_id"=>$json['place_id'], "type"=>($json['place_type'] == 1 ? "BUSINESS" : "EVENT"), "place_type"=>$json['place_type']);
				}
			} else {
				
				$requestedCheckIn = $this->checkin->fetchPotentialCheckIns($json['id'], $json['lat'], $json['lon'], $json['source'], $json['place_id'], $json['place_type']);
				$requestedCheckIn = $requestedCheckIn[0];
				if($currCheckIn != null)
					$checkInRow = $this->checkin->getCheckIn($currCheckIn);

				if($json['place_id'] == $checkInRow['place_id'] && $json['place_type'] == $checkInRow['place_type']) {

					$requestedCheckIn['count'] = $checkInRow['count'];
					$requestedCheckIn['last_checkin_time'] = strtotime($checkInRow['last_checkin_time']) * 1000;
					$checkInResults['checked_into'] = $requestedCheckIn;
					$success = true;
				} else if(($checkinId = $this->checkin->insertOrUpdateCheckIn($json['id'], $requestedCheckIn))) {
					if($success = $this->info->updateCurrentCheckIn($json['id'], -1, $checkinId)) //update person info
					$checkInResults['checked_into'] = $requestedCheckIn;
				}
			}

			if ($success)
				echo $this->createSuccessJSON(array("location_info"=>$checkInResults));
			else
				echo $this->createErrorJSON(errorcode::CHECKIN_NOT_UPDATED, $this->errorcode->getErrorMessage(errorcode::CHECKIN_NOT_UPDATED));
		} else
			echo $this->createErrorJSON(errorcode::MISSING_DATA, $this->errorcode->getErrorMessage(errorcode::MISSING_DATA));
	}

	/**
	 * Sample input:
	 * {"id":24,"place_type":2,"place_id":27}
	 * 
	 * {"gatherable_info":{"tags":[{"rank":"1","title":"business fields","id":"3","name":"Entertainment"},{"rank":"1","title":"business fields","id":"22","name":"News"},{"rank":"2","title":"business environment","id":"2","name":"Professional"},{"rank":"2","title":"business environment","id":"4","name":"Suit & Tie"}],"friends":[{"entity_id":"24","name":"Kachi Nwaobasi","entity_type":"1","image":"resources\/24\/images\/img20130204001156.jpg","is_personal":"1"}]},"success":true}
	 */
	public function getGatherableInfo() {
		$json = json_decode(file_get_contents('php://input'), true);
		$requiredFields = array('id', 'place_id', 'place_type');
		if($this->areValuesSet($json, $requiredFields)) {
			$this->load->model('Checkin', 'checkin');
			$this->load->model('Tag', 'tag');
			$info = array();
			if($json['place_type'] == 1 || $json['place_type'] == checkin::TYPE_BUSINESS) {
				$tagArray = $this->tag->getBusinessTags($json['place_id'], array('business_field', 'business_env'));				
				$friendArray = $this->checkin->getAllEntitiesCheckedInAtPlace($json['id'], $json['place_id'], checkin::TYPE_BUSINESS, false);
			} else if($json['place_type'] == 2 || $json['place_type'] == checkin::TYPE_EVENT) {
				$tagArray = $this->tag->getEventTags($json['place_id']);
				$friendArray = $this->checkin->getAllEntitiesCheckedInAtPlace($json['id'], $json['place_id'], checkin::TYPE_EVENT, false);					
			}
			
			echo $this->createSuccessJSON(array("gatherable_info"=>array("tags"=>$tagArray, "friends"=>$friendArray)));
		} else
			echo $this->createErrorJSON(errorcode::MISSING_DATA, $this->errorcode->getErrorMessage(errorcode::MISSING_DATA));
	}

	public function linkSocialNetwork() {
		$json = json_decode(file_get_contents('php://input'), true);
		$requiredFields = array('id', 'type');
		if($this->areValuesSet($json, $requiredFields)) {
			$this->load->model('Entity', 'entity');
			$this->entity->modifySocialNetworkId($json['id'], $json['type'], $json['user_id']);
			echo $this->createSuccessJSON(array());
		} else
			echo $this->createErrorJSON(errorcode::MISSING_DATA, $this->errorcode->getErrorMessage(errorcode::MISSING_DATA));
	}
	
	public function sendDummyPush() {
		$this->load->model('Event', 'event');
		$this->event->hasActiveEvents(24, true);

		//echo $this->createSuccessJSON(array("attendees"=>$this->checkin->getSpecificEntityFromLocationChat(24, 54, checkin::TYPE_BUSINESS, 54, false)));
		//function getAllEntitiesFromLocationChat($entity_id, $place_id, $place_type_alias, $updated_time = 0, $includeReg = true)
		//echo $this->createSuccessJSON(array("attendees"=>$this->checkin->getCertainEntitiesCheckedInAtPlace(24, 54, checkin::TYPE_BUSINESS, array(25, 24, 38, 41, 26, 40, 39), false)));
		//echo $this->createSuccessJSON(array("attendees"=>$this->checkin->getAllEntitiesFromLocationChat(24, 54, checkin::TYPE_BUSINESS, array(25, 24, 38, 41, 26, 40, 39), false)));
		//echo $this->createSuccessJSON(array("attendees"=>$this->checkin->getCertainEntitiesFromLocationChat(24, 54, checkin::TYPE_BUSINESS, array(25, 24, 38, 41, 26, 40, 39), false)));
		//echo $this->createSuccessJSON(array("attendees"=>$this->checkin->getSpecificEntityFromLocationChat(24, 54, checkin::TYPE_BUSINESS, 55, false)));
		//$this->load->library('pushmanager');
		//var_dump($this->pushmanager->replaceRegistrationId("ABCDEFG", "ABCDEFGH", "ANDROID"));
		//$this->pushmanager->deleteRegistrationIdWithRegId("ABCDEFGH");
		//$this->pushmanager->sendDummyMessage(array(constants::PARAM_COLLAPSE_KEY=> "new notification", constants::PARAM_DATA=>array("message"=>"Felix is the man!"), constants::PARAM_DELAY_WHILE_IDLE=>true, ), "");
	}

	public function areValuesSet($sourceArray, $arrayOfValues) {
		foreach ($arrayOfValues as $string) {
			if(!isset($sourceArray[$string]))
				return false;
		}
		return true;
	}

	public function sendAutomaticEmail() {
		$json = json_decode($this->input->post('string'), true);
		$requiredFields = array('id', 'email', 'title', 'type');
		if($this->areValuesSet($json, $requiredFields) ) {
			$this->load->library('upload');
			$filenames = array();

			for($i=0; $i<count($_FILES); $i++) {
				$dir = self::RESOURCE_FOLDER . $json['id'] . "/" .  $json['type'] . "/";
				$filePath = pathinfo($_FILES['file_' . $i]['name']);
				if(!file_exists($dir))
					mkdir($dir, 0755, true);
				$config = array('file_name'=>$filePath['basename'], 'allowed_types' => "*", 'upload_path' => $dir,
						'max_size' => '0', 'overwrite' => FALSE);
					
				$this->upload->initialize($config);
				if($this->upload->do_upload("file_" . $i)) {
					//TODO UH! do something when files fail. for all file uploading. register, event creation, live stream, and message sending
					$filenames[$i] = $config['upload_path'] . $config['file_name'];
				} else {
					echo $this->createErrorJSON(errorcode::FILE_NOT_UPLOADED, $this->errorcode->getErrorMessage(errorcode::FILE_NOT_UPLOADED));
					return;
				}
			}


			$this->load->library('mailer');
			switch($json['type']) {
				case mailer::GUESTLIST:
					$success = $this->mailer->sendGuestList($json['email'], $json['title'], $filenames);
					foreach($filenames as $file)
						unlink($file);
					break;
			}

			if($success)
				echo $this->createSuccessJSON(array());
			else
				echo $this->createErrorJSON(errorcode::MAIL_NOT_SENT, $this->errorcode->getErrorMessage(errorcode::MAIL_NOT_SENT));

		} else
			echo $this->createErrorJSON(errorcode::MISSING_DATA, $this->errorcode->getErrorMessage(errorcode::MISSING_DATA));
	}

	/*
	 * iVBORw0KGgoAAAANSUhEUgAAABwAAAASCAMAAAB/2U7WAAAABlBMVEUAAAD///+l2Z/dAAAASUlEQVR4XqWQUQoAIAxC2/0vXZDrEX4IJTRkb7lobNUStXsB0jIXIAMSsQnWlsV+wULF4Avk9fLq2r8a5HSE35Q3eO2XP1A1wQkZSgETvDtKdQAAAABJRU5ErkJggg==
	* $data = 'iVBORw0KGgoAAAANSUhEUgAAABwAAAASCAMAAAB/2U7WAAAABl'
	. 'BMVEUAAAD///+l2Z/dAAAASUlEQVR4XqWQUQoAIAxC2/0vXZDr'
	. 'EX4IJTRkb7lobNUStXsB0jIXIAMSsQnWlsV+wULF4Avk9fLq2r'
	. '8a5HSE35Q3eO2XP1A1wQkZSgETvDtKdQAAAABJRU5ErkJggg==';

	* returns filename if it exists, else returns false
	*/
	public function addPhoto($dir, $base64, $prefix = "") {
		$new_filename = "";
		if($data = base64_decode($base64)) {
			$im = imagecreatefromstring($data);
			if($im != false) {
				$dirName = self::RESOURCE_FOLDER . $dir . "/images";
				if(!file_exists($dirName))
					mkdir($dirName, 0755, true);
				imagepng($im, $filename = tempnam('temp', 'img'));
				rename($filename, $new_filename = $dirName . "/" . $prefix . "img" . date('YmdHis', time()) . image_type_to_extension(exif_imagetype($filename)));
				chmod($new_filename, 0755);
				imagedestroy($im);
			}

		}
		return (file_exists($new_filename)) ? $new_filename : false;
	}


	public function createSuccessJSON($array) {
		$json = $array;
		$json['success'] = true;
		return json_encode($json);
	}

	public function createErrorJSON($errorNo, $errorMessage) {
		$json = array("success"=>false, "errorCode"=>$errorNo, "message"=>$errorMessage);
		return json_encode($json);
	}
}
?>