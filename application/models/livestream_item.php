<?php

class Livestream_item extends CI_Model {

	const ARRIVE = "ARRIVE";
	const DEPART = "DEPART";
	const COMMENT = "COMMENT";
	const PHOTO = "PHOTO";
	const VIDEO = "VIDEO";
	const RATING = "RATING";
	
	function __construct() {
		parent::__construct();
		$this->load->library('errorCode'); //used for returning error codes
		$this->load->library('util');
		//$this->db->query("SET time_zone='+0:00'");
	}
	
	function getStreamItems($data) {
		$cleanId = $this->db->escape($data['id']);
		$this->load->model('Relationship_general', 'rel');
		
		$idRangeClause = "";
		$dateRangeClause = "";
		
		if(isset($data['updated_range'])) {
			if($data['updated_range'][1] == -1)
				$dateRangeClause = ' AND event_livestream_item.date > "' .date( 'Y-m-d H:i:s', $data['updated_range'][0]/1000). '"';
			else {
				$sign = $data['updated_range'][0] < $data['updated_range'][1] ? ">" : "<";
				$dateRangeClause = ' AND event_livestream_item.date ' .$sign. ' "' .date( 'Y-m-d H:i:s', $data['updated_range'][0]/1000) .  '" AND event_livestream_item.date ' . ($sign == ">" ? " <=" : " >= ") . ' "' .date( 'Y-m-d H:i:s', $data['updated_range'][1]/1000). '"';
			}				
		} 
			
		if(isset($data['id_range'])) {
			if($data['id_range'][1] == -1) 
				$idRangeClause = ' AND event_livestream_item.id > ' . $data['id_range'][0];
			else {
				$sign = $data['id_range'][0] < $data['id_range'][1] ? ">" : "<";
				$idRangeClause = ' AND event_livestream_item.id ' .$sign. $data['id_range'][0].  ' AND event_livestream_item.id ' . ($sign == ">" ? " <=" : " >= ") . $data['id_range'][1];
			}
		}
		
		//we're only getting new items if id_range[0] < id_range[1] OR id_range[-1]=-1
		//if id_range == 0, then discard this, and just get the most recent items 
		$gettingNew = ((isset($data['id_range']) && $data['id_range'][0] > 0 && ($data['id_range'][0] < $data['id_range'][1] || $data['id_range'][1] == -1))) ||
		((isset($data['updated_range']) && $data['updated_range'][0] > 0 && ($data['updated_range'][0] < $data['updated_range'][1] || $data['updated_range'][1] == -1)));
		
		$sqlQuery = ($gettingNew ? 'select * from (' : '') . 'select event_livestream_item.id, event_id, event_livestream_item.entity_id, if(relationship_general.state_one=' .Relationship_general::TYPE_REL_PERSONAL. ' OR event_livestream_item.entity_id=' .$cleanId.', concat(first_name, " ", last_name),"Someone") as name, (select alias from livestream_type where id=stream_type) as stream_type, if(stream_type !=(select id from livestream_type where alias="COMMENT"), concat(if(relationship_general.state_one=' .Relationship_general::TYPE_REL_PERSONAL. ' OR event_livestream_item.entity_id=' .$cleanId.', concat(first_name, " ", last_name),"Someone"), " ", comment), comment) as  comment, hidden, (unix_timestamp(event_livestream_item.date) * 1000) as date, if(relationship_general.state_one=' .Relationship_general::TYPE_REL_PERSONAL. ' OR event_livestream_item.entity_id=' .$cleanId.', ifnull(image, ""), "") as image, '.
		'source as media from event_livestream_item join person_info on(person_info.entity_id=event_livestream_item.entity_id) left join photo_entity on (photo_entity.owner_id=event_livestream_item.entity_id AND is_profile=1) left join relationship_general on ((id_one=event_livestream_item.entity_id AND id_two=' .$cleanId. ') OR (id_one=' .$cleanId. ' AND id_two=event_livestream_item.entity_id)) left join media_livestream on (event_livestream_item.id=media_livestream.livestream_item_id) ' .
		'where event_id='.$this->db->escape($data['event_id']). $idRangeClause . $dateRangeClause. ' AND hidden=0 order by date ' . ($gettingNew ? 'asc': 'desc') . ', id ' .($gettingNew ? 'asc': 'desc'). ' limit 100' . ($gettingNew ? ') as a order by date desc, id desc' : '') ;
		
		//echo $sqlQuery;
		$result = $this->db->query($sqlQuery);
		return $result->result_array();
	}
	
	function insertNewItem($data) {
		if(!$this->areValuesValid($data)) {
			return errorCode::MISSING_DATA;
		}
		
		$result = $this->db->select('id')->from('event_livestream_item')->where(array("entity_id"=>$data['entity_id'], "event_id"=>$data['event_id'], "stream_type"=>$this->queryLiveStreamTypeIdFromAlias(self::ARRIVE)), NULL, FALSE)->order_by('id asc')->limit(1)->get();
		$row = $result->num_rows() > 0 ? $result->row_array() : null;
		if(isset($row))
			$arriveId = $row['id'];
		
		$result = $this->db->select('id')->from('event_livestream_item')->where(array("entity_id"=>$data['entity_id'], "event_id"=>$data['event_id'], "stream_type"=>$this->queryLiveStreamTypeIdFromAlias(self::DEPART)), NULL, FALSE)->order_by('id asc')->limit(1)->get();
		$row = $result->num_rows() > 0 ? $result->row_array() : null;
		if(isset($row))
			$departId = $row['id'];
		
		//if it's a rating, submit the rating first
		if($data['alias'] == self::RATING) {
			$this->load->model('Event_rating', 'rating');
			$this->rating->updateUserRating($data['entity_id'], $data['event_id'], $data['rating']);
		}

		if($data['alias']==self::ARRIVE && isset($arriveId)) {
			//TODO we'll prolly be able to remove this later. But for now, just delete any duplicate arrivals
			$this->db->where(array("entity_id"=>$data['entity_id'], "event_id"=>$data['event_id'], "stream_type"=>$this->queryLiveStreamTypeIdFromAlias(self::ARRIVE), "id !="=>$arriveId), NULL, FALSE);
			$this->db->delete('event_livestream_item');
			//if we've departed from this event before, then just hide those results in the db
			if(isset($departId)) {
				$this->db->where(array("entity_id"=>$data['entity_id'], "event_id"=>$data['event_id'], "stream_type"=>$this->queryLiveStreamTypeIdFromAlias(self::DEPART)), NULL, FALSE); //hide that we had previously left;
				$this->db->update('event_livestream_item', array("hidden"=>TRUE));
				errorCode::logError("okay", "event_livestream_item");
			}
			
			$this->db->select('event_livestream_item.id, event_livestream_item.event_id, event_livestream_item.entity_id, concat(first_name, " ", last_name) as name, (select alias from livestream_type where id=stream_type) as stream_type, if(stream_type !=(select id from livestream_type where alias="COMMENT"), concat(first_name, " ", last_name, " ", comment), comment) as  comment, hidden, (unix_timestamp(event_livestream_item.date) * 1000) as date, ifnull(image, "' .$this->util->getDefaultImage(util::PERSON_INFO). '") as image, source as media', FALSE);
			$this->db->from('event_livestream_item')->join('person_info', 'person_info.entity_id=event_livestream_item.entity_id')->join('photo_entity', 'photo_entity.owner_id=event_livestream_item.entity_id AND is_profile=1', 'left')->join('media_livestream', 'event_livestream_item.id=media_livestream.livestream_item_id', 'left')->where('event_livestream_item.id', $arriveId)->order_by('id', 'desc')->limit(1);
			$result = $this->db->get(); //get the last message;
			
		} else if($data['alias'] == self::DEPART && isset($departId)) {
			//TODO we'll prolly be able to remove this later. But for now, just delete any duplicate arrivals
			$this->db->where(array("entity_id"=>$data['entity_id'], "event_id"=>$data['event_id'], "stream_type"=>$this->queryLiveStreamTypeIdFromAlias(self::DEPART), "id !="=>$departId), NULL, FALSE);
			$this->db->delete('event_livestream_item');
			
			//updated the current row that corresponds to our departure. unhide it & update its times
			$this->db->where(array("entity_id"=>$data['entity_id'], "event_id"=>$data['event_id'], "stream_type"=>$this->queryLiveStreamTypeIdFromAlias(self::DEPART)), NULL, FALSE); //hide that we had previously left;
			$this->db->update('event_livestream_item', array("hidden"=>FALSE, "date"=>date('Y-m-d H:i:s', time())));
			
			
			$this->db->select('event_livestream_item.id, event_livestream_item.event_id, event_livestream_item.entity_id, concat(first_name, " ", last_name) as name, (select alias from livestream_type where id=stream_type) as stream_type, if(stream_type !=(select id from livestream_type where alias="COMMENT"), concat(first_name, " ", last_name, " ", comment), comment) as  comment, hidden, (unix_timestamp(event_livestream_item.date) * 1000) as date, ifnull(image, "' .$this->util->getDefaultImage(util::PERSON_INFO). '") as image, source as media', FALSE);
			$this->db->from('event_livestream_item')->join('person_info', 'person_info.entity_id=event_livestream_item.entity_id')->join('photo_entity', 'photo_entity.owner_id=event_livestream_item.entity_id AND is_profile=1', 'left')->join('media_livestream', 'event_livestream_item.id=media_livestream.livestream_item_id', 'left')->where('event_livestream_item.id', $departId)->order_by('id', 'desc')->limit(1);
			$result = $this->db->get(); //get the last message;
			
		} else {
			$this->db->set('event_id', $data['event_id']);
			$this->db->set('entity_id', $data['entity_id']);
			$this->db->set('stream_type', $this->queryLiveStreamTypeIdFromAlias($data['alias']), FALSE);
			$this->db->set('comment', $data['alias'] ==self::COMMENT ? $data['comment'] : ($data['alias']== self::RATING ? $this->createCommentFromAlias($data['alias'], $data['rating']) : $this->createCommentFromAlias($data['alias'])));
			$this->db->insert('event_livestream_item');
			
			$this->db->select('event_livestream_item.id, event_livestream_item.event_id, event_livestream_item.entity_id, concat(first_name, " ", last_name) as name, (select alias from livestream_type where id=stream_type) as stream_type, if(stream_type !=(select id from livestream_type where alias="COMMENT"), concat(first_name, " ", last_name, " ", comment), comment) as  comment, hidden, (unix_timestamp(event_livestream_item.date) * 1000) as date, ifnull(image, "' .$this->util->getDefaultImage(util::PERSON_INFO). '") as image, source as media', FALSE);
			$this->db->from('event_livestream_item')->join('person_info', 'person_info.entity_id=event_livestream_item.entity_id')->join('photo_entity', 'photo_entity.owner_id=event_livestream_item.entity_id AND is_profile=1', 'left')->join('media_livestream', 'event_livestream_item.id=media_livestream.livestream_item_id', 'left')->order_by('id', 'desc')->limit(1);
			$result = $this->db->get(); //get the last message;
		}

 		if($result->num_rows() > 0) { 			
 			$row = $result->row_array();
 			
 			if($row['stream_type'] == self::PHOTO || $row['stream_type'] == self::VIDEO) {
 				$this->load->model('Photo', 'photo');
 				$this->photo->addPhoto('media_livestream', array('livestream_item_id'=>$row['id'], 'source'=>$data['file']));
 				$row['media'] = $data['file'];
 			}
 			
 			return $row;
 		} else {
 			return errorCode::MESSAGE_FAILED;
 		}
	}
	
	function getHiddenDepartureIdForEntity($entityId) {
		$result = $this->db->select('id')->from('event_livestream_item')->where(array('event_livestream_item.entity_id'=>$entityId, 'hidden'=>TRUE))->get();
		if($result->num_rows() > 0) {
			$row = $result->row_array();
			return $row['id'];
		} else
			return false;
		return $result->num_rows() > 0 ? $result->row_array() : false;	
	}
	
	private function areValuesValid($data) {
		switch($data['alias']) {
			case self::COMMENT:
				return isset($data['comment']) && strlen($data['comment']) > 0;
				break;
			case self::PHOTO:
			case self::VIDEO:
				return isset($data['file']) && strlen($data['file']) > 0; //check that we've attached a file link
				break;
			case self::RATING:
				return isset($data['rating']) && is_numeric($data['rating']);
		}
		
		return true;
	}
	
	private function queryLiveStreamTypeIdFromAlias($alias) {
		return "(select id from livestream_type where alias='" . $alias . "')"; 
	}
	
	private function createCommentFromAlias($alias, $rating = NULL) {
		switch($alias) {
			case self::ARRIVE:
				$comment = "has entered the event";
				break;
			case self::DEPART:
				$comment = "has left the event";
				break;
			case self::PHOTO:
				$comment = "has added a photo at this event";
				break;
			case self::VIDEO:
				$comment = "has added a video at this event";
				break;
			case self::RATING:
				$comment = "has given this event a rating of " . $rating;
				break;
		}
		
		return $comment;
	}

}
?>