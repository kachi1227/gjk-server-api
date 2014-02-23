<?php

class Bucket_list_item extends CI_Model {

	function __construct() {
		parent::__construct();
		$this->load->library('errorCode'); //used for returning error codes
		$this->load->library('util');
	}
	
	function fetchBucketListItems($data) {
				
		$whereArray = array('entity_id'=>$data['id']);
		if(isset($data['last_id'])) {
			$whereArray['bucket_list_item.id >'] = $data['last_id'];
		}
		
		if(isset($data['last_updated'])) {
			$whereArray['date_updated >'] = date( 'Y-m-d H:i:s', $data['last_updated']/1000);
		}
		
		$this->db->select('id, entity_id, title, priority, completed, ifnull(image, "") as image, (unix_timestamp(date_updated) * 1000) as date_updated', FALSE);
		$this->db->from('bucket_list_item')->where($whereArray)->order_by('id', 'asc');
		$result = $this->db->get()->result_array(); //get the last bucket list item for user;
		
		$tags = $this->db->select('bucket_list_item_id, tag_id as id, alias, name')->from('bucket_list_item_tag_activity')->join('bucket_list_item', 'bucket_list_item.id=bucket_list_item_id')->join('tag_activity', 'tag_id=tag_activity.id')->where($whereArray)->order_by('bucket_list_item_id, name asc')->get()->result_array();
		for($i=0, $size=count($result); $i < $size; $i++) {
			$id = $result[$i]['id'];
			$tagArray = array();
			foreach ($tags as $key=>$tag) {
				if($tag['bucket_list_item_id'] != $id) //lets do not equal to be safe. it's ordered, so it should be greater than, but w/e
					break;
				unset($tag['bucket_list_item_id']); //remove bucket list id, we dont need it. 
				$tagArray[] = $tag;
				unset($tags[$key]); //remove thte tag	
			}
			
			$result[$i]['tags'] = $tagArray;
		}
		return $result;
	}
	
	function insertNewItem($data) {
		if(isset($data['tags']))
			unset($data['tags']);
		
		$updateData = array('priority'=> 'priority + 1');
		
		$updateQuery = "update bucket_list_item set priority = priority + 1 where priority >=" .$data['priority']. ' AND entity_id='.$data['entity_id'];
		$this->db->query($updateQuery);
		
		errorCode::logError("update", "bucket_list_item");
		$this->db->insert('bucket_list_item', $data);
		
		
		$this->db->select('id, entity_id, title, priority, completed, ifnull(image, "") as image, (unix_timestamp(date_updated) * 1000) as date_updated', FALSE);
		$this->db->from('bucket_list_item')->where(array("entity_id"=>$data['entity_id'], "priority"=>$data['priority']))->order_by('id', 'desc')->limit(1);
		$result = $this->db->get(); //get the last bucket list item for user;

		
		return $result->num_rows() > 0 ? $result->row_array() : false;
	}
	
	function editInfo($data) {
		$entityId = $this->db->escape($data['id']);
		$bucketListItemId = $this->db->escape($data['bucket_list_item_id']);
		$this->load->model('Tag', 'tag');
	
		//deal with any tag modifications we may have made
		if(isset($data['tags'])) {
			
			$this->tag->modifyTags($bucketListItemId, 'bucket_list_item', $data['tags']['added'], $data['tags']['removed']);
			unset($data['tags']);
		}
		
		if(isset($data['priority'])) {
			$newPriority = $this->db->escape($data['priority']);
			
			
			$row = $this->db->select('priority')->from('bucket_list_item')->where("id", $data['bucket_list_item_id'])->get()->row_array();
			
			$currPriority = $row['priority'];
			
			$updateQuery = 'update bucket_list_item set priority = case when ' .$newPriority. ' < ' .$currPriority. ' AND priority < ' .$currPriority. ' AND priority >=' .$newPriority. ' then priority + 1 '.
					'when ' .$newPriority. ' > '. $currPriority. ' AND priority > ' .$currPriority. ' AND priority <=' .$newPriority. ' then priority - 1 '.
					'else priority end where entity_id='.$entityId;

			$this->db->query($updateQuery);
			errorCode::logError("database", "bucket_list_item");
		}
		
		unset($data['id']);
		unset($data['bucket_list_item_id']);
	
		//now we're ready
		if(count($data) > 0) {
			$data['date_updated'] = date('Y-m-d H:i:s', time());
			$this->db->where('id', $bucketListItemId)->update('bucket_list_item', $data);
		}
		
		$this->db->select('id, entity_id, title, priority, completed, ifnull(image, "") as image, (unix_timestamp(date_updated) * 1000) as date_updated', FALSE);
		$this->db->from('bucket_list_item')->where(array("id"=>$bucketListItemId))->order_by('id', 'desc')->limit(1);
		$result = $this->db->get()->row_array();
		$result['tags'] =  $this->tag->getBucketListTags($bucketListItemId);;
		return $result;
	}
	
	function manuallyPrioritizeItems($id, $priorityMap) {
		$id = $this->db->escape($id);
		
		$caseStatement = "";
		foreach($priorityMap as $key=>$value) {
			$caseStatement .= ("when id=".$key. " then " .$value. " ");
		}
		$dateUpdated = date('Y-m-d H:i:s', time());
		$updateQuery = 'update bucket_list_item set priority = case ' .$caseStatement. ' else priority end, date_updated="' .$dateUpdated. '" where entity_id='.$id;
		$this->db->query($updateQuery);
	}
}
?>