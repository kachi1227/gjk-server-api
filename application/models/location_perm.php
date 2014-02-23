<?php
class Location_perm extends CI_Model {
	
	const MIN_IN_SECONDS = 60;

	function __construct() {
		parent::__construct();
		$this->load->library('errorCode'); //used for returning error codes
		$this->load->library('util');
		//$this->db->query("SET time_zone='+0:00'");
	}
	/*
	 * instead of doing an insert, we want to replace. we can only have 1 location!!
	 */
	function addLocation($array) {
		
		$data = array('id'=>$array['id'], 'lat'=>$array['lat'], 'lon'=>$array['lon'], 'address'=>(isset($array['address']) ? $array['address'] : ''));
		
		$result = $this->db->select()->from('location_perm')->where('id', $data['id'])->limit(1)->get();
		//var_dump($result);
		if($result->num_rows() > 0) {
			$loc = $result->row_array();
			$this->db->where('id', $data['id']);
			$this->db->update('location_perm', $data);
			
		} else {
			$this->db->insert('location_perm', $data);
			errorCode::logError(errorCode::ERROR_DATABASE, $this->db->_error_number(), $this->db->_error_message(), "location_perm");
		}
		$result = $this->db->select('id, lat, lon')->from('location_perm')->where('id', $data['id'])->limit(1)->get();
		if($result->num_rows() > 0) {
			return $result->row_array();
		} else 
			return false;
	}
	
	//order by date added, order descending. 
	function getMostRecentLocation($id) {
		
	}
}
?>