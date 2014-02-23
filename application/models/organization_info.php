<?php
/*
 * Class that retrieves and inserts data into the 'user' table
 */
class Organization_info extends CI_Model {    

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
		
		if(isset($array['phone_number']))
			$data['phone_number'] = $array['phone_number'];
		if(isset($array['slogan']))
			$data['slogan'] = $array['slogan'];

		return $this->db->insert('organization_info', $data);	
	}
	
	function editAccount($data) {
		$id = $data['id'];
		unset($data['id']);
		unset($data['entity_type']);		
		
		//TODO we should figure out how extensively this function is going to be used. for now, just dump all the data into the account
		if(count($data) > 0)
			$this->db->where('entity_id', $id)->update('organization_info', $data);
		
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
			$info_id = $this->db->select('id')->from('organization_info')->where('entity_id', $id)->limit(1)->get()->row_array();
			foreach ($data['tags'] as $tagName=>$alteredTags)
				$this->tag->modifyTags($info_id['id'], $tagName, $alteredTags['added'], $alteredTags['removed']);
	
			unset($data['tags']);
		}
	
		//now we're ready
		if(count($data) > 0)
			$this->db->where('entity_id', $id)->update('organization_info', $data);
	
		return $this->getProfileInfo($id);
	}
	
	
	function getProfileInfo($entity_id) {
		
		$profileInfo = array();
		$this->load->model('Tag', 'tag');
		$profileInfo['basic_info'] = $this->getBasicInfo($entity_id);
		if($profileInfo['basic_info']) {
			$profileInfo['misc_info'] = $this->getMiscProfile($entity_id);
			$profileInfo['tags'] = $this->tag->getOrganizationTags($entity_id);
			return 	$profileInfo;
		} else
			return errorCode::USER_NOT_RETRIEVED;
	}
	
	function getBasicInfo($entity_id) {
		$result = $this->db->select('entity_id as id, name, entity_type, lat, lon, ifnull(image, "") as image', false)->from('organization_info')->join('entity', 'organization_info.entity_id=entity.id')->join('photo_entity', 'organization_info.entity_id=photo_entity.owner_id AND is_profile=1', 'left')->join('location_perm', 'location_perm.id=organization_info.entity_id', 'left')->where('entity_id', $entity_id)->limit(1)->get();
		return $result->num_rows() > 0 ? $result->row_array() : false;
	}
	
	function getAccountInfo($entity_id) {
		$result = $this->db->select('entity_id as id, name, entity_type, lat, lon, log_location, automatic_checkin, ifnull(image, "") as image', false)->from('organization_info')->join('entity', 'organization_info.entity_id=entity.id')->join('photo_entity', 'organization_info.entity_id=photo_entity.owner_id AND is_profile=1', 'left')->join('location_perm', 'location_perm.id=organization_info.entity_id', 'left')->where('entity_id', $entity_id)->limit(1)->get();
		return $result->num_rows() > 0 ? $result->row_array() : false;
	}
	
	function getMiscProfile($entity_id) {
		return $this->db->select('"organization type" as line_1_label, type as line_1_value, "slogan" as line_2_label, slogan as line_2_value, "about" as line_3_label, ifnull(about, "") as line_3_value', FALSE)->from('organization_info')->where('entity_id', $entity_id)->get()->row_array();
	}
	
	
	
	function searchBasicInfo($id, $query, $offset = 0, $limit = 20) {
		$id = $this->db->escape($id);
		
		$this->load->model('Relationship_general', 'rel');
		$result = $this->db->select('entity_id as id, name, lat, lon, ifnull(image, "") as image, entity_type, (select count(*) from relationship_general where ((id_one=entity_id AND id_two=' .$id. ') OR (id_one='. $id . ' AND id_two=entity_id)) AND (state_one =' . Relationship_general::TYPE_REL_PERSONAL. ' OR (id_one=' .$id. ' AND state_one & ' .Relationship_general::TYPE_REL_IMPERSONAL. ' > 0) OR (id_two=' .$id. ' AND state_two & ' .Relationship_general::TYPE_REL_IMPERSONAL. ' > 0))) > 0 as relationship_exists, "ORGANIZATION" as  broad_alias', false)->from('organization_info')->join('entity', 'organization_info.entity_id=entity.id')->join('photo_entity', 'organization_info.entity_id=photo_entity.owner_id AND is_profile=1', 'left')->join('location_perm', 'location_perm.id=organization_info.entity_id', 'left')->like('name', $query)->limit($limit, $offset)->get();
		return $result->result_array();
	}
}
?>