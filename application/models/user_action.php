<?php

class User_action extends CI_Model {

	function getUserActions($type) {
		$where = "specific_user_type_id=". $this->db->escape($type) ." AND datatype.alias != 'RSVP' AND datatype.alias !='RATING'";
		$result = $this->db->distinct()->select('datatype.alias as datatype')->from('user_action')->join('action', 'user_action.action_id=action.id')->join('datatype', 'action.datatype_id=datatype.id')->where($where, NULL, FALSE)->order_by('datatype', 'asc')->get();
	
		if($result) {
			if($result->num_rows() > 0)
				return $result->result_array();
		} else
			return false;
	}
}
?>