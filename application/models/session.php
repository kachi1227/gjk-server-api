<?php
class Session extends CI_Model {

	const LIFETIME = 2592000;
	
	function __construct() {

		session_set_save_handler(
				array($this, "open"),
				array($this, "close"),
				array($this, "read"),
				array($this, "write"),
				array($this, "destroy"),
				array($this, "gc"));
		register_shutdown_function('session_write_close');
	}

	function open( $save_path, $session_name ) {

		global $sess_save_path;
		$sess_save_path = $save_path;
		// Don't need to do anything. Just return TRUE.
		return true;
	}

	function close() {
		return true;
	}

	function read($id) {
		$result = $this->db->select('data')->from('user_session')->where(array('id'=>$id))->get();
		return $result->num_rows() > 0 ? $result->row()->data : '';
	}

	function write( $id, $data ) {
		$cleanId = $this->db->escape($id);
		$cleanData = $this->db->escape($data);
		$insertQuery = 'insert into user_session values('.$cleanId. ', ' .$cleanData. ', now()) on duplicate key update data=VALUES(data), last_accessed=now()';
		$this->db->query($insertQuery);
		return true;
	}

	function destroy( $id ) {
		$this->db->delete('user_session', array('id' => $id));
		return true;
	}

	function gc() {
		$deleteQuery = 'delete from user_session where (select unix_timestamp(now()) - unix_timestamp(last_accessed)) > '.self::LIFETIME;
		$this->db->query($deleteQuery);
		return true;

	}
	
	function isValidSessionId($id) {
		$result = $this->db->select('data')->from('user_session')->where(array('id'=>$id))->get();
		return $result->num_rows() > 0;
	}

}
?>