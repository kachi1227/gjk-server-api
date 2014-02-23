<?php

class Moment extends CI_Model {
	
	const FLAG_SENDER					= 1; //the sender of the original event or message that started the thread
	const FLAG_RECIPIENT				= 2; //anybody else involved in thread that is NOT the original sender
	const TYPE_INTERACTION 				= "INTERACTION";
	const TYPE_EVENT					= "EVENT";
	const TYPE_PHOTO					= "PHOTO";
	
	function __construct() {
		parent::__construct();
		$this->load->library('errorCode'); //used for returning error codes
		$this->load->library('util');
	}

	
	function addMoment($momentData) {
		$cleanId = $this->db->escape($momentData['id']);
		$cleanMomentId = $this->db->escape($momentData['moment_id']);
		
        
		//take this id. find all where datatype = the same as this and table_id= the same as this, and update its moment flag IF Sender
		//if it is recipient then find the min id where table_id= same as root_id and recipient=recipient_id
		switch($momentData['type']) {
			case self::TYPE_INTERACTION:
                $this->db->query('update message join (select message.id, root_sender_id, message.recipient_id, message.table_id from message join (select * from message join (select min(id) as root_id, sender_id as root_sender_id, datatype_id as root_datatype, table_id as root_table_id from message group by root_datatype, root_table_id) as root_message on (message.root_message_id=root_id OR (root_message_id is NULL AND message.table_id=root_table_id AND message.datatype_id=root_datatype)) where id=' .$cleanMomentId.') as moments on (message.root_message_id=root_id OR (message.table_id=root_table_id AND message.datatype_id=root_datatype)) where (message.sender_id=' .$cleanId. ' OR message.recipient_id=' .$cleanId.') order by message.id asc limit 1) as update_row on (update_row.id=message.id) set message.moment_flag=message.moment_flag|(if(root_sender_id=' .$cleanId. ',' .self::FLAG_SENDER. ',' .self::FLAG_RECIPIENT. '))');
				break;
			case self::TYPE_EVENT:
				$eventRow =$this->db->select('creator_id')->from('event')->where('id', $cleanMomentId)->limit(1)->get()->row_array();
				if($eventRow['creator_id'] == $cleanId)
					$this->db->query('update event set is_creators_moment=is_creators_moment |' .self::FLAG_SENDER. ' where id=' .$cleanMomentId);
				else {
					$this->db->query('update event_rsvp set is_moment=is_moment |' .self::FLAG_RECIPIENT. ' where event_id=' .$cleanMomentId. ' AND entity_id=' .$cleanId);
				}					 
				break;
			case self::TYPE_PHOTO:
				break;
		}
	}
	
	function removeMoment($momentData) {
		$cleanId = $this->db->escape($momentData['id']);
		$cleanMomentId = $this->db->escape($momentData['moment_id']);
		switch($momentData['type']) {
			case self::TYPE_INTERACTION:
                $this->db->query('update message join (select message.id, root_sender_id, message.recipient_id, message.table_id from message join (select * from message join (select min(id) as root_id, sender_id as root_sender_id, datatype_id as root_datatype, table_id as root_table_id from message group by root_datatype, root_table_id) as root_message on (message.root_message_id=root_id OR (root_message_id is NULL AND message.table_id=root_table_id AND message.datatype_id=root_datatype)) where id=' .$cleanMomentId.') as moments on (message.root_message_id=root_id OR (message.table_id=root_table_id AND message.datatype_id=root_datatype)) where (message.sender_id=' .$cleanId. ' OR message.recipient_id=' .$cleanId.') order by message.id asc limit 1) as update_row on (update_row.id=message.id) set message.moment_flag=message.moment_flag & (if(root_sender_id=' .$cleanId. ', ~' .self::FLAG_SENDER. ', ~' .self::FLAG_RECIPIENT. '))');
				break;
			case self::TYPE_EVENT:
				$eventRow =$this->db->select('creator_id')->from('event')->where('id', $cleanMomentId)->limit(1)->get()->row_array();
				if($eventRow['creator_id'] == $cleanId)
					$this->db->query('update event set is_creators_moment=is_creators_moment & ~' .self::FLAG_SENDER. ' where id=' .$cleanMomentId);
				else
					$this->db->query('update event_rsvp set is_moment=is_moment & ~' .self::FLAG_RECIPIENT. ' where event_id=' .$cleanMomentId. ' AND entity_id=' .$cleanId);
				break;
			case self::TYPE_PHOTO:
				break;
		}
	}
	
	//two cases:
	
	//1. when the moment has root_message_id == NULL, but is not the minimum. then we have to find that minimum to return as the root_message_id FOR fetchMoments.
	
	//2. when the moment has root_message_id != NULL, then we have to pass the root_moment_id as the moment_id

	/*
	 * (select event.id as moment_id, event.name as moment_name, creator_id as sender_id, sender.name as sender_name, event_rsvp.entity_id as recipient_id, (select concat(first_name, " ", last_name) from person_info where event_rsvp.entity_id=person_info.entity_id) as recipient_name, "EVENT" as moment_type, "EVENT" as datatype, ifnull(image, "") as image, "" as misc, unix_timestamp(start_time) * 1000 as date from event join ((select entity_id as id, concat(first_name, " ", last_name) as name from person_info) union all (select entity_id as id, name from business_info) union all (select entity_id as id, name from organization_info)) as sender on sender.id=creator_id left join event_rsvp on (event_rsvp.event_id=event.id AND entity_id=24) left join photo_event on (photo_event.event_id=event.id AND is_flyer=1) where (creator_id=24 AND is_creators_moment & 1 > 0) OR (entity_id=24 AND is_moment & 2 > 0)) union all (select root_id as moment_id, datatype.name as moment_name, root_sender_id as sender_id, sender.name as sender_name, if(root_sender_id != 24 AND sender_id=24, sender_id, recipient_id) as recipient_id, recipient.name as recipient_name, "INTERACTION" as moment_type, datatype.alias as datatype, "" as image, (select group_concat(distinct image separator "|") from message left join photo_entity on (photo_entity.owner_id=sender_id and is_profile=1) where message.id=moment_id OR root_message_id=moment_id) as misc, unix_timestamp(date) * 1000 as date from (select * from message join (select min(id) as root_id, sender_id as root_sender_id, datatype_id as root_datatype, table_id as root_table_id from message group by root_datatype, root_table_id) as root_message on (message.root_message_id=root_id OR (root_message_id is NULL AND message.table_id=root_table_id AND message.datatype_id=root_datatype))) as message join (((select entity_id as id, concat(first_name, " ", last_name) as name from person_info) union all (select entity_id as id, name from business_info) union all (select entity_id as id, name from organization_info)) as sender, datatype) on (datatype.id=datatype_id AND root_sender_id=sender.id) left join ((select entity_id as id, concat(first_name, " ", last_name) as name from person_info) union all (select entity_id as id, name from business_info) union all (select entity_id as id, name from organization_info)) as recipient on recipient_id=recipient.id where (moment_flag &1 > 0 AND root_sender_id=24) OR (root_sender_id != 24 AND moment_flag &2 > 0 AND recipient_id=24)) order by date desc\G;
	 * 
	 * 
	 */
	function fetchMoments($data) {
		$cleanId = $this->db->escape($data['id']);
		
		//TODO have to do it with a select person as well!
		
		
		//TODO add recipient name and sender name. what if we want to add a moment that we created?

		$query = '(select event.id as moment_id, event.name as moment_name, creator_id as sender_id, sender.name as sender_name, event_rsvp.entity_id as recipient_id, (select concat(first_name, " ", last_name) from person_info where event_rsvp.entity_id=person_info.entity_id) as recipient_name, "EVENT" as moment_type, "EVENT" as datatype, ifnull(image, "") as image, "" as misc, unix_timestamp(start_time) * 1000 as date from event '.
		'join ((select entity_id as id, concat(first_name, " ", last_name) as name from person_info) union all (select entity_id as id, name from business_info) union all (select entity_id as id, name from organization_info)) as sender on sender.id=creator_id left join event_rsvp on (event_rsvp.event_id=event.id AND entity_id=' .$cleanId. ') left join photo_event on (photo_event.event_id=event.id AND is_flyer=1) where (creator_id=' .$cleanId. 
		' AND is_creators_moment & ' .self::FLAG_SENDER. ' > 0) OR (entity_id=' .$cleanId. ' AND is_moment & ' .self::FLAG_RECIPIENT. ' > 0)) union all (select root_id as moment_id, datatype.name as moment_name, root_sender_id as sender_id, sender.name as sender_name, if(root_sender_id != ' .$cleanId. ' AND sender_id=' .$cleanId. ', sender_id, recipient_id) as recipient_id, recipient.name as recipient_name, "INTERACTION" as moment_type, '.
		'datatype.alias as datatype, "" as image, (select group_concat(distinct image separator "|") from message left join photo_entity on (photo_entity.owner_id=sender_id and is_profile=1) where message.id=moment_id OR root_message_id=moment_id) as misc, unix_timestamp(date) * 1000 as date from (select * from message join (select min(id) as root_id, sender_id as root_sender_id, datatype_id as root_datatype, table_id as root_table_id '.
		'from message group by root_datatype, root_table_id) as root_message on (message.root_message_id=root_id OR (root_message_id is NULL AND message.table_id=root_table_id AND message.datatype_id=root_datatype))) as message join (((select entity_id as id, concat(first_name, " ", last_name) as name from person_info) union all (select entity_id as id, name from business_info) union all (select entity_id as id, name from organization_info)) as sender, datatype) '.
		'on (datatype.id=datatype_id AND root_sender_id=sender.id) left join ((select entity_id as id, concat(first_name, " ", last_name) as name from person_info) union all (select entity_id as id, name from business_info) union all (select entity_id as id, name from organization_info)) as recipient on recipient_id=recipient.id where (moment_flag & ' .self::FLAG_SENDER. ' > 0 AND root_sender_id=' .$cleanId. ') OR (root_sender_id != ' .$cleanId. ' AND moment_flag & ' 
		.self::FLAG_RECIPIENT. ' > 0 AND (recipient_id=' .$cleanId. ' OR sender_id=' .$cleanId.'))) order by date desc';
		//echo $query;
		
// 		$query = '(select event.id as moment_id, event.name as moment_name, creator_id as sender_id, sender.name as sender_name, event_rsvp.entity_id as recipient_id, (select concat(first_name, " ", last_name) from person_info where event_rsvp.entity_id=person_info.entity_id) as recipient_name, "' .self::TYPE_EVENT. '" as moment_type, "EVENT" as datatype, ifnull(image, "") as image, "" as misc, unix_timestamp(start_time) * 1000 as date from event '.
// 		'join ((select entity_id as id, concat(first_name, " ", last_name) as name from person_info) union all (select entity_id as id, name from business_info) union all (select entity_id as id, name from organization_info)) as sender on sender.id=creator_id ' .
// 		'left join event_rsvp on (event_rsvp.event_id=event.id AND entity_id=' .$cleanId. ') left join photo_event on (photo_event.event_id=event.id AND is_flyer=1) where (creator_id=' .$cleanId. ' AND is_creators_moment & ' .self::FLAG_SENDER. ' > 0) OR (entity_id=' .$cleanId. ' AND is_moment & ' .self::FLAG_RECIPIENT. ' > 0)) union all ' .
// 		'(select message.id as moment_id, datatype.name as moment_name, sender_id, sender.name as sender_name, recipient_id, recipient.name as recipient_name, "' .self::TYPE_INTERACTION. '" as moment_type, datatype.alias as datatype, "" as image, (select group_concat(distinct image separator "|") from message left join photo_entity on (photo_entity.owner_id=sender_id and is_profile=1) where message.id=moment_id OR root_message_id=moment_id) as misc, unix_timestamp(date) * 1000 as date from message ' .
// 		'join (((select entity_id as id, concat(first_name, " ", last_name) as name from person_info) union all (select entity_id as id, name from business_info) union all (select entity_id as id, name from organization_info)) as sender, datatype) on (datatype.id=datatype_id AND sender_id=sender.id) '.
// 		'left join ((select entity_id as id, concat(first_name, " ", last_name) as name from person_info) union all (select entity_id as id, name from business_info) union all (select entity_id as id, name from organization_info)) as recipient on recipient_id=recipient.id '.
// 		'where (moment_flag &' .self::FLAG_SENDER. ' > 0 AND sender_id=' .$cleanId. ') OR (moment_flag &' .self::FLAG_RECIPIENT. ' > 0 AND recipient_id=' .$cleanId. ') AND root_message_id is null) order by date desc';
		//echo $query;
		return $this->db->query($query)->result_array();
		
	}
	
	function getMomentFlagForInteraction($id, $interactionId) {
		$cleanId = $this->db->escape($id);
		$cleanInteractionId = $this->db->escape($interactionId);
		$query = 'select case when root_sender_id=' .$cleanId. ' AND message.moment_flag & ' . moment::FLAG_SENDER. '> 0 then message.moment_flag when root_sender_id !=' .$cleanId. ' AND message.recipient_id=' .$cleanId. ' AND message.moment_flag & ' . moment::FLAG_RECIPIENT. '> 0 then message.moment_flag else 0 end as moment_flag from message join (select * from message join (select min(id) as root_id, sender_id as root_sender_id, datatype_id as root_datatype, table_id as root_table_id from message group by root_datatype, root_table_id) as root_message on (message.root_message_id=root_id OR (root_message_id is NULL AND message.table_id=root_table_id AND message.datatype_id=root_datatype)) where id=' .$cleanInteractionId.') as moments on (message.root_message_id=root_id OR (message.table_id=root_table_id AND message.datatype_id=root_datatype)) where (message.sender_id=' .$cleanId. ' OR message.recipient_id=' .$cleanId.') order by message.id asc limit 1';
		$result = $this->db->query($query);
		if($result->num_rows() > 0) {
			$row = $result->row_array();
			return $row['moment_flag'];
		} else
			return null;	
	}
}
?>