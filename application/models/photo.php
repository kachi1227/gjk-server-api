<?php
class Photo extends CI_Model {

	function addPhoto($tableName, $data) {
		return $this->db->insert($tableName, $data);
	}

	function getProfilePicture($id) {
		$result = $this->db->select('image')->from('photo_entity')->where(array('owner_id'=>$id, 'is_profile'=> true))->limit(1)->get();
		if($result->num_rows() > 0) {				
			$photo = $result->row();
			return $photo->image;
		}
		return false;
	}
	
	function removeProfilePicture($id) {
		//TODO not sure if we should actually delete that photo file??
		$this->db->where(array('owner_id'=>$id, 'is_profile'=>1));
		$this->db->update('photo_entity', array('is_profile'=>0));
	}
	
	function removeEventFlyer($id) {
		//TODO not sure if we should actually delete that photo file??
		$this->db->where(array('event_id'=>$id, 'is_flyer'=>1));
		$this->db->update('photo_event', array('is_flyer'=>0));
	}
	
	
}
?>