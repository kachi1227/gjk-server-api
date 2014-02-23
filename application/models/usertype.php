<?php
class Usertype extends CI_Model {

	function getBroadUserTypeIdByAlias($alias) {
		$this->db->select('broad_id')->from('specific_user_type')->where('alias', $alias)->limit(1);
		$query = $this->db->get();
		$row = $query->row();
		return $query->num_rows() > 0 ? $row->broad_id : 0;
	}
	
	function getBroadUserTypeIdById($id) {
		$this->db->select('broad_id')->from('specific_user_type')->where('id', $id)->limit(1);
		$query = $this->db->get();
		$row = $query->row();
		return $query->num_rows() > 0 ? $row->broad_id : 0;
	}

	function convertFromAliasToId($alias) {
		$this->db->select('id')->from('specific_user_type')->where('alias', $alias)->limit(1);
		$query = $this->db->get();
		$row = $query->row();
		return $query->num_rows() > 0 ? $row->id : 0;
	}
}
?>