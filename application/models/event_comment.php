<?php

class Event_comment extends CI_Model {
	function __construct() {
		parent::__construct();
		$this->load->library('errorCode'); //used for returning error codes
		$this->load->library('util');
	}
	
	function insertComment($data) {
	
		$this->db->set('event_id', $data['event_id']);
		$this->db->set('entity_id', $data['entity_id']);
		$this->db->set('comment', $data['comment']);
		$this->db->insert('event_comment');
	}

	function getEventComments($eventId, $lastId, $limit) {
		//person
		$query = '(select event_comment.id, event_comment.entity_id, concat(first_name, " ", last_name) as name, comment, entity_type, (unix_timestamp(date) * 1000) as date, ifnull(image, "' . $this->util->getDefaultImage(util::EVENT_INFO) . '") as image '.
					'from event_comment join (person_info, entity) on (event_comment.entity_id = entity.id AND person_info.entity_id=event_comment.entity_id) left join photo_entity on (photo_entity.owner_id=event_comment.entity_id AND is_profile=1) where event_id=' .$eventId. ' AND event_comment.id > ' .$lastId. ') union all ' .
	
		//business
					'(select event_comment.id, event_comment.entity_id, name, comment, entity_type, (unix_timestamp(date) * 1000) as date, ifnull(image, "' . $this->util->getDefaultImage(util::EVENT_INFO) . '") as image '.
					'from event_comment join (business_info, entity) on (event_comment.entity_id = entity.id AND business_info.entity_id=event_comment.entity_id) left join photo_entity on (photo_entity.owner_id=event_comment.entity_id AND is_profile=1) where event_id=' .$eventId. ' AND event_comment.id > ' .$lastId. ') union all ' .
	
		//organization
					'(select event_comment.id, event_comment.entity_id, name, comment, entity_type, (unix_timestamp(date) * 1000) as date, ifnull(image, "' . $this->util->getDefaultImage(util::EVENT_INFO) . '") as image '.
					'from event_comment join (organization_info, entity) on (event_comment.entity_id = entity.id AND organization_info.entity_id=event_comment.entity_id) left join photo_entity on (photo_entity.owner_id=event_comment.entity_id AND is_profile=1) where event_id=' .$eventId. ' AND event_comment.id > ' .$lastId. ') order by date DESC limit ' . $limit;
		return $this->db->query($query)->result_array();
	}
	
}
?>