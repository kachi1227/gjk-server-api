<?php

class Rsvp_state extends CI_Model {

	function getAllStates() {
		$result = $this->db->get('rsvp_state');
	
		if($result) {
			if($result->num_rows() > 0)
				return $result->result_array();
		} else
			return false;
	}

}
?>