<?php
/*
 * Class that retrieves and inserts data into the 'user' table
 */
class Business_info extends CI_Model {    

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
		$data['name'] = $array['name'];
		$data['type'] = $array['type'];
		$data['phone_number'] = $array['phone_number'];
		$this->load->model('Location_perm', 'loc');
		if(!$this->loc->addLocation(array('id'=>$array['entity_id'], 'lat'=>$array['lat'], 'lon'=>$array['lon'], 'address'=>$array['address']))) //if this fails
			return false; //return false here, don't bother inserting other info
			
		return $this->db->insert('business_info', $data);	
	}
	
	function editAccount($data) {
		$id = $data['id'];
		unset($data['id']);
		unset($data['entity_type']);		
		
		//TODO we should figure out how extensively this function is going to be used. for now, just dump all the data into the account
		if(count($data) > 0)
			$this->db->where('entity_id', $id)->update('person_info', $data);
		
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
			$info_id = $this->db->select('id')->from('business_info')->where('entity_id', $id)->limit(1)->get()->row_array();
			foreach ($data['tags'] as $tagName=>$alteredTags)
				$this->tag->modifyTags($info_id['id'], $tagName, $alteredTags['added'], $alteredTags['removed']);
				
			unset($data['tags']);
		}
	
		//now we're ready
		if(count($data) > 0) 
			$this->db->where('entity_id', $id)->update('business_info', $data);
	
		return $this->getProfileInfo($id);
	}
	

		
	function getProfileInfo($entity_id) {
		
		$profileInfo = array();
		$this->load->model('Tag', 'tag');
		$profileInfo['basic_info'] = $this->getBasicInfo($entity_id);
		if($profileInfo['basic_info']) {
			$profileInfo['misc_info'] = $this->getMiscProfile($entity_id);
			$profileInfo['tags'] = $this->tag->getBusinessTags($entity_id);
			return 	$profileInfo;
		} else
			return errorCode::USER_NOT_RETRIEVED;
	}
	
	function getBasicInfo($entity_id) {
		$result = $this->db->select('entity_id as id, name, entity_type, lat, lon, ifnull(image, "") as image', false)->from('business_info')->join('entity', 'business_info.entity_id=entity.id')->join('photo_entity', 'business_info.entity_id=photo_entity.owner_id AND is_profile=1', 'left')->join('location_perm', 'location_perm.id=business_info.entity_id')->where('entity_id', $entity_id)->limit(1)->get();
		return $result->num_rows() > 0 ? $result->row_array() : false;
	}
	
	function getAccountInfo($entity_id) {
		//don't just call on getBasicInfo. we might have stuff that we need to retreive later on
		$result = $this->db->select('entity_id as id, name, entity_type, lat, lon, ifnull(image, "") as image', false)->from('business_info')->join('entity', 'business_info.entity_id=entity.id')->join('photo_entity', 'business_info.entity_id=photo_entity.owner_id AND is_profile=1', 'left')->join('location_perm', 'location_perm.id=business_info.entity_id')->where('entity_id', $entity_id)->limit(1)->get();
		return $result->num_rows() > 0 ? $result->row_array() : false;
	}
	
	function getMiscProfile($entity_id) {	
		return $this->db->select('"address" as line_1_label, address as line_1_value, if((select entity_type from entity where id=' .$entity_id.')=4, "goods sold", if((select entity_type from entity where id=' .$entity_id.')=5, "industry", "category")) as line_2_label, type as line_2_value, "about" as line_3_label, ifnull(about, "") as line_3_value', FALSE)->from('business_info')->join('location_perm', 'location_perm.id=business_info.entity_id')->where('entity_id', $entity_id)->get()->row_array();
	}
	
	
	
	function searchBasicInfo($id, $query, $offset = 0, $limit = 20, $includeAddress = false) {
		$id = $this->db->escape($id);
		$this->load->model('Relationship_general', 'rel');		
		$this->db->select('entity_id as id, name, lat, lon, address, ifnull(image, "") as image, entity_type, (select count(*) from relationship_general where ((id_one=entity_id AND id_two=' .$id. ') OR (id_one='. $id . ' AND id_two=entity_id)) AND (state_one =' . Relationship_general::TYPE_REL_PERSONAL. ' OR (id_one=' .$id. ' AND state_one & ' .Relationship_general::TYPE_REL_IMPERSONAL. ' > 0) OR (id_two=' .$id. ' AND state_two & ' .Relationship_general::TYPE_REL_IMPERSONAL. ' > 0))) > 0 as relationship_exists, "BUSINESS" as  broad_alias', false)->from('business_info')->join('entity', 'business_info.entity_id=entity.id')->join('photo_entity', 'business_info.entity_id=photo_entity.owner_id AND is_profile=1', 'left')->join('location_perm', 'location_perm.id=business_info.entity_id', 'left')->like('name', $query);
		if($includeAddress)
			$this->db->or_like('address', $query);
		$result = $this->db->limit($limit, $offset)->get();
		return $result->result_array();
	}	
	
}

?>