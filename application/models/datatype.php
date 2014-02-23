<?php

class Datatype extends CI_Model {

	function getAllDatatypes() {
		$result = $this->db->get('datatype');
	
		if($result) {
			if($result->num_rows() > 0)
				return $result->result_array();
		} else
			return false;
	}
	
	function getIdForAlias($alias) {
		$query = $this->db->select('id')->from('datatype')->where('alias', $alias)->limit(1)->get();
		return $query->num_rows() > 0 ? $query->row()->id : NULL;
	}
	

}
?>