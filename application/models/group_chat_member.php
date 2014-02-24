<?php

class Group_chat_member extends CI_Model {

	function __construct() {
		parent::__construct();
		$this->load->library('errorCode'); //used for returning error codes
		$this->load->library('util');
	}


	function add($groupId, $members) {
		$insertData = array();
		foreach ($members as $member)
			$insertData[] = array('group_chat_id'=>$groupId, 'user_id'=>$member);

		$this->db->insert_batch('group_chat_member', $insertData);
	}

	//TODO implement retreiving of many, retreiving of a little, etc
	function retreive() {

	}

	function update($id, $updateData) {

	}

	function delete($groupId, $members) {
		$this->db->where('group_chat_id', $groupId)->where_in('user_id', $members)->delete('group_chat_member');
		return $this->db->where('group_chat_id', $groupId)->count_all_results('group_chat_member');
	}
}
?>