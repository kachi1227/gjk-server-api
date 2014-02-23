<?php
/*
 * Class that retrieves and inserts data into the 'person_info' table
 */
class Person_info extends CI_Model {    

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
	function insert($array) {
		$data = array();
		$data['entity_id'] = $array['entity_id'];
		$data['first_name'] = $array['first_name'];
		$data['last_name'] = $array['last_name'];
		if(isset($array['birthday']))
			$data['birthday'] = date( 'Y-m-d H:i:s', $array['birthday']/1000);
		if(isset($array['gender']))
			$data['gender'] = substr($array['gender'], 0, 1);
		if(isset($array['phone_number']))
			$data['phone_number'] = $array['phone_number'];	
		if($array['user_type'] == "PUBLIC" || $array['user_type'] == "DIGNITARY") {
			$data['occupation'] = $array['occupation'];
		}
		if($array['user_type'] == "DIGNITARY") {
			
			$this->load->model('Location_perm', 'loc');
			if(!$this->loc->addLocation(array('id'=>$array['entity_id'], 'lat'=>$array['lat'], 'lon'=>$array['lon'], 'address'=>$array['address']))) //if this fails
				return false; //return false here, don't bother inserting other info
		}
		return $this->db->insert('person_info', $data);;	
	}
	
	function editAccount($data) {
		$id = $data['id'];
		unset($data['id']);
		unset($data['entity_type']);		
		
		//TODO we should figure out how extensively this function is going to be used. for now, just dump all the data into the account
		if(count($data) > 0) {
			$this->db->where('entity_id', $id)->update('person_info', $data);
			if(isset($data['log_location']) && ($data['log_location'] == 0 || $data['log_location'] == false)) {
				$currCheckIn = $this->getCurrentCheckIn($id);
				if($currCheckIn != NULL)
					$this->updateCurrentCheckIn($id, $currCheckIn, NULL);
				
			}
		}
		
		return $this->getAccountInfo($id);
	}
	
	function editInfo($data) {
		$id = $data['id'];
		unset($data['id']);
		unset($data['entity_type']);	
		//deal with the location edit
		if(isset($data['lon']) && isset($data['lat'])) {
			$locationData = array('id'=>$id, 'lat'=>$data['lat'], 'lon'=>$data['lon'], 'address'=>(isset($data['address']) ? $data['address'] : ''));
			$this->load->model('Location_perm', 'loc');
			$this->loc->addLocation($locationData);
			unset($data['lat']);
			unset($data['lon']);
			if(isset($data['address']))
				unset($data['address']);
		}
		
		//deal with any tag modifications we may have made
		if(isset($data['tags'])) {
			$this->load->model('Tag', 'tag');
			$info_id = $this->db->select('id')->from('person_info')->where('entity_id', $id)->limit(1)->get()->row_array();
			foreach ($data['tags'] as $tagName=>$alteredTags) 
				$this->tag->modifyTags($info_id['id'], $tagName, $alteredTags['added'], $alteredTags['removed']);
			
			unset($data['tags']);
		}
		
		//modify the birthday to be MySQL ready
		if(isset($data['birthday']))
			$data['birthday'] = date( 'Y-m-d H:i:s', $data['birthday']/1000);
		//now we're ready
		if(count($data) > 0) 
			$this->db->where('entity_id', $id)->update('person_info', $data);
		
		return $this->getProfileInfo($id);		
	}
	
	function getProfileInfo($entity_id) {
		$profileInfo = array();
		$this->load->model('Tag', 'tag');
	
		$profileInfo['basic_info'] = $this->getBasicInfo($entity_id);
		if($profileInfo['basic_info']) {
			$profileInfo['misc_info'] = $this->getMiscProfile($entity_id);
			$profileInfo['tags'] = $this->tag->getPersonTags($entity_id);
			return 	$profileInfo;
		} else
			return errorCode::USER_NOT_RETRIEVED;
	}
	
	
	function getBasicInfo($entity_id) {
		$result = $this->db->select('entity_id as id, first_name, last_name, concat(first_name, " ", last_name) as name, entity_type, ((datediff(birthday, from_unixtime(0)) * 24 * 3600 * 1000) + (time_to_sec(timediff(time(birthday), time(from_unixtime(0)))) * 1000)) as birthday, lat, lon, ifnull(image, "") as image', false)->from('person_info')->join('entity', 'person_info.entity_id=entity.id')->join('photo_entity', 'person_info.entity_id=photo_entity.owner_id AND is_profile=1', 'left')->join('location_perm', 'location_perm.id=person_info.entity_id', 'left')->where('entity_id', $entity_id)->limit(1)->get();
		return $result->num_rows() > 0 ? $result->row_array() : false;
	}
	
	function getAccountInfo($entity_id) {
		$result = $this->db->select('entity_id as id, first_name, last_name, concat(first_name, " ", last_name) as name, entity_type, ((datediff(birthday, from_unixtime(0)) * 24 * 3600 * 1000) + (time_to_sec(timediff(time(birthday), time(from_unixtime(0)))) * 1000)) as birthday, lat, lon, log_location, automatic_checkin, map_visible, ifnull(image, "") as image', false)->from('person_info')->join('entity', 'person_info.entity_id=entity.id')->join('photo_entity', 'person_info.entity_id=photo_entity.owner_id AND is_profile=1', 'left')->join('location_perm', 'location_perm.id=person_info.entity_id', 'left')->where('entity_id', $entity_id)->limit(1)->get();
		return $result->num_rows() > 0 ? $result->row_array() : false;
	}
	
	function getMiscProfile($entity_id) {
	
		return $this->db->select('"gender" as line_1_label, case when gender="M" then "male" when gender="F" then "female" when gender is null then "" END as line_1_value, if((select entity_type from entity where id=' .$entity_id.')=1, "age", if((select entity_type from entity where id=' .$entity_id.')=3, "position", "occupation")) as line_2_label, if((select entity_type from entity where id=' .$entity_id.')=1, ((datediff(birthday, from_unixtime(0)) * 24 * 3600 * 1000) + time_to_sec(timediff(time(birthday), time(from_unixtime(0)))) * 1000), ifnull(occupation, "")) as line_2_value, "about" as line_3_label, ifnull(about, "") as line_3_value, if((select entity_type from entity where id=' .$entity_id. ')=1, "occupation", "birthday") as line_4_label, if((select entity_type from entity where id=' .$entity_id. ')=1, ifnull(occupation, ""), ((datediff(birthday, from_unixtime(0)) * 24 * 3600 * 1000) + time_to_sec(timediff(time(birthday), time(from_unixtime(0)))) * 1000)) as line_4_value', FALSE)->from('person_info')->where('entity_id', $entity_id)->get()->row_array();
	}
	
	
	function searchBasicInfo($id, $query, $offset = 0, $limit = 20) {
		$id = $this->db->escape($id);
		
		$this->load->model('Relationship_general', 'rel');
		$result = $this->db->select('entity_id as id, concat(first_name, " ", last_name) as name, ((datediff(birthday, from_unixtime(0)) * 24 * 3600 * 1000) + time_to_sec(timediff(time(birthday), time(from_unixtime(0)))) * 1000) as birthday, lat, lon, ifnull(image, "") as image, entity_type, (select count(*) from relationship_general where ((id_one=entity_id AND id_two=' .$id. ') OR (id_one='. $id . ' AND id_two=entity_id)) AND (state_one =' . Relationship_general::TYPE_REL_PERSONAL. ' OR (id_one=' .$id. ' AND state_one & ' .Relationship_general::TYPE_REL_IMPERSONAL. ' > 0) OR (id_two=' .$id. ' AND state_two & ' .Relationship_general::TYPE_REL_IMPERSONAL. ' > 0))) > 0 as relationship_exists, "PERSON" as  broad_alias', false)->from('person_info')->join('entity', 'person_info.entity_id=entity.id')->join('photo_entity', 'person_info.entity_id=photo_entity.owner_id AND is_profile=1', 'left')->join('location_perm', 'location_perm.id=person_info.entity_id', 'left')->like('concat(first_name, " ", last_name)', $query)->limit($limit, $offset)->order_by('first_name, last_name asc')->get();
		
		return $result->result_array();
	}
	
	function getCurrentCheckIn($entity_id) {
		$result = $this->db->select('current_checkin')->from('person_info')->where('entity_id', $entity_id)->get();
		return $result->num_rows() > 0 ? $result->row_array() : NULL;
	}
	
	function updateCurrentCheckIn($id, $currCheckIn, $val) {
		$this->load->model('Checkin', 'checkin');
		
		if($val == NULL) { //if we're checking a user out, then first check if the place is an event., if it is, post it to live stream		
			if($result = $this->checkin->getCheckIn($currCheckIn)) { //get the current checkin
				if($result['place_type_alias'] == checkin::TYPE_EVENT || $result['secondary_place_type'] == checkin::TYPE_EVENT) { //if its an event
					$this->load->model('Livestream_item', 'livestream'); 
					$streamData = array("entity_id"=>$id, "event_id"=>$result[$result['place_type_alias'] == checkin::TYPE_EVENT ? 'place_id' : 'secondary_place_id'], "alias"=>Livestream_item::DEPART);
					$this->db->trans_start();
					$this->livestream->insertNewItem($streamData); //add new livestream item 
					$this->db->trans_complete();
				} 
				
				if($result['place_type_alias'] == checkin::TYPE_BUSINESS || $result['secondary_place_type'] == checkin::TYPE_BUSINESS) {
					$this->load->model('Location_chat', 'loc_chat');
					$this->loc_chat->removeAnonymousId($id); 					
				}
			}			
		}
		
		$this->db->where('entity_id', $id);
		$this->db->update('person_info', array('current_checkin'=>$val));		
		
		$result = $this->db->select('current_checkin')->from('person_info')->where('entity_id', $id)->limit(1)->get()->row_array();
		
		$success = $result['current_checkin'] == $val;

		if($success && $val != NULL) { //if we succeeded in updating checkin value & we werent checking out
			if($result = $this->checkin->getCheckIn($val)) {//get the current checkin
				if($result['place_type_alias'] == checkin::TYPE_EVENT || $result['secondary_place_type'] == checkin::TYPE_EVENT) {//if its an event
					$this->load->model('Livestream_item', 'livestream');
					$streamData = array("entity_id"=>$id, "event_id"=>$result[$result['place_type_alias'] == checkin::TYPE_EVENT ? 'place_id' : 'secondary_place_id'], "alias"=>Livestream_item::ARRIVE);
					$this->db->trans_start();						
					$this->livestream->insertNewItem($streamData); //add new livestream item
					$this->db->trans_complete();
				} 
				
				if($result['place_type_alias'] == checkin::TYPE_BUSINESS || $result['secondary_place_type'] == checkin::TYPE_BUSINESS) {
					$this->load->model('Location_chat', 'loc_chat');
					$this->loc_chat->createAnonymousId($id);
				}
			}
		}
		return $success;
	}
	
	function isAutomaticCheckIn($entity_id) {
		$result = $this->db->select('automatic_checkin')->from('person_info')->where('entity_id', $entity_id)->get()->row_array();
		return $result['automatic_checkin'];
		
	}
}
?>