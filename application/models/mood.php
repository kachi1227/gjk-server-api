<?php

class Mood extends CI_Model {

	function getAllMoods() {
		$result = $this->db->get('mood');
	
		if($result) {
			if($result->num_rows() > 0)
				return $result->result_array();
		} else
			return false;
	}

}
?>