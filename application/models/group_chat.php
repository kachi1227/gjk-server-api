<?php

class Group_chat extends CI_Model {
	
	function __construct() {
		parent::__construct();
		$this->load->library('errorCode'); //used for returning error codes
		$this->load->library('util');
	}
	
	
	function create($data) {
		$data['date_created'] = date( 'Y-m-d H:i:s', time());
		if($this->db->insert('group_chat', $data)) {
			$query = $this->db->select('group_chat.id, name, creator_id, first_name, last_name')->from('group_chat')->join('user', 'creator_id=user.id')->
				where(array('creator_id'=>$data['creator_id'], 'date_created'=>$data['date_created']))->order_by('id', 'desc')->limit(1)->get();
			if($query->num_rows() > 0)
				return $query->row_array();
			else
				errorCode::logError(errorCode::ERROR_DATABASE, $this->db->_error_number(), $this->db->_error_message(), "group_chat");
				
		} else
			return errorCode::logError(errorCode::ERROR_DATABASE, $this->db->_error_number(), $this->db->_error_message(), "group_chat");
		
	}
	
	//TODO implement retreiving of many, retreiving of a little, etc
	function retreive() {
		
	}
	
	function update($id, $updateData) {
		return $this->db->where('id', $id)->update('group_chat', $updateData);
	}
	
	function delete($id) {
		return $this->db->delete('group_chat', array('id' => $id)); 
	}
}
?>