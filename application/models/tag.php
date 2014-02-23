<?php
class Tag extends CI_Model {
	
	private $tagArray = array();
	function __construct() {
		parent::__construct();
		$this->load->library('errorCode'); //used for returning error codes
		$this->load->library('util');
		
		$this->tagArray['activity'] = array('table_name'=>'person_tag_activity', 'id_name'=>'person_info_id');
		$this->tagArray['business_employee'] = array('table_name'=>'business_tag_employee', 'id_name'=>'business_info_id');
		$this->tagArray['business_env'] = array('table_name'=>'business_tag_env', 'id_name'=>'business_info_id');
		$this->tagArray['business_field'] = array('table_name'=>'business_tag_field', 'id_name'=>'business_info_id');
		$this->tagArray['business_interest'] = array('table_name'=>'person_tag_business', 'id_name'=>'person_info_id');
		$this->tagArray['event'] = array('table_name'=>'event_tag_event', 'id_name'=>'event_id');
		$this->tagArray['organization_activity'] = array('table_name'=>'organization_tag_activity', 'id_name'=>'organization_info_id');
		$this->tagArray['organization_cause'] = array('table_name'=>'organization_tag_cause', 'id_name'=>'organization_info_id');
		$this->tagArray['organization_member'] = array('table_name'=>'organization_tag_member', 'id_name'=>'organization_info_id');
		$this->tagArray['people'] = array('table_name'=>'person_tag_people', 'id_name'=>'person_info_id');
		$this->tagArray['social_interest'] = array('table_name'=>'person_tag_social', 'id_name'=>'person_info_id');
		$this->tagArray['bucket_list_item'] = array('table_name'=>'bucket_list_item_tag_activity', 'id_name'=>'bucket_list_item_id');
	}
	
	function getTag($name) {
		$query = $this->db->get("tag_" . $name);
		return $query->result_array();
	}
	
	function getPersonTags($entity_id) {
		//social, business, activity, people.
		$id = $this->db->escape($entity_id);
		$query = 'select * from ((select 1 as rank, "social interests" as title, id, name from  person_tag_social join tag_social_interest on (tag_id=tag_social_interest.id) '.
							'where person_info_id=(select id from person_info where entity_id='.$id.')) union all '.
							'(select 2 as rank, "business interests" as title,  id, name from person_tag_business join tag_business_interest on (tag_id=tag_business_interest.id) '.
							'where person_info_id=(select id from person_info where entity_id='.$id.')) union all '.
							'(select 3 as rank, "activities" as title, id, name from person_tag_activity join tag_activity on (tag_id=tag_activity.id) '.
							'where person_info_id=(select id from person_info where entity_id='.$id.')) union all '.
							'(select 4 as rank, "people" as title, id, name from person_tag_people join tag_people on (tag_id=tag_people.id) '.
							'where person_info_id=(select id from person_info where entity_id='.$id.'))) a order by rank, name;';
		//echo $query;
		return $this->db->query($query)->result_array();
	}
	
	function getBusinessTags($entity_id, $specific_tags = null) {
		//field, environment, employee
		$id = $this->db->escape($entity_id);
		if(!isset($specific_tags)) {
			$query = 'select * from ((select 1 as rank, "business fields" as title, id, name from  business_tag_field join tag_business_field on (tag_id=tag_business_field.id) '.
					'where business_info_id=(select id from business_info where entity_id='.$id.')) union all '.
					'(select 2 as rank, "business environment" as title,  id, name from business_tag_env join tag_business_env on (tag_id=tag_business_env.id) '.
					'where business_info_id=(select id from business_info where entity_id='.$id.')) union all '.
					'(select 3 as rank, "ideal employee" as title, id, name from business_tag_employee join tag_business_employee on (tag_id=tag_business_employee.id) '.
					'where business_info_id=(select id from business_info where entity_id='.$id.'))) a order by rank, name;';
		} else {
			$query = '';
			if(in_array('business_field', $specific_tags)) {
				$query .= '(select 1 as rank, "business fields" as title, id, alias, name from  business_tag_field join tag_business_field on (tag_id=tag_business_field.id) '.
					'where business_info_id=(select id from business_info where entity_id='.$id.'))';
			}
			if(in_array('business_env', $specific_tags)) {
				if(strlen($query) > 0)
					$query .= ' union all ';
				
				$query .= '(select 2 as rank, "business environment" as title, id, alias, name from business_tag_env join tag_business_env on (tag_id=tag_business_env.id) '.
					'where business_info_id=(select id from business_info where entity_id='.$id.'))';
			}
			if(in_array('business_employee', $specific_tags)) {
				if(strlen($query) > 0)
					$query .= ' union all ';			
				
				$query .= '(select 3 as rank, "ideal employee" as title, id, alias, name from business_tag_employee join tag_business_employee on (tag_id=tag_business_employee.id) '.
				'where business_info_id=(select id from business_info where entity_id='.$id.'))';
			}
			
			$query = 'select * from (' .$query. ') a order by rank, name';
			
		}
		//echo $query;
		return $this->db->query($query)->result_array();
	}
	
	function getOrganizationTags($entity_id) {
		//causes, activities, members
		$id = $this->db->escape($entity_id);
		$query = 'select * from ((select 1 as rank, "causes" as title, id, name from  organization_tag_cause join tag_organization_cause on (tag_id=tag_organization_cause.id) '.
				'where organization_info_id=(select id from organization_info where entity_id='.$id.')) union all '.
				'(select 2 as rank, "activities" as title,  id, name from organization_tag_activity join tag_organization_activity on (tag_id=tag_organization_activity.id) '.
				'where organization_info_id=(select id from organization_info where entity_id='.$id.')) union all '.
				'(select 3 as rank, "ideal members" as title, id, name from organization_tag_member join tag_organization_member on (tag_id=tag_organization_member.id) '.
				'where organization_info_id=(select id from organization_info where entity_id='.$id.'))) a order by rank, name;';
		//echo $query;
		return $this->db->query($query)->result_array();
	}
	
	function getEventTags($event_id) {
		$result = $this->db->select('id, name')->from('event_tag_event')->join('tag_event', 'tag_id=tag_event.id')->where('event_id', $event_id)->order_by('name', 'asc')->get();
		return $result->result_array();
				
	}
	
	function getBucketListTags($list_item_id) {
		$result = $this->db->select('id, name')->from('bucket_list_item_tag_activity')->join('tag_activity', 'tag_id=tag_activity.id')->where('bucket_list_item_id', $list_item_id)->order_by('name', 'asc')->get();
		errorCode::logError("database", "tags");
		return $result->result_array();		
	}
	
	function modifyTags($id, $tagName, $added, $removed) {
		$tagInfo = $this->tagArray[$tagName];
		if(isset($added)) {
			foreach($added as &$addTag)
				$addTag[$tagInfo['id_name']] = $id;
			unset($addTag);
			$this->db->insert_batch($tagInfo['table_name'], $added);
		}
		if(isset($removed)) {
			foreach($removed as &$removeTag) {
				$removeTag[$tagInfo['id_name']] = $id;
				$this->db->delete($tagInfo['table_name'], $removeTag);
				errorCode::logError("database", "tag - removing");
			}
		}
	}
	
	function getBusinessForService($services, $lat, $lon) {
		
		$sqlArray = "(";
		//converts our recipients into a sql array.
		for($i = 0, $size = count($services); $i < $size; $i++) 
			$sqlArray = $sqlArray . ($i==0 ? '' : ', '). $this->db->escape($services[$i]);
		$sqlArray = $sqlArray . ")";
		
		$query = 'select distinct entity_id as id from business_info join (location_perm, business_tag_field) on (location_perm.id=business_info.entity_id AND business_info_id=business_info.id) where tag_id in ' .$sqlArray. ' AND ' .$this->util->getHaversineSQLString($lat, $lon, 'lat', 'lon', 'mi'). ' < 15' ;
		return $this->db->query($query)->result_array(); 
	}
	
	function getPeopleForJobPosting($businessId, $jobReqs) {
		
		$sqlArray = "(";
		//converts our recipients into a sql array.
		for($i = 0, $size = count($jobReqs); $i < $size; $i++) 
			$sqlArray = $sqlArray . ($i==0 ? '' : ', '). $this->db->escape($jobReqs[$i]);
		$sqlArray = $sqlArray . ")";
		
		$query = 'select distinct entity_id as id from person_info join (location_perm, person_tag_business, tag_interest_to_employee_map) on (location_perm.id=person_info.entity_id AND person_tag_business.person_info_id=person_info.id AND tag_interest_to_employee_map.business_interest_id=person_tag_business.tag_id) ' .
		 'where tag_interest_to_employee_map.employee_id in '.$sqlArray. ' AND ' .$this->util->getHaversineSQLString('(select lat from location_perm where id=' .$businessId. ')', '(select lon from location_perm where id=' .$businessId. ')', 'lat', 'lon', 'mi'). ' < 100' ;
		
		return $this->db->query($query)->result_array();
	}
}