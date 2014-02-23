<?php
class Event_rating extends CI_Model {
	
	function __construct() {
		parent::__construct();
		$this->load->library('errorCode'); //used for returning error codes
		$this->load->library('util');
		//$this->db->query("SET time_zone='+0:00'");
	}
	function updateUserRating($entityId, $eventId, $rating) {
		$resultCount = $this->db->from('event_rating')->where(array("entity_id"=>$entityId, "event_id"=>$eventId))->count_all_results();
		
		if($resultCount > 0) {
			
			$this->db->where(array("entity_id"=>$entityId, "event_id"=>$eventId));
			$this->db->update('event_rating', array('rating'=>$rating));
		} else
			$this->db->insert('event_rating', array('entity_id'=>$entityId, 'event_id'=>$eventId, 'rating'=>$rating));
	}
	
	function getEventRatingInfo($entityId, $eventId) {
		$cleanEventId = $this->db->escape($eventId);
		$cleanEntityId = $this->db->escape($entityId);
		
		$result = $this->db->select('(select rating from event_rating where event_id=' .$cleanEventId. ' AND entity_id=' .$cleanEntityId. ') as my_rating, avg(rating) as avg, concat(round(avg(rating) * 10), "%") as avg_percent, count(rating) as count', FALSE)->from('event_rating')->where('event_id', $eventId)->get()->row_array();
		return $result;
	}
	
		
	
}