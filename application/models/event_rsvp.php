<?php

class Event_rsvp extends CI_Model {
	function __construct() {
		parent::__construct();
		$this->load->library('errorCode'); //used for returning error codes
		$this->load->library('util');
	}

	
	function addRsvps($eventId, $personIds) {
		$rsvp = $this->db->select('id')->from('rsvp_state')->where('alias', 'INVITE')->get()->row()->id;
		
		$data = array('event_id'=>$eventId, 'state'=>$rsvp);
		foreach ($personIds as $id) {
			$data['entity_id'] = $id;
			$this->db->insert('event_rsvp', $data);
		}		
	}
	
	function removeRsvps($eventId, $personIds) {
		$where = 'event_id=' .$eventId. ' AND (';
		for($i=0, $len=count($personIds); $i < $len; $i++)
			$where .= (($i==0 ? '' : ' OR ') .'entity_id='.$personIds[$i]);
		$where .= ')';
		$this->db->where($where, NULL, FALSE);
		$this->db->delete('event_rsvp');
	}
	
	function changeRsvp($data) {
		$where = array('event_id'=>$data['event_id'], 'entity_id'=>$data['id']);
		$result = $this->db->select()->from('event_rsvp')->where($where)->limit(1)->get();
		if($result->num_rows() > 0) {
			$this->db->where($where);
			$this->db->update('event_rsvp', array('state'=>$data['rsvp']));				
		} else {
			$where['state'] = $data['rsvp'];
			$this->db->insert('event_rsvp', $where);
		}
		
		$result = $this->db->select()->from('event_rsvp')->where($where)->limit(1)->get();
		if($result->num_rows() > 0) {
			return $result->row_array();
		} else
			return false;
	}
	
	function getRsvps($event_id, $rsvp =  null) {
		$id = $this->db->escape($event_id);
		$where = array('event_id'=>$id);
		if(isset($rsvp))
			$where['state'] = $rsvp;
		$result = $this->db->select('event_rsvp.entity_id, concat(first_name, " ", last_name) as name, ifnull(image,"") as image, alias', FALSE)->from('event_rsvp')->join('person_info', 'event_rsvp.entity_id=person_info.entity_id')->join('rsvp_state', 'event_rsvp.state=rsvp_state.id')->join('photo_entity', 'owner_id=event_rsvp.entity_id AND is_profile=1', 'left')->where($where)->order_by('name', 'asc')->get();
		return $result->result_array();
				
	}
	
}
?>