<?php
class Relationship_state extends CI_Model {
	
	
	function getIdByAlias($alias) {
		$query = $this->db->select('id')->from('relationship_state')->where('alias', $alias)->limit(1)->get();
		return $query->num_rows() > 0 ? $query->row()->id : 0;
	}
	
	function getAliasById($id) {
		$query = $this->db->select('alias')->from('relationship_state')->where('id', $id)->limit(1)->get();
		return $query->num_rows() > 0 ? $query->row()->alias : NULL;		
	}
	
}?>