<?php

class Location_chat extends CI_Model {
	
	const FLAG_FIRST_NAME = 1;
	const FLAG_LAST_NAME = 2;
	const FLAG_IMAGE = 4;
	const FLAG_MILU_NUMBER = 8;
	
	const UPDATE_MESSAGE = 1;
	const UPDATE_PARTICIPANT = 2;
	const UPDATE_REVEAL = 4;
	
	function __construct() {
		parent::__construct();
		$this->load->library('errorCode'); //used for returning error codes
		$this->load->library('util');
	}
	
	function getMessages($data) {
		$this->load->model('Relationship_general', 'rel');
		$cleanId = $this->db->escape($data['id']);
	
		$idRangeClause = "";
	
	
		if(isset($data['id_range'])) {
			if($data['id_range'][1] == -1)
				$idRangeClause = ' AND location_chat_message.id > ' . $data['id_range'][0];
			else {
				$sign = $data['id_range'][0] < $data['id_range'][1] ? ">" : "<";
				$idRangeClause = ' AND location_chat_message.id ' .$sign. $data['id_range'][0].  ' AND location_chat_message.id ' . ($sign == ">" ? " <=" : " >= ") . $data['id_range'][1];
			}
		}
	
		//we're only getting new items if id_range[0] < id_range[1] OR id_range=-1
		//if id_range == 0, then discard this, and just get the most recent items
		$gettingNew = (isset($data['id_range']) && $data['id_range'][0] > 0 && ($data['id_range'][0] < $data['id_range'][1] || $data['id_range'][1] == -1));
	
		$sqlQuery = ($gettingNew ? 'select * from (' : '') . 'select location_chat_message.id, (select entity_id from business_info where id=location_chat_message.business_id) as business_id, location_chat_message.entity_id, '.
				'if(( (relationship_general.state_one & ' .Relationship_general::TYPE_REL_BLOCKED. ' = 0 AND relationship_general.state_two & ' .Relationship_general::TYPE_REL_BLOCKED. ' = 0) OR location_chat_message.entity_id =' .$cleanId. ' OR location_chat_message.entity_id=(select entity_id from business_info where id=location_chat_message.business_id)) OR if(relationship_anonymous.id_one=' .$cleanId. ', flag_two, flag_one) & ' .Location_chat::FLAG_MILU_NUMBER. ' > 0, milu_number, "") as milu_number, '.
				'case when location_chat_message.entity_id !=' .$cleanId. ' AND location_chat_message.entity_id != (select entity_id from business_info where id=location_chat_message.business_id) AND ((relationship_general.state_one is null AND relationship_general.state_two is null) OR (relationship_general.state_one &' .Relationship_general::TYPE_REL_BLOCKED. ' > 0 OR relationship_general.state_two &' .Relationship_general::TYPE_REL_BLOCKED. ' > 0)) then '.
				'if(if(relationship_anonymous.id_one=' .$cleanId. ', flag_two, flag_one) & ~'. Location_chat::FLAG_IMAGE. ' & ~' .Location_chat::FLAG_MILU_NUMBER. '  > 0, concat(if(if(relationship_anonymous.id_one=' .$cleanId. ',flag_two, flag_one) & ' .Location_chat::FLAG_FIRST_NAME.
				' > 0, concat(first_name, " "), ""), if(if(relationship_anonymous.id_one=' .$cleanId. ',flag_two, flag_one) & ' .Location_chat::FLAG_LAST_NAME. ' > 0, last_name, "")), anonymous_id) else concat(first_name, " ", last_name) end as name, '.
				'entity_type, content, (unix_timestamp(date) * 1000) as date, ifnull(if(relationship_anonymous.id_one=' .$cleanId. ', flag_two, flag_one), -1) as flag, '. 
				'if(( (relationship_general.state_one & ' .Relationship_general::TYPE_REL_BLOCKED. ' = 0 AND relationship_general.state_two & ' .Relationship_general::TYPE_REL_BLOCKED. ' = 0) OR location_chat_message.entity_id =' .$cleanId. ' OR location_chat_message.entity_id=(select entity_id from business_info where id=location_chat_message.business_id)) OR if(relationship_anonymous.id_one=' .$cleanId. ', flag_two, flag_one) & ' .Location_chat::FLAG_IMAGE. ' > 0, ifnull(image, ""), "") as image '.
				
				'from location_chat_message join (entity, ((select entity_id as id, first_name, last_name from person_info) union all (select entity_id as id, name as first_name, "" as last_name from business_info) union all (select entity_id as id, name as first_name, "" as last_name from organization_info)) as name) '.
				'on (location_chat_message.entity_id=name.id AND location_chat_message.entity_id=entity.id) left join location_chat_user on (location_chat_message.entity_id=location_chat_user.patron_id)' .
				'left join photo_entity on (location_chat_message.entity_id=photo_entity.owner_id AND is_profile=1) left join relationship_general on ((relationship_general.id_one=location_chat_message.entity_id AND relationship_general.id_two=' .$cleanId. ') OR (relationship_general.id_one=' .$cleanId. ' AND relationship_general.id_two=location_chat_message.entity_id)) '.
				'left join relationship_anonymous on  ((relationship_anonymous.id_one=location_chat_message.entity_id AND relationship_anonymous.id_two=' .$cleanId. ') OR (relationship_anonymous.id_one=' .$cleanId. ' AND relationship_anonymous.id_two=location_chat_message.entity_id)) '.
				'where business_id=(select id from business_info where entity_id='.$this->db->escape($data['business_id']). ')'  .$idRangeClause . ' order by date ' . ($gettingNew ? 'asc': 'desc') . ' limit 100' . ($gettingNew ? ') as a order by date desc' : '') ;

		
		//echo $sqlQuery;
		$result = $this->db->query($sqlQuery);
		return $result->result_array();
	}
	
	function insertMessage($data) {
		//print_r($data);
		
		$this->db->set('business_id', "(select id from business_info where entity_id='" . $data['business_id'] . "')", FALSE);
		$this->db->set('entity_id', $data['entity_id']);
		$this->db->set('content', $data['content']);
		$this->db->insert('location_chat_message');
	
		
		$query = 'select location_chat_message.id, (select entity_id from business_info where id=location_chat_message.business_id) as business_id, location_chat_message.entity_id, concat(first_name, " ", last_name) as name, entity_type, content, (unix_timestamp(date) * 1000) as date, ifnull(image, "") as image '.
				'from location_chat_message join (entity, ((select entity_id as id, first_name, last_name from person_info) union all (select entity_id as id, name as first_name, "" as last_name from business_info) union all (select entity_id as id, name as first_name, "" as last_name from organization_info)) as name) '.
				'on (location_chat_message.entity_id=entity.id AND location_chat_message.entity_id=name.id) left join photo_entity on (photo_entity.owner_id=location_chat_message.entity_id AND is_profile=1) order by id DESC limit 1';

		$result = $this->db->query($query); //get the last message;
	
		if($result->num_rows() > 0) {
			$row = $result->row_array();
			return $row;
		} else {
			return errorCode::MESSAGE_FAILED;
		}
	}
	
	function createAnonymousId($entityId) {
		$cleanId = $this->db->escape($entityId);
		$insertQuery = 'insert into location_chat_user values(uuid(), ' .$cleanId. ', now()) on duplicate key update anonymous_id=VALUES(anonymous_id), date_updated=now()';
		$this->db->query($insertQuery);
		
	}
	
	function removeAnonymousId($entityId) {
		$this->db->delete('location_chat_user', array('patron_id' => $entityId)); 
	}
	
	function revealIdentityToEntities($data) {
		$cleanId = $data['id'];
		
		$values = "";
		for($i = 0, $size=count($data['ids']); $i < $size; $i++) {
			$newId = $data['ids'][$i];
			$values.= (($i == 0 ? "VALUES" : ", ") . ("(" .($cleanId < $newId ? $cleanId : $newId). ", " .($cleanId > $newId ? $cleanId : $newId).", " .($cleanId < $newId ? $data['flag'] : 0). ", " .($cleanId > $newId ? $data['flag'] : 0). ")"));
		}
		
		$insertQuery = 'insert into relationship_anonymous ' .$values. ' on duplicate key update flag_one=flag_one|VALUES(flag_one), flag_two=flag_two|VALUES(flag_two)';
		$this->db->query($insertQuery);	
	}
}