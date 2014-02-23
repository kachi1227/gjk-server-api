<?php
class Photo_id extends CI_Model {

	function addPhoto($data) {
		return $this->db->insert('photo_id', $data);
	}

	function getId($id) {
		$result = $this->db->select('image')->from('photo_id')->where(array('owner_id'=>$id))->limit(1)->get();
		if($result->num_rows() > 0) {				
			$photo = $result->row();
			return $photo->image;
		}
		return false;
	}
}
?>