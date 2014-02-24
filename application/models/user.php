<?php

class User extends CI_Model {
	function __construct() {
		parent::__construct();
		$this->load->library('errorCode'); //used for returning error codes
		$this->load->library('util');
	}

	/*
	 * registers a new user.
	*
	* returns the new person on success, else returns false;
	*/
	function register($array) {

		if($this->isEmailAlreadyPresent($array['email']))
			return errorCode::USER_ALREADY_EXISTS;
		

		$this->addPasswordAndSalt($array, $array['password']);
		//if we can insert the entity in there, then try to insert *_info. else return registration error.
	
		if($this->db->insert('user', $array)) {
			$query = $this->db->select('id, email, first_name, last_name, bio', FALSE)->from('user')->where('email', $array['email'])->limit(1)->get();	
			
			if($query->num_rows() > 0)
				return $query->row_array();
			 else
				return errorCode::logError(errorCode::ERROR_DATABASE, $this->db->_error_number(), $this->db->_error_message(), "user");
		} else
			return errorCode::logError(errorCode::ERROR_DATABASE, $this->db->_error_number(), $this->db->_error_message(), "user");
	}

	function login($email, $password) {		
		$result = $this->db->select('salt, temp_password')->from('user')->where('email', $email)->limit(1)->get();
		if($result->num_rows() > 0) {
			$row = $result->row();
			
			//if we have a temp password present, assume at first that's what they're logging in with
			if(isset($row->temp_password)) {
				$verify = $this->db->select('id')->from('user')->where(array('email'=>$email, 'temp_password'=>$this->generatePasswordHash($password . $row->salt)))->limit(1)->get();
				if($verify->num_rows() > 0) {
					$user = $verify->row_array();
					$user['reset_password'] = true;
					return $user;
				}
			}
				
			//if we get here, then they werent trying to log in with the temp password. try normal login. maybe they rememebered it
			$verify = $this->db->select('id, email, first_name, last_name, ifnull(image, "") as image, bio', FALSE)->from('user')->where(array('email'=>$email, 'password'=>$this->generatePasswordHash($password . $row->salt)))->limit(1)->get();
			if($verify->num_rows() > 0) {
				$user = $verify->row_array();
				
				if(isset($row->temp_password))
					$this->db->update('user', array('temp_password'=>NULL), array('id'=>$user['id']));
				return $user;
			} else
					return errorCode::INVALID_CRED;
		}

		return errorCode::INVALID_CRED;
	}

	function updatePhoto($id, $photoLocation) {
		return $this->db->update('user', array('image'=>$photoLocation), array('id'=>$id));
	}
	
	function setTempPassword($username) {
		$usernameAsMiluNumber = str_replace("-", "", $username);
		
		$isMiluNumber = is_numeric($usernameAsMiluNumber) && $usernameAsMiluNumber == intval($usernameAsMiluNumber);
		
		$result = $this->db->select('id, email, milu_number, salt')->from('entity')->where($isMiluNumber ? 'milu_number' : 'email', $isMiluNumber ? $usernameAsMiluNumber : $username)->limit(1)->get();
		if($result->num_rows() > 0) {
			$row = $result->row_array();
			$tempPassword = $this->generateTempPassword();
			$secureTemp = $this->generatePasswordHash($tempPassword . $row['salt']);
			$this->db->update('entity', array('temp_password'=>$secureTemp), array('id'=>$row['id']));
			unset($row['id']);
			unset($row['salt']);
			$row['temp_password'] = $tempPassword;
			return $row;
		}
		
		return errorCode::INVALID_USER;
	}
	
	function resetPassword($username, $tempPassword, $newPassword) {
		$usernameAsMiluNumber = str_replace("-", "", $username);
		
		$isMiluNumber = is_numeric($usernameAsMiluNumber) && $usernameAsMiluNumber == intval($usernameAsMiluNumber);
		$usernameKey = $isMiluNumber ? 'milu_number' : 'email';
		$usernameVal = $isMiluNumber ? $usernameAsMiluNumber : $username;
		$result = $this->db->select('salt')->from('entity')->where($usernameKey, $usernameVal)->limit(1)->get();
		if($result->num_rows() > 0) {
			$row = $result->row_array();
			if($this->db->select('id')->from('entity')->where(array($usernameKey=>$usernameVal, 'temp_password'=>$this->generatePasswordHash($tempPassword . $row['salt'])))->get()->num_rows() > 0) {
				$newPassData = array();
				$this->addPasswordAndSalt($newPassData, $newPassword);
				$newPassData['temp_password'] = NULL;
				$this->db->update('entity', $newPassData, array($usernameKey=>$usernameVal));
				return true;
			}
		}
	
		return errorCode::INVALID_CRED;
	
	}
	
		
	function changePassword($id, $oldPassword, $newPassword) {
		$result = $this->db->select('salt')->from('entity')->where('id', $id)->limit(1)->get();
		if($result->num_rows() > 0) {
			$row = $result->row_array();			
			if($this->db->select('id')->from('entity')->where(array('id'=>$id, 'password'=>$this->generatePasswordHash($oldPassword . $row['salt'])))->get()->num_rows() > 0) {
				$newPassData = array();
				$this->addPasswordAndSalt($newPassData, $newPassword);
				$this->db->update('entity', $newPassData, array('id'=>$id));
				return true;
			}
		}
		
		return errorCode::INVALID_CRED;
		
	}

	function addPasswordAndSalt(&$array, $pass) {

		$size = mcrypt_get_iv_size(MCRYPT_CAST_256, MCRYPT_MODE_CFB);
		$salt = base64_encode(mcrypt_create_iv($size, MCRYPT_DEV_RANDOM));
		$array['salt'] = $salt;
		$array['password'] = $this->generatePasswordHash($pass . $salt);
	}

	function generatePasswordHash($passalt) {
		return hash("sha256", $passalt);
	}
	
	function generateTempPassword ($length = 12) {
		$password = "";
		$possible = "123456789abcdefghijklmnpqrtvwxyzABCDFGHJKLMNPQRTVWXYZ!-_";
		$maxlength = strlen($possible);
	
		for ($i=0, $length = min($length, $maxlength); $i < $length;) {
			$char = substr($possible, mt_rand(0, $maxlength-1), 1);
	
			// have we already used this character in $password?
			if (!strstr($password, $char)) {
				$password .= $char;
				$i++;
			}
		}
		return $password;
	}
	
	function getEntityById($id) {
		$result = $this->db->select('entity_type')->from('entity')->where('id', $id)->limit(1)->get();
		if($result->num_rows() > 0) {
			$row = $result->row();
			$this->load->model('Usertype', 'userType');
			if(($tableType = $this->userType->getBroadUserTypeIdById($row->entity_type)) != 0) {
				$modelName = $this->util->getModelName($tableType);
				$this->load->model($modelName, 'info');
				return $this->info->getBasicInfo($id);
			}
		}
			
		return false;
	}

	function isEmailAlreadyPresent($email) {
		$query = $this->db->select()->from('user')->where('email', $email)->get();
		return $query->num_rows() > 0;
	}
	
	function saveRegistrationId($id, $registrationId, $phoneType) {
		$cleanId = $this->db->escape($id);
		$cleanRegistrationId = $this->db->escape($registrationId);
		$cleanPhoneType = $this->db->escape($phoneType);
		$where = "push_registration_id=" . $cleanRegistrationId . " AND phone_type=(select id from phone_type where alias=" . $cleanPhoneType . ")";
		
		$result = $this->db->select('id')->from('entity')->where($where, NULL, FALSE)->limit(1)->get();
		//errorCode::logError(errorCode::ERROR_DATABASE, $this->db->_error_number(), $this->db->_error_message(), "entity");
		if($result->num_rows() > 0) {
			$row = $result->row_array();
			if(!$this->deleteRegistrationId($row['id']))
				return false;
		}
			
		$sqlQuery = 'update entity set push_registration_id='.$cleanRegistrationId. ', phone_type=(select id from phone_type where alias='.$cleanPhoneType . 
		') where id='. $cleanId;
		$this->db->query($sqlQuery);
		$result = $this->db->select('push_registration_id, (select alias from phone_type where id=phone_type) as phone_type', FALSE)->from('entity')->where('id', $id)->limit(1)->get()->row_array();
		return $result['push_registration_id'] == $registrationId && $result['phone_type'] == $phoneType;
	}
	
	function replaceRegistrationId($oldRegistrationId, $newRegistrationId, $phoneType) {
		
		$cleanOldId = $this->db->escape($oldRegistrationId);
		$cleanNewId = $this->db->escape($newRegistrationId);
		$cleanPhoneType = $this->db->escape($phoneType);
		$where = "push_registration_id=" . $cleanOldId . " AND phone_type=(select id from phone_type where alias=" . $cleanPhoneType . ")";

		$result = $this->db->select('id')->from('entity')->where($where, NULL, FALSE)->limit(1)->get();
		//errorCode::logError(errorCode::ERROR_DATABASE, $this->db->_error_number(), $this->db->_error_message(), "entity");
		if($result->num_rows() < 0) {
			return false;
		}
		$row = $result->row_array();
			
		$sqlQuery = 'update entity set push_registration_id='.$cleanNewId. ', phone_type=(select id from phone_type where alias='.$cleanPhoneType .
			') where id='. $row['id'];
		$this->db->query($sqlQuery);
		$result = $this->db->select('push_registration_id, (select alias from phone_type where id=phone_type) as phone_type', FALSE)->from('entity')->where('id', $row['id'])->limit(1)->get()->row_array();
		return $result['push_registration_id'] == $newRegistrationId && $result['phone_type'] == $phoneType;
	}
	
	
	function deleteRegistrationId($id) {
		$data = array('push_registration_id'=>null, 'phone_type'=>null);
		$this->db->update('entity', $data, array('id'=>$id));
		$result = $this->db->select('push_registration_id, phone_type')->from('entity')->where('id', $id)->limit(1)->get()->row_array();
		return !isset($result['push_registration_id']) && !isset($result['phone_type']);  
	}
	
	function deleteRegistrationIdWithRegId($regId) {
		$row = $this->db->select('id')->from('entity')->where('push_registration_id', $regId)->limit(1)->get()->row_array();
		if(count($row) <= 0)
			return false;
		$data = array('push_registration_id'=>null, 'phone_type'=>null);
		$this->db->update('entity', $data, array('push_registration_id'=>$regId));
		$result = $this->db->select('push_registration_id, phone_type')->from('entity')->where('id', $row['id'])->limit(1)->get()->row_array();
		return !isset($result['push_registration_id']) && !isset($result['phone_type']);
	}
	
	function getProfile($id, $tableType) {
		$modelName = $this->util->getModelName($tableType);
		$this->load->model($modelName, 'info');
		return $this->info->getProfileInfo($id);
	}

	function queryEntities($id, $query, $which, $offset = 0, $limit = 20) {
		switch($which) {
			case 1: //person
				$this->load->model('person_info', 'info');
				return $this->info->searchBasicInfo($id, $query, $offset, $limit);
			case 2: //business
				$this->load->model('business_info', 'info');
				return $this->info->searchBasicInfo($id, $query, $offset, $limit);
			case 3: //organization
				$this->load->model('organization_info', 'info');
				return $this->info->searchBasicInfo($id, $query, $offset, $limit);
			default:
				$query = $this->db->escape_like_str($query);
				$id=$this->db->escape($id);
				$this->load->model('Relationship_general', 'rel');
				$sqlQuery = '(select entity_id as id, concat(first_name, " ", last_name) as name, (unix_timestamp(birthday) * 1000) as birthday, lat, lon, ifnull(image, "") as image, entity_type, (select count(*) from relationship_general where ((id_one=entity_id AND id_two=' .$id. ') OR (id_one='. $id . ' AND id_two=entity_id)) AND (state_one =' . Relationship_general::TYPE_REL_PERSONAL. ' OR (id_one=' .$id. ' AND state_one & ' .Relationship_general::TYPE_REL_IMPERSONAL. ' > 0) OR (id_two=' .$id. ' AND state_two & ' .Relationship_general::TYPE_REL_IMPERSONAL. ' > 0))) > 0 as relationship_exists, "PERSON" as  broad_alias from person_info join entity on (person_info.entity_id=entity.id) left join photo_entity on (person_info.entity_id=photo_entity.owner_id AND is_profile=1) left join location_perm on (location_perm.id=person_info.entity_id) where concat(first_name, " ", last_name) like "%' . $query . '%") union all '.
    					 '(select entity_id as id, name, NULL as birthday, lat, lon, ifnull(image, "") as image, entity_type, (select count(*) from relationship_general where ((id_one=entity_id AND id_two=' .$id. ') OR (id_one='. $id . ' AND id_two=entity_id)) AND (state_one =' . Relationship_general::TYPE_REL_PERSONAL. ' OR (id_one=' .$id. ' AND state_one & ' .Relationship_general::TYPE_REL_IMPERSONAL. ' > 0) OR (id_two=' .$id. ' AND state_two & ' .Relationship_general::TYPE_REL_IMPERSONAL. ' > 0))) > 0 as relationship_exists, "BUSINESS" as broad_alias from business_info join entity on (business_info.entity_id=entity.id) left join photo_entity on (business_info.entity_id=photo_entity.owner_id AND is_profile=1) left join location_perm on (location_perm.id=business_info.entity_id) where name like "%' . $query . '%") union all '.
    					 '(select entity_id as id, name, NULL as birthday, lat, lon, ifnull(image, "") as image, entity_type, (select count(*) from relationship_general where ((id_one=entity_id AND id_two=' .$id. ') OR (id_one='. $id . ' AND id_two=entity_id)) AND (state_one =' . Relationship_general::TYPE_REL_PERSONAL. ' OR (id_one=' .$id. ' AND state_one & ' .Relationship_general::TYPE_REL_IMPERSONAL. ' > 0) OR (id_two=' .$id. ' AND state_two & ' .Relationship_general::TYPE_REL_IMPERSONAL. ' > 0))) > 0 as relationship_exists, "ORGANIZATION" as broad_alias from organization_info join entity on (organization_info.entity_id=entity.id) left join photo_entity on (organization_info.entity_id=photo_entity.owner_id AND is_profile=1) left join location_perm on (location_perm.id=organization_info.entity_id) where name like "%' . $query . '%") order by name limit ' . $offset . ', ' . $limit;
				$result = $this->db->query($sqlQuery);
				return $result->result_array();
		}
	}

	function queryContacts($id, $query, $filter = null) {
		$query = $this->db->escape_like_str($query);
		$id=$this->db->escape($id);
		$entityFilter = "";
		if(isset($filter)) {
			$size = count($filter);
			//converts our recipients into a sql array.
			for($i = 0; $i < $size; $i++) {
				if($i == 0)
					$entityFilter = $entityFilter . 'AND (entity_type='. $this->db->escape($filter[$i]);
				else
					$entityFilter = $entityFilter . ' OR entity_type=' . $this->db->escape($filter[$i]);
			}
			$entityFilter = $entityFilter . ')';
		}
		//we're going to set the entity type to a static value here. this is so that we can know which
		//default image to use on the phone, in case we can't pull the image from the url.
		//persons will be 1, business will b 4, organization will be 7
		$this->load->model('Relationship_general', 'rel');
		//(select count(*) from relationship_general ) > 0 as relationship_exists
		$sqlQuery = '(select entity_id as id, concat(first_name, " ", last_name) as name, 1 as entity_type, ifnull(image, "") as image from person_info join (relationship_general, entity) on ((id_one=entity_id OR id_two=entity_id) AND person_info.entity_id=entity.id) left join photo_entity on (person_info.entity_id=photo_entity.owner_id AND is_profile=1) where ((id_one=entity_id AND id_two=' .$id. ') OR (id_one='. $id . ' AND id_two=entity_id)) AND (state_one =' . Relationship_general::TYPE_REL_PERSONAL. ' OR (id_one=' .$id. ' AND state_one & ' .Relationship_general::TYPE_REL_IMPERSONAL. ' > 0) OR (id_two=' .$id. ' AND state_two & ' .Relationship_general::TYPE_REL_IMPERSONAL. ' > 0)) AND concat(first_name, " ", last_name) like "%' . $query . '%" ' . $entityFilter . ') union all '.
        					 '(select entity_id as id, name, 4 as entity_type, ifnull(image, "") as image from business_info join (relationship_general, entity) on ((id_one=entity_id OR id_two=entity_id) AND business_info.entity_id=entity.id) left join photo_entity on (business_info.entity_id=photo_entity.owner_id AND is_profile=1) where ((id_one=entity_id AND id_two=' .$id. ') OR (id_one='. $id . ' AND id_two=entity_id)) AND (state_one =' . Relationship_general::TYPE_REL_PERSONAL. ' OR (id_one=' .$id. ' AND state_one & ' .Relationship_general::TYPE_REL_IMPERSONAL. ' > 0) OR (id_two=' .$id. ' AND state_two & ' .Relationship_general::TYPE_REL_IMPERSONAL. ' > 0)) AND name like "%' . $query . '%" ' . $entityFilter . ') union all '.
        					 '(select entity_id as id, name, 7 as entity_type, ifnull(image, "") as image from organization_info join (relationship_general, entity) on ((id_one=entity_id OR id_two=entity_id) AND organization_info.entity_id=entity.id) left join photo_entity on (organization_info.entity_id=photo_entity.owner_id AND is_profile=1) where ((id_one=entity_id AND id_two=' .$id. ') OR (id_one='. $id . ' AND id_two=entity_id)) AND (state_one =' . Relationship_general::TYPE_REL_PERSONAL. ' OR (id_one=' .$id. ' AND state_one & ' .Relationship_general::TYPE_REL_IMPERSONAL. ' > 0) OR (id_two=' .$id. ' AND state_two & ' .Relationship_general::TYPE_REL_IMPERSONAL. ' > 0)) AND name like "%' . $query . '%" ' . $entityFilter . ') order by name';
		
		$result = $this->db->query($sqlQuery);
		
		return $result->result_array();
	}
	
	function modifySocialNetworkId($id, $type, $userId) {
		$data = array($type=>$userId);
		$this->db->update('entity', $data, array('id'=>$id));
	}
	
	function getAllAssociatedEntities($id, $includeReg = false) {
		$cleanId = $this->db->escape($id);
		$this->load->model('Relationship_general', 'rel');
    	$sqlQuery = '(select entity.id as id, name.name, ifnull(image, "") as image, entity_type, "' .util::TYPE_MAP_ENTITY. '" as type' . ($includeReg ? ', push_registration_id, phone_type.alias as phone_alias ' : ''). ' from entity '.
    	'join (specific_user_type, ((select entity_id as id, concat(first_name, " ", last_name) as name from person_info) union all (select entity_id as id, name from business_info) union all (select entity_id as id, name from organization_info)) as name) '.
    	'on (entity_type=specific_user_type.id AND entity.id=name.id) left join relationship_general on ((id_one=entity.id AND id_two=' .$cleanId. ') OR (id_one=' .$cleanId. ' AND id_two=entity.id)) '.
    	'left join photo_entity on (entity.id=owner_id AND is_profile=1) left join phone_type on (phone_type.id=entity.phone_type) where '.
    	'((state_one is NULL AND state_two is NULL AND entity.id=' .$cleanId.') OR (state_one = ' . Relationship_general::TYPE_REL_PERSONAL. ' OR (id_one=' .$cleanId. ' AND state_one & ' .Relationship_general::TYPE_REL_IMPERSONAL. ' > 0) OR (id_two=' .$cleanId. ' AND state_two & ' .Relationship_general::TYPE_REL_IMPERSONAL. ' > 0)))) union all '.    	
    	'(select event.id as id, event.name, ifnull(image, "") as image, 8 as entity_type, "' .util::TYPE_MAP_EVENT. '" as type'. ($includeReg ? ', null as push_registration_id, null as photo_alias ' : ''). ' from event ' .
    	'left join photo_event on (photo_event.event_id=event.id AND is_flyer=1) left join event_rsvp on (event.id=event_rsvp.event_id AND entity_id=' .$cleanId. ') left join rsvp_state on (rsvp_state.id=event_rsvp.state) where '.
    	'(alias="MAYBE" OR alias="ATTEND" OR creator_id=' .$cleanId. ') AND timestampdiff(HOUR, now(), start_time) <= 1 AND end_time > now()) order by name';
    	
    	//echo $sqlQuery;
    	
    	$result = $this->db->query($sqlQuery);
    	return $result->result_array();
	}
	
	function getEntitiesFromIds($ids, $includeReg = false) {
		$entityArray = "";

		//converts our recipients into a sql array.
		for($i = 0, $size = count($ids); $i < $size; $i++) 
			$entityArray = $entityArray . ((strlen($entityArray) == 0) ? "(" : ", ") . $this->db->escape($ids[$i]);
		
		$entityArray.= (strlen($entityArray) > 0 ? ")": "('')");
		
		//entities
		$sqlQuery =	 'select entity.id, name.name, entity_type, "' .util::TYPE_MAP_ENTITY. '" as type, ifnull(image, "") as image '. ($includeReg ? ', push_registration_id, phone_type.alias as phone_alias ' : '').
		'from entity join ((select entity_id as id, concat(first_name, " ", last_name) as name from person_info) union all (select entity_id as id, name from business_info) union all (select entity_id as id, name from organization_info)) as name '.
		'on (entity.id=name.id) left join photo_entity on (entity.id=owner_id AND is_profile=1) left join phone_type on (phone_type.id=entity.phone_type)'.
		'where entity.id IN ' .$entityArray;
		
		
		//echo $sqlQuery;
		$result = $this->db->query($sqlQuery);
		return $result->result_array();
	}
}
?>