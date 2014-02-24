<?php

class Api extends CI_Controller {

	const RESOURCE_FOLDER = "resources/";
	const DEFAULT_RESOURCE = "resources/0/";
	const USER_RESOURCE_FOLDER = "resources/users/user-%d/";
	const GROUP_CHAT_RESOURCE_FOLDER = "resources/groups/group-%d/";

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
			$user = $this->user->register($json);
			$profile_image = '';
			if(!$this->errorcode->isError($user)) {
				$user['image'] = '';
	
				if(count($_FILES) > 0) {
					$this->load->library('upload');
					$this->load->library('util');
					$dirName = sprintf(self::USER_RESOURCE_FOLDER, $user['id']) . "/images" . "/";
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
					echo $this->createErrorJSON(errorcode::REGISTRATION_FAILED);
				else
					echo $this->createSuccessJSON(array("user"=>$user, "configData"=>array(), "chats"=>array()));
			} else {
				echo $this->createErrorJSON($user, $this->errorcode->getErrorMessage($user));
				$this->db->trans_complete();
			}
		}
		else
			echo $this->createErrorJSON(errorcode::MISSING_DATA);
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
				echo $this->createErrorJSON($user);
		} else
			echo $this->createErrorJSON(errorcode::MISSING_DATA);
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
			echo $this->createErrorJSON(errorcode::MISSING_DATA);

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
			echo $this->createErrorJSON(errorcode::MISSING_DATA);
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
					$user['image'] = '';//$this->util->gsetDefaultImage($user['broad_user_type']);
					if($profile_image = $this->photo->getProfilePicture($user['id']))
						$user['image'] = $profile_image;
					echo $this->createSuccessJSON(array("entity"=>$user, "configData"=>array("actions"=>$this->createActionsJSON($user['entity_type']), "datatypes"=>$this->getAllDatatypes(), "moods"=>$this->getAllMoods(), "rsvps"=>$this->getAllRsvps())));
				} else
					echo $this->createErrorJSON($user, $this->errorcode->getErrorMessage($user));
			} else {
				echo $this->createErrorJSON($success, $this->errorcode->getErrorMessage($success));
			}
		} else
			echo $this->createErrorJSON(errorcode::MISSING_DATA);
		
	}

	/**
	 * Sample input:
	 * {"name":"GJK", "creator_id":4}
	 * 
	 * Sample output:
	 * {"group":{"id":"9","name":"GJK Back","creator_id":"4","first_name":"Kachi","last_name":"Nwaobasi","image":"resources\/groups\/group-9\/img20140224010119.png"},"success":true}
	 */
	
	public function createGroup() {		
		$requiredFields = array('name', 'creator_id');
		$json = json_decode($this->input->post('string'), true);

		if($this->areValuesSet($json, $requiredFields)) {
			$this->load->model('Group_chat', 'group');
			$this->load->model('Group_chat_member', 'member');

			$this->db->trans_start();
			$group = $this->group->create($json); //create the actual group
			$this->member->add($group['id'], array($group['creator_id'])); //add the group creator to the group
			$this->db->trans_complete();
			
			if ($this->db->trans_status() === FALSE) {
				echo $this->createErrorJSON(errorcode::GROUP_NOT_CREATED);
			} else {	
				$group['image'] = "";
				if(count($_FILES) > 0) {
					$this->load->library('upload');  // NOTE: always load the library outside the loop
					$this->load->library('util');
	
					mkdir($dir = sprintf(self::GROUP_CHAT_RESOURCE_FOLDER, $group['id']), 0755, true);
					$filePath = pathinfo($_FILES['file_0']['name']);
	
					$config = array('file_name'=>"img" . date('YmdHis', time()) . "." . $filePath['extension'], 'allowed_types' => "*", 'upload_path' => $dir,
							'max_size' => '0', 'overwrite' => FALSE);
					$this->upload->initialize($config);
					if($this->upload->do_upload("file_0")) {
						$filename = $config['upload_path'] . $config['file_name'];
						if($this->group->update($group['id'], array('image'=>$filename)))
							$group['image'] = $filename;
						else
							unlink($filename);
					}	
				}
		
				echo $this->createSuccessJSON(array("group"=>$group));
			} 
		} else
			echo $this->createErrorJSON(errorcode::MISSING_DATA);
		
	}
	
	
	/**
	 * 
	 * Sample input:
	 * {"group_id": 5}
	 * 
	 * Sample output:
	 * {"success":true} (if we were able to successfully remove group or if we tried to remove a group that doesnt exist)
	 *
	 */
	public function removeGroup() {
		$requiredFields = array('group_id');
		$json = json_decode(file_get_contents('php://input'), true);		

		if($this->areValuesSet($json, $requiredFields)) {
			$this->load->model('Group_chat', 'group');
			if($this->group->delete($json['group_id'])) {
				if($this->group->delete($json['group_id']))
					$this->removeDir(sprintf(self::GROUP_CHAT_RESOURCE_FOLDER, $json['group_id']));
				echo $this->createSuccessJSON(array());
			} else
				echo $this->createErrorJSON(errorcode::GROUP_NOT_DELETED);
			
		} else
			echo $this->createErrorJSON(errorcode::MISSING_DATA);
	}
	
	/**
	 * 
	 * Sample input:
	 * {"group_id":5, "recipients": [5]}
	 * 
	 * Sample output:
	 * {"success":true} (if we were able to successfully add all members that we attempted to add)
	 * 
	 */
	public function addMembers() {
		$requiredFields = array('group_id', 'recipients');
		$json = json_decode(file_get_contents('php://input'), true);		
		
		if($this->areValuesSet($json, $requiredFields)) {
			$this->load->model('Group_chat_member', 'member');	
			$this->db->trans_start(); //start adding
			$this->member->add($json['group_id'], $json['recipients']);
			$this->db->trans_complete();
			if ($this->db->trans_status() === FALSE) {
				echo $this->createErrorJSON(errorcode::GROUP_MEMBER_NOT_ADDED);
			} else
				echo $this->createSuccessJSON(array());
			
		} else
			echo $this->createErrorJSON(errorcode::MISSING_DATA);
	}
	
	/**
	 * 
	 * Sample input:
	 * {"group_id":5, "members": [5]}
	 * 
	 * Sample output:
	 * {"success":true} (if we were able to successfully remove members or if we tried to remove a member that wasnt in group)
	 */
	public function removeMembers() {
		$requiredFields = array('group_id', 'members');
		$json = json_decode(file_get_contents('php://input'), true);
		
		if($this->areValuesSet($json, $requiredFields)) {
			$this->load->model('Group_chat_member', 'member');
			$this->db->trans_start(); //start removing
			$remainingCount = $this->member->delete($json['group_id'], $json['members']);
			if($remainingCount == 0) {
				$this->load->model('Group_chat', 'group');
				if($this->group->delete($json['group_id']))
					$this->removeDir(sprintf(self::GROUP_CHAT_RESOURCE_FOLDER, $json['group_id']));
			}
				
			$this->db->trans_complete();
			if ($this->db->trans_status() === FALSE) {
				echo $this->createErrorJSON(errorcode::GROUP_MEMBER_NOT_DELETED);
			} else
				echo $this->createSuccessJSON(array());
				
		} else
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

	/**
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
	
	public function removeDir($dirname) {
		if(!is_dir($dirname))
			return;
		
		$it = new RecursiveDirectoryIterator($dirname);
		$files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);

		foreach($files as $file) {
			if ($file->getFilename() === '.' || $file->getFilename() === '..')
				continue;
			
			if ($file->isDir())
				rmdir($file->getRealPath());
			else
				unlink($file->getRealPath());
		}
		rmdir($dirname);
	}

	public function createSuccessJSON($array) {
		$json = $array;
		$json['success'] = true;
		return json_encode($json);
	}

	public function createErrorJSON($errorNo) {
		$json = array("success"=>false, "errorCode"=>$errorNo, "message"=>$this->errorcode->getErrorMessage($errorNo));
		return json_encode($json);
	}
}
?>