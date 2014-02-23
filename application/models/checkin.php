<?php

class Checkin extends CI_Model {
	
	const TYPE_EVENT = "EVENT";
	const TYPE_BUSINESS = "BUSINESS";

	const FETCH_FLAG_NONE = 0;
	const FETCH_FLAG_NEARBY = 1;
	const FETCH_FLAG_FAV = 2;
	const FETCH_FLAG_RECENT = 4;
	
	function __construct() {
		parent::__construct();
		$this->load->library('errorCode'); //used for returning error codes
		$this->load->library('util');
		$this->load->model('Relationship_general', 'rel');
		//$this->db->query("SET time_zone='+0:00'");
	}

	function insertOrUpdateCheckIn($entityId, &$checkIn) {
		$where = array('entity_id'=>$entityId, 'place_id'=>$checkIn['place_id'], 'place_type'=>$checkIn['place_type'], 'total_time'=>0);
		$result = $this->db->get_where('checkin', $where, 1);
		$count = 0;
		if($result->num_rows() > 0) {
			$checkin = $result->row_array();
			$count = $checkin['count'];
			$this->db->where($where);
			$this->db->update('checkin', array('count'=>$checkin['count'] + 1, 'last_checkin_time'=>date( 'Y-m-d H:i:s', time())));
		} else {
			$this->db->insert('checkin', $where);
			//errorCode::logError(errorCode::ERROR_DATABASE, $this->db->_error_number(), $this->db->_error_message(), "checkin");
		}
		
		$secondaryQuery = $checkIn['place_type'] == 1 ? "select id from event where venue_id=" .$checkIn['place_id']. " AND start_time < now() AND end_time > now() AND (creator_id=" .$entityId. " OR (select state from event_rsvp where event_rsvp.entity_id=" .$entityId. " AND event_rsvp.event_id=event.id order by event_rsvp.event_id desc limit 1) !=4)" :
		"select venue_id as id from event where id=" . $checkIn['place_id']. " AND venue_id is NOT NULL";

		$result = $this->db->query($secondaryQuery); 
		if($result->num_rows() > 0) {
			$row = $result->row_array();
			$insertQuery = "insert into checkin values(NULL, " .$entityId. ", " .$row['id']. ", ". ($checkIn['place_type']== 1 ? 2 : 1). ", 1, 0, NULL) on duplicate key update count=count + 1, last_checkin_time=now()";
			$this->db->query($insertQuery);
		}

		
		$query = 'select checkin.id as id, checkin.entity_id, place_id, place_type, count, last_checkin_time, if(place_type=1, event.id, business_info.entity_id) as secondary_place_id, if(place_type=1, event.name, business_info.name) as secondary_place_name, ' .
		'if((place_type=1 AND event.id is not NULL) OR (place_type=2 AND business_info.entity_id is not NULL), (select alias from checkin_place_type where id=if(place_type=1, 2, 1)), null) as secondary_place_type from checkin left join event on (place_type=1 AND venue_id=place_id ' .
		'AND start_time < now() AND end_time > now() AND (creator_id=checkin.entity_id OR (select state from event_rsvp where event_rsvp.entity_id=checkin.entity_id AND event_rsvp.event_id=event.id order by event_rsvp.event_id desc limit 1) != 4)) left join business_info on (place_type=2 AND ' .
		'business_info.entity_id=(select venue_id from event where event.id=place_id)) where checkin.entity_id="' .$entityId. '" AND place_id="' .$checkIn['place_id']. '" AND place_type="'. $checkIn['place_type']. '" limit 1' ;
		//echo $query;			
		$result = $this->db->query($query);
		//errorCode::logError("select", "checkin");
		if($result->num_rows() > 0) {
			$row = $result->row_array();
			$checkIn['count'] = $row['count'];
			$checkIn['last_checkin_time'] = strtotime($row['last_checkin_time']) * 1000;
			$checkIn['secondary_place_id'] = $row['secondary_place_id'];
			$checkIn['secondary_name'] = $row['secondary_place_name'];
			$checkIn['secondary_type'] = $row['secondary_place_type'];
			return $row['count'] == $count + 1 ? $row['id'] : false;
		} else
			return false;
		
	}
	
	function fetchPotentialCheckIns($id, $lat, $lon, $source, $place_id=null, $place_type=null) {
		
		$specificWhere = "";
		if(isset($place_id) && isset($place_type)) {
			$specificWhere = " AND (place_id=" .$place_id. " AND place_type=" .$place_type. ")";
		}
		
		$query = '(select event.id as place_id, "EVENT" as type, (select id from checkin_place_type where alias="EVENT") as place_type, event.name as name, '.
				'business_info.entity_id as secondary_place_id, business_info.name as secondary_name, if(business_info.entity_id is not NULL, "BUSINESS", null) as secondary_type, lat, lon, hashtag, ifnull(image, "") as image, '.
				$this->util->getHaversineSQLString($lat, $lon, "lat", "lon", "m") . ' as distance from event left join business_info on (business_info.entity_id=event.venue_id) left join photo_event on (event.id=photo_event.event_id AND is_flyer=1) ' .
				'left join event_rsvp on (event.id=event_rsvp.event_id AND event_rsvp.entity_id='. $id . ') where start_time < now() AND end_time > now() AND (event_rsvp.state !=(select id from rsvp_state where alias="DECLINE") OR '.
				'creator_id=' .$id. ') having distance < ' .$this->util->getDistanceError($source, 'm'). $specificWhere. ' order by distance limit 10) union '.
		
				'(select entity_id as place_id, "BUSINESS" as type, (select id from checkin_place_type where alias="BUSINESS") as place_type, business_info.name as name, '.
				'event.id as secondary_place_id, event.name as secondary_name, if(event.id is not NULL, "EVENT", null) as secondary_type, location_perm.lat, location_perm.lon, "" as hashtag, ifnull(image, "") as image, '.
				$this->util->getHaversineSQLString($lat, $lon, "location_perm.lat", "location_perm.lon", "m") . ' as distance from business_info left join event on (venue_id=business_info.entity_id ' .
				'AND start_time < now() AND end_time > now() AND (creator_id=' .$id. ' OR (select state from event_rsvp where event_rsvp.entity_id=' .$id. ' AND event_rsvp.event_id=event.id order by event_rsvp.event_id desc limit 1) != (select id from rsvp_state where alias="DECLINE"))) '.
				'left join photo_entity on (business_info.entity_id=photo_entity.owner_id AND is_profile=1) ' .
				'join location_perm on (location_perm.id=business_info.entity_id) having distance < ' .$this->util->getDistanceError($source, 'm') .$specificWhere. ' order by distance limit 10) order by distance, place_type limit 10';
		
		return $this->db->query($query)->result_array();
	}
	
	function fetchCheckInsOfType($data) {
		//nearby, favorites, recent
		$cleanId = $this->db->escape($data['id']);
		
		$where = "";
		$distanceSelect = "";
		
		if(($data['flag'] & self::FETCH_FLAG_NEARBY) > 0) {
			if(!(isset($data['lat']) &&  isset($data['lon']) && isset($data['source'])))
				return errorCode::MISSING_DATA;
			$distanceSelect = ', ' .$this->util->getHaversineSQLString($data['lat'], $data['lon'], "if(place_type=1, location_perm.lat, event.lat)", "if(place_type=1, location_perm.lon, event.lon)", "m") . ' as distance';
			$where = "AND ((place_type=1 AND start_time is NULL) OR (place_type=2 AND end_time > now())) "; //we dont need to check if its already started. if it didnt, we wouldnt have checked in here
			$orderBy = "distance asc"; 
		}
		
		if(($data['flag'] & self::FETCH_FLAG_FAV) > 0) {
			$orderBy = $orderBy . ((strlen($orderBy) > 0 ?  ", " : "") ."count desc");		
		}
		
		if(($data['flag'] & self::FETCH_FLAG_RECENT) > 0) {
			$where = " AND datediff(now(), last_checkin_time) <=28"; //only show places that we've check into at most 4 weeks ago
			$orderBy = $orderBy . ((strlen($orderBy) > 0 ?  ", " : "") . "last_checkin_time desc");
		}
		
		//if nearby, calculate distance and see if its less than nearby value
		//$orderbyClause = "";
		//if (recent) favorites else if last_checkin_time
		//select * from checkin where entity_id=id AND 
		
		$sqlQuery = 'select if(place_type=1, business_info.entity_id, event.id) as place_id, if(place_type=1, "BUSINESS", "EVENT") as type, place_type, if(place_type=1, business_info.name, event.name) as name, '. 
					'if(place_type=1, location_perm.lat, event.lat) as lat, if(place_type=1, location_perm.lon, event.lon) as lon, if(place_type=1, location_perm.address, event.address) as address, '.
					'if(place_type=1, 0, unix_timestamp(start_time) * 1000) as start_time, if(place_type=1, 0, unix_timestamp(end_time) * 1000) as end_time, if(place_type=1, business_info.about, event.details) as details, if(place_type=1, "", hashtag) as hashtag, count, total_time, unix_timestamp(last_checkin_time) * 1000 as last_checkin_time, if(place_type=1, ifnull(photo_entity.image, ""), ifnull(photo_event.image, "")) as image'. 
					$distanceSelect. ' from checkin left join business_info on (place_type=1 AND business_info.entity_id=place_id) left join event on (place_type=2 AND event.id=place_id) left join photo_entity on ' .
					' (place_type=1 AND business_info.entity_id=photo_entity.owner_id AND is_profile=1) left join photo_event on (place_type = 2 AND event.id=photo_event.event_id AND is_flyer=1) ' .
					'left join location_perm on (place_type=1 AND location_perm.id=business_info.entity_id) where checkin.entity_id=' .$cleanId. ' ' . $where. 
					(strlen($distanceSelect) > 0 ? 'having distance < ' .$this->util->getDistanceError($source, 'm') : '') . ' order by '. (strlen($orderBy) > 0 ? '  ' .$orderBy : "last_checkin_time desc"). ' limit 15';	
	
		//echo $sqlQuery;
		
		$result = $this->db->query($sqlQuery);
		return $result->result_array();
	}
	
	
	
	function getCheckIn($id) {
		$query = 'select checkin.id as id, checkin.entity_id as entity_id, place_id, place_type, (select alias from checkin_place_type where id=place_type) as place_type_alias, count, unix_timestamp(last_checkin_time) * 1000 as last_checkin_time, if(place_type=1, event.id, business_info.entity_id) as secondary_place_id, if(place_type=1, event.name, business_info.name) as secondary_place_name, ' .
				'if((place_type=1 AND event.id is not NULL) OR (place_type=2 AND business_info.entity_id is not NULL), (select alias from checkin_place_type where id=if(place_type=1, 2, 1)), null) as secondary_place_type from checkin left join event on (place_type=1 AND venue_id=place_id ' .
				'AND start_time < now() AND end_time > now() AND (creator_id=checkin.entity_id OR (select state from event_rsvp where event_rsvp.entity_id=checkin.entity_id AND event_rsvp.event_id=event.id order by event_rsvp.event_id desc limit 1) != (select id from rsvp_state where alias="DECLINE"))) left join business_info on (place_type=2 AND ' .
				'business_info.entity_id=(select venue_id from event where event.id=place_id)) where checkin.id="' .$id. '"';
		
		$result = $this->db->query($query);
		
		//$result = $this->db->select('id, entity_id, place_id, place_type, (select alias from checkin_place_type where id=place_type) as place_type_alias, count, last_checkin_time', FALSE)->from('checkin')->where(array('id' => $id))->get();	
		return $result->num_rows() > 0 ? $result->row_array() : false;
	}
	
	function getCheckInAsGeofence($id) {
		$query = 'select place_id, (select alias from checkin_place_type where id=place_type) as type, place_type, if(place_type=1, location_perm.geofence_lat, event.geofence_lat) as geofence_lat, if(place_type=1, location_perm.geofence_lon, event.geofence_lon) as geofence_lon, ' .
				'if(place_type=1, location_perm.geofence_radius, event.geofence_radius) as geofence_radius, if(place_type=1, -1, unix_timestamp(end_time) * 1000) as expiration from checkin left join location_perm on (place_type=1 AND location_perm.id=place_id) ' . 
				'left join event on (place_type=2 AND place_id=event.id AND start_time < now() AND end_time > now() AND (creator_id=checkin.entity_id OR (select state from event_rsvp where event_rsvp.entity_id=checkin.entity_id AND event_rsvp.event_id=event.id '. 
				'order by event_rsvp.event_id desc limit 1) != (select id from rsvp_state where alias="DECLINE")))  where checkin.id="' .$id. '"';
		
		$result = $this->db->query($query);
		
		return $result->num_rows() > 0 ? $result->row_array() : false;
	}

	function getAllEntitiesCheckedInAtPlace($entity_id, $place_id, $place_type_alias, $includeReg = true) {
		$cleanId= $this->db->escape($entity_id);
		
		$query = 'select person_info.entity_id, if(relationship_general.state_one=' .Relationship_general::TYPE_REL_PERSONAL. ' OR person_info.entity_id=' .$cleanId.', first_name, "Someone") as first_name, if(relationship_general.state_one=' .Relationship_general::TYPE_REL_PERSONAL. ' OR person_info.entity_id=' .$cleanId.', last_name,"") as last_name, '.
		'if(relationship_general.state_one=' .Relationship_general::TYPE_REL_PERSONAL. ' OR person_info.entity_id=' .$cleanId.', concat(first_name, " ", last_name),"Someone") as name, entity_type, if(relationship_general.state_one=' .Relationship_general::TYPE_REL_PERSONAL. ' OR person_info.entity_id=' .$cleanId.', ifnull(image, ""), "") as image ' 
		. ($includeReg ? ', push_registration_id, phone_type.alias as phone_alias ' : ''). ', ifnull((relationship_general.state_one=' .Relationship_general::TYPE_REL_PERSONAL. ' OR person_info.entity_id=' .$cleanId.'), 0) as is_personal from person_info join (entity, checkin) on (person_info.current_checkin=checkin.id AND person_info.entity_id=entity.id) '.
		'left join event on ((place_type=1 AND venue_id=place_id AND start_time < now() AND end_time > now() AND (creator_id=checkin.entity_id OR (select state from event_rsvp where event_rsvp.entity_id=checkin.entity_id AND event_rsvp.event_id=event.id order by event_rsvp.event_id desc limit 1) != (select id from rsvp_state where alias="DECLINE"))) OR (place_type=2 AND event.id=place_id)) '. 
		'left join photo_entity on (person_info.entity_id=photo_entity.owner_id AND is_profile=1) left join relationship_general on ((id_one=person_info.entity_id AND id_two=' .$cleanId. ') OR (id_one=' .$cleanId. ' AND id_two=person_info.entity_id)) left join phone_type on (phone_type.id=entity.phone_type) where (place_id=' .$this->db->escape($place_id). ' AND place_type=(select id from checkin_place_type where alias='.$this->db->escape($place_type_alias) . ')) OR ' . 
		'(place_type=1 AND "' .$place_type_alias. '"="'.self::TYPE_EVENT. '" AND event.id='.$this->db->escape($place_id). ') OR (place_type=2 AND "' .$place_type_alias. '"="'.self::TYPE_BUSINESS. '" AND venue_id='.$this->db->escape($place_id). ')';
		//if this is a business, and we're requesting an event, then get the event.id of the place! //else if this is an event AND we're requesting a business, then get the event.id
		//echo $query;
		$result = $this->db->query($query);
		return $result->result_array();
	}
	
	function getCertainEntitiesCheckedInAtPlace($entity_id, $place_id, $place_type_alias, $particular_ids, $includeReg = true) {	
		$size = count($particular_ids);
		//converts our recipients into a sql array.
		$sqlArray = "";
		for($i = 0; $i < $size; $i++)  			
			$sqlArray = $sqlArray . (strlen($sqlArray) == 0 ? "(" : ", ") . $this->db->escape($particular_ids[$i]);
		
		$sqlArray.= (strlen($sqlArray) > 0 ? ")": "('')");
		
		$cleanId= $this->db->escape($entity_id);
		$query = 'select person_info.entity_id, if(relationship_general.state_one=' .Relationship_general::TYPE_REL_PERSONAL. ' OR person_info.entity_id=' .$cleanId.', concat(first_name, " ", last_name),"Someone") as name, entity_type, if(relationship_general.state_one=' .Relationship_general::TYPE_REL_PERSONAL. ' OR person_info.entity_id=' .$cleanId.', ifnull(image, ""), "") as image ' . ($includeReg ? ', push_registration_id, phone_type.alias as phone_alias ' : ''). ', ifnull((relationship_general.state_one=' .Relationship_general::TYPE_REL_PERSONAL. ' OR person_info.entity_id=' .$cleanId.'), 0) as is_personal from person_info join (entity, checkin) on (person_info.current_checkin=checkin.id AND person_info.entity_id=entity.id) '.
		'left join event on ((place_type=1 AND venue_id=place_id AND start_time < now() AND end_time > now() AND (creator_id=checkin.entity_id OR (select state from event_rsvp where event_rsvp.entity_id=checkin.entity_id AND event_rsvp.event_id=event.id order by event_rsvp.event_id desc limit 1) != (select id from rsvp_state where alias="DECLINE"))) OR (place_type=2 AND event.id=place_id)) '.
		'left join photo_entity on (person_info.entity_id=photo_entity.owner_id AND is_profile=1)  left join relationship_general on ((id_one=person_info.entity_id AND id_two=' .$cleanId. ') OR (id_one=' .$cleanId. ' AND id_two=person_info.entity_id)) left join phone_type on (phone_type.id=entity.phone_type) where ((place_id=' .$this->db->escape($place_id). ' AND place_type=(select id from checkin_place_type where alias='.$this->db->escape($place_type_alias) . ')) OR ' .
		'(place_type=1 AND "' .$place_type_alias. '"="'.self::TYPE_EVENT. '" AND event.id='.$this->db->escape($place_id). ') OR (place_type=2 AND "' .$place_type_alias. '"="'.self::TYPE_BUSINESS. '" AND venue_id='.$this->db->escape($place_id). ')) AND  person_info.entity_id IN ' . $sqlArray;
		//echo $query;
		$result = $this->db->query($query);
		return $result->result_array();
	}
	
	function getAllEntitiesFromLocationChat($entity_id, $place_id, $place_type_alias, $updated_time = 0, $includeReg = true) {
		$this->load->model('Location_chat', 'loc_chat');
		$cleanId= $this->db->escape($entity_id);
		$query = '(select person_info.entity_id as id, if(( (relationship_general.state_one & ' .Relationship_general::TYPE_REL_BLOCKED. '=0 AND relationship_general.state_two & ' .Relationship_general::TYPE_REL_BLOCKED. '=0) OR entity.id =' .$cleanId. ') OR if(relationship_anonymous.id_one=' .$cleanId. ', flag_two, flag_one) & ' .Location_chat::FLAG_MILU_NUMBER. ' > 0, milu_number, "") as milu_number, '.
		'case when entity.id !=' .$cleanId. ' AND ( (relationship_general.state_one is NULL AND relationship_general.state_two is NULL) OR (relationship_general.state_one & ' .Relationship_general::TYPE_REL_BLOCKED. ' > 0 OR relationship_general.state_two & ' .Relationship_general::TYPE_REL_BLOCKED. ' > 0)) then '.
		'if(if(relationship_anonymous.id_one=' .$cleanId. ', flag_two, flag_one) & ~'. Location_chat::FLAG_IMAGE. ' & ~' .Location_chat::FLAG_MILU_NUMBER. '  > 0, concat(if(if(relationship_anonymous.id_one=' .$cleanId. ',flag_two, flag_one) & ' .Location_chat::FLAG_FIRST_NAME. 
		' > 0, concat(first_name, " "), ""), if(if(relationship_anonymous.id_one=' .$cleanId. ',flag_two, flag_one) & ' .Location_chat::FLAG_LAST_NAME. ' > 0, last_name, "")), anonymous_id) else concat(first_name, " ", last_name) end as name, '.
		'entity_type, if(( (relationship_general.state_one & ' .Relationship_general::TYPE_REL_BLOCKED. '=0 AND relationship_general.state_two & ' .Relationship_general::TYPE_REL_BLOCKED. '=0) OR entity.id =' .$cleanId. ') OR if(relationship_anonymous.id_one=' .$cleanId. ', flag_two, flag_one) & ' .Location_chat::FLAG_IMAGE. ' > 0, ifnull(image, ""), "") as image '.
		($includeReg ? ', push_registration_id, phone_type.alias as phone_alias ' : ''). ', ifnull(( (relationship_general.state_one & ' .Relationship_general::TYPE_REL_BLOCKED. '=0 AND relationship_general.state_two & ' .Relationship_general::TYPE_REL_BLOCKED. '=0) OR person_info.entity_id=' .$cleanId.'), 0) as relationship_exists, ifnull(if(relationship_anonymous.id_one=' .$cleanId. ', flag_two, flag_one), 0) as flag '.
		'from person_info join (entity, checkin, location_chat_user) on (person_info.current_checkin=checkin.id AND person_info.entity_id=entity.id AND person_info.entity_id=location_chat_user.patron_id) '.
		'left join event on ((place_type=1 AND venue_id=place_id AND start_time < now() AND end_time > now() AND (creator_id=checkin.entity_id OR (select state from event_rsvp where event_rsvp.entity_id=checkin.entity_id AND event_rsvp.event_id=event.id order by event_rsvp.event_id desc limit 1) != (select id from rsvp_state where alias="DECLINE"))) OR (place_type=2 AND event.id=place_id)) '.
		'left join photo_entity on (person_info.entity_id=photo_entity.owner_id AND is_profile=1)  left join relationship_general on ((relationship_general.id_one=person_info.entity_id AND relationship_general.id_two=' .$cleanId. ') OR (relationship_general.id_one=' .$cleanId. ' AND relationship_general.id_two=person_info.entity_id)) '.
		'left join relationship_anonymous on  ((relationship_anonymous.id_one=person_info.entity_id AND relationship_anonymous.id_two=' .$cleanId. ') OR (relationship_anonymous.id_one=' .$cleanId. ' AND relationship_anonymous.id_two=person_info.entity_id)) '.
		'left join phone_type on (phone_type.id=entity.phone_type) where '.
		'((place_id=' .$this->db->escape($place_id). ' AND place_type=(select id from checkin_place_type where alias='.$this->db->escape($place_type_alias) . ')) OR (place_type=1 AND "' .$place_type_alias. '"="'.self::TYPE_EVENT. '" AND event.id='.$this->db->escape($place_id). ') OR (place_type=2 AND "' .$place_type_alias. '"="'.self::TYPE_BUSINESS. '" AND venue_id='.$this->db->escape($place_id). ')) '.
		' AND location_chat_user.date_updated > "' .date( 'Y-m-d H:i:s', $updated_time). '") union all '.
		
		//this is to get the business that we're at. 
		'(select entity.id as id, milu_number, business_info.name as name, entity_type, ifnull(image,"") as image '.($includeReg ? ', push_registration_id, phone_type.alias as phone_alias ' : ''). ', ifnull(( (relationship_general.state_one & ' .Relationship_general::TYPE_REL_BLOCKED. '=0 AND relationship_general.state_two & ' .Relationship_general::TYPE_REL_BLOCKED. '=0) OR entity.id=' .$cleanId.'), 0) as relationship_exists, '.
		'ifnull(if(relationship_anonymous.id_one=' .$cleanId. ', flag_two, flag_one), 0) as flag from entity join business_info on (entity.id=business_info.entity_id) left join photo_entity on (entity.id=photo_entity.owner_id AND is_profile=1) '.
		'left join relationship_general on ((relationship_general.id_one=entity.id AND relationship_general.id_two=' .$cleanId. ') OR (relationship_general.id_one=' .$cleanId. ' AND relationship_general.id_two=entity.id)) '.
		'left join relationship_anonymous on  ((relationship_anonymous.id_one=entity.id AND relationship_anonymous.id_two=' .$cleanId. ') OR (relationship_anonymous.id_one=' .$cleanId. ' AND relationship_anonymous.id_two=entity.id)) '.
		'left join phone_type on (phone_type.id=entity.phone_type) where entity.id=' .$this->db->escape($place_id).' AND entity.date_joined > "' .date( 'Y-m-d H:i:s', $updated_time). '") order by name asc' ; 
		//echo $query;
		$result = $this->db->query($query);
		return $result->result_array();
	}
	
	
	function getCertainEntitiesFromLocationChat($entity_id, $place_id, $place_type_alias, $particular_ids, $includeReg = true) {
		$this->load->model('Location_chat', 'loc_chat');
		$size = count($particular_ids);
		//converts our recipients into a sql array.
		$sqlArray = "";
		for($i = 0; $i < $size; $i++)
			$sqlArray = $sqlArray . (strlen($sqlArray) == 0 ? "(" : ", ") . $this->db->escape($particular_ids[$i]);
		
		$sqlArray.= (strlen($sqlArray) > 0 ? ")": "('')");
		
		$cleanId= $this->db->escape($entity_id);
		$query = 'select * from ((select person_info.entity_id as id, if(( (relationship_general.state_one &' .Relationship_general::TYPE_REL_BLOCKED. '= 0 AND relationship_general.state_two &' .Relationship_general::TYPE_REL_BLOCKED. '=0) OR entity.id =' .$cleanId. ') OR if(relationship_anonymous.id_one=' .$cleanId. ', flag_two, flag_one) & ' .Location_chat::FLAG_MILU_NUMBER. ' > 0, milu_number, "") as milu_number, '.
				'case when entity.id !=' .$cleanId. ' AND ((relationship_general.state_one is NULL AND relationship_general.state_two is NULL) OR (relationship_general.state_one &' .Relationship_general::TYPE_REL_BLOCKED. ' > 0 OR relationship_general.state_two &' .Relationship_general::TYPE_REL_BLOCKED. ' > 0)) then '.
				'if(if(relationship_anonymous.id_one=' .$cleanId. ', flag_two, flag_one) & ~'. Location_chat::FLAG_IMAGE. ' & ~' .Location_chat::FLAG_MILU_NUMBER. '  > 0, concat(if(if(relationship_anonymous.id_one=' .$cleanId. ',flag_two, flag_one) & ' .Location_chat::FLAG_FIRST_NAME.
				' > 0, concat(first_name, " "), ""), if(if(relationship_anonymous.id_one=' .$cleanId. ',flag_two, flag_one) & ' .Location_chat::FLAG_LAST_NAME. ' > 0, last_name, "")), anonymous_id) else concat(first_name, " ", last_name) end as name, '.
				'entity_type, if(( (relationship_general.state_one & ' .Relationship_general::TYPE_REL_BLOCKED. '=0 AND relationship_general.state_two & ' .Relationship_general::TYPE_REL_BLOCKED. '=0) OR entity.id =' .$cleanId. ') OR if(relationship_anonymous.id_one=' .$cleanId. ', flag_two, flag_one) & ' .Location_chat::FLAG_IMAGE. ' > 0, ifnull(image, ""), "") as image '.
				($includeReg ? ', push_registration_id, phone_type.alias as phone_alias ' : ''). ', ifnull(( (relationship_general.state_one & ' .Relationship_general::TYPE_REL_BLOCKED. '=0 AND relationship_general.state_two & ' .Relationship_general::TYPE_REL_BLOCKED. '=0) OR person_info.entity_id=' .$cleanId.'), 0) as relationship_exists, ifnull(if(relationship_anonymous.id_one=' .$cleanId. ', flag_two, flag_one), 0) as flag '.
				'from person_info join (entity, checkin, location_chat_user) on (person_info.current_checkin=checkin.id AND person_info.entity_id=entity.id AND person_info.entity_id=location_chat_user.patron_id) '.
				'left join event on ((place_type=1 AND venue_id=place_id AND start_time < now() AND end_time > now() AND (creator_id=checkin.entity_id OR (select state from event_rsvp where event_rsvp.entity_id=checkin.entity_id AND event_rsvp.event_id=event.id order by event_rsvp.event_id desc limit 1) != (select id from rsvp_state where alias="DECLINE"))) OR (place_type=2 AND event.id=place_id)) '.
				'left join photo_entity on (person_info.entity_id=photo_entity.owner_id AND is_profile=1)  left join relationship_general on ((relationship_general.id_one=person_info.entity_id AND relationship_general.id_two=' .$cleanId. ') OR (relationship_general.id_one=' .$cleanId. ' AND relationship_general.id_two=person_info.entity_id)) '.
				'left join relationship_anonymous on  ((relationship_anonymous.id_one=person_info.entity_id AND relationship_anonymous.id_two=' .$cleanId. ') OR (relationship_anonymous.id_one=' .$cleanId. ' AND relationship_anonymous.id_two=person_info.entity_id)) '.
				'left join phone_type on (phone_type.id=entity.phone_type) where '.
				'((place_id=' .$this->db->escape($place_id). ' AND place_type=(select id from checkin_place_type where alias='.$this->db->escape($place_type_alias) . ')) OR (place_type=1 AND "' .$place_type_alias. '"="'.self::TYPE_EVENT. '" AND event.id='.$this->db->escape($place_id). ') OR (place_type=2 AND "' .$place_type_alias. '"="'.self::TYPE_BUSINESS. '" AND venue_id='.$this->db->escape($place_id). ')) '.				
				' AND location_chat_user.date_updated > "' .date( 'Y-m-d H:i:s', $updated_time). '") union all '.

				//this is to get the business that we're at.
				'(select entity.id as id, milu_number, business_info.name as name, entity_type, ifnull(image,"") as image '.($includeReg ? ', push_registration_id, phone_type.alias as phone_alias ' : ''). ', ifnull(( (relationship_general.state_one & ' .Relationship_general::TYPE_REL_BLOCKED. '=0 AND relationship_general.state_two &' .Relationship_general::TYPE_REL_BLOCKED. '=0) OR entity.id=' .$cleanId.'), 0) as relationship_exists, '.
				'ifnull(if(relationship_anonymous.id_one=' .$cleanId. ', flag_two, flag_one), 0) as flag from entity join business_info on (entity.id=business_info.entity_id) left join photo_entity on (entity.id=photo_entity.owner_id AND is_profile=1) '.
				'left join relationship_general on ((relationship_general.id_one=entity.id AND relationship_general.id_two=' .$cleanId. ') OR (relationship_general.id_one=' .$cleanId. ' AND relationship_general.id_two=entity.id)) '.
				'left join relationship_anonymous on  ((relationship_anonymous.id_one=entity.id AND relationship_anonymous.id_two=' .$cleanId. ') OR (relationship_anonymous.id_one=' .$cleanId. ' AND relationship_anonymous.id_two=entity.id)) '.
				'left join phone_type on (phone_type.id=entity.phone_type) where entity.id=' .$this->db->escape($place_id).' AND entity.date_joined > "' .date( 'Y-m-d H:i:s', $updated_time). '")) as new_table where id IN ' . $sqlArray;
		//echo $query;
		$result = $this->db->query($query);
		return $result->result_array();
	}
	
	function getSpecificEntityFromLocationChat($entity_id, $place_id, $place_type_alias, $specific_id, $includeReg = true) {
		$this->load->model('Location_chat', 'loc_chat');
		$cleanId= $this->db->escape($entity_id);
		$query = $specific_id != $place_id ? 'select person_info.entity_id as id, if(( (relationship_general.state_one & ' .Relationship_general::TYPE_REL_BLOCKED. '=0 AND relationship_general.state_two &' .Relationship_general::TYPE_REL_BLOCKED. '=0) OR entity.id =' .$cleanId. ') OR if(relationship_anonymous.id_one=' .$cleanId. ', flag_two, flag_one) & ' .Location_chat::FLAG_MILU_NUMBER. ' > 0, milu_number, "") as milu_number, '.
		'case when entity.id !=' .$cleanId. ' AND ((relationship_general.state_one is NULL AND relationship_general.state_two is NULL) OR (relationship_general.state_one &' .Relationship_general::TYPE_REL_BLOCKED. ' > 0 OR relationship_general.state_two &' .Relationship_general::TYPE_REL_BLOCKED. '> 0)) then '.
		'if(if(relationship_anonymous.id_one=' .$cleanId. ', flag_two, flag_one) & ~'. Location_chat::FLAG_IMAGE. ' & ~' .Location_chat::FLAG_MILU_NUMBER. '  > 0, concat(if(if(relationship_anonymous.id_one=' .$cleanId. ',flag_two, flag_one) & ' .Location_chat::FLAG_FIRST_NAME. 
		' > 0, concat(first_name, " "), ""), if(if(relationship_anonymous.id_one=' .$cleanId. ',flag_two, flag_one) & ' .Location_chat::FLAG_LAST_NAME. ' > 0, last_name, "")), anonymous_id) else concat(first_name, " ", last_name) end as name, '.
		'entity_type, if(( (relationship_general.state_one & ' .Relationship_general::TYPE_REL_BLOCKED. '=0 AND relationship_general.state_two & ' .Relationship_general::TYPE_REL_BLOCKED. '=0) OR entity.id =' .$cleanId. ') OR if(relationship_anonymous.id_one=' .$cleanId. ', flag_two, flag_one) & ' .Location_chat::FLAG_IMAGE. ' > 0, ifnull(image, ""), "") as image '.
		($includeReg ? ', push_registration_id, phone_type.alias as phone_alias ' : ''). ', ifnull(( (relationship_general.state_one & ' .Relationship_general::TYPE_REL_BLOCKED. '=0 AND relationship_general.state_two & '.Relationship_general::TYPE_REL_BLOCKED. '=0) OR person_info.entity_id=' .$cleanId.'), 0) as relationship_exists, ifnull(if(relationship_anonymous.id_one=' .$cleanId. ', flag_two, flag_one), 0) as flag '.
		'from person_info join (entity, checkin, location_chat_user) on (person_info.current_checkin=checkin.id AND person_info.entity_id=entity.id AND person_info.entity_id=location_chat_user.patron_id) '.
		'left join event on ((place_type=1 AND venue_id=place_id AND start_time < now() AND end_time > now() AND (creator_id=checkin.entity_id OR (select state from event_rsvp where event_rsvp.entity_id=checkin.entity_id AND event_rsvp.event_id=event.id order by event_rsvp.event_id desc limit 1) != (select id from rsvp_state where alias="DECLINE"))) OR (place_type=2 AND event.id=place_id)) '.
		'left join photo_entity on (person_info.entity_id=photo_entity.owner_id AND is_profile=1)  left join relationship_general on ((relationship_general.id_one=person_info.entity_id AND relationship_general.id_two=' .$cleanId. ') OR (relationship_general.id_one=' .$cleanId. ' AND relationship_general.id_two=person_info.entity_id)) '.
		'left join relationship_anonymous on  ((relationship_anonymous.id_one=person_info.entity_id AND relationship_anonymous.id_two=' .$cleanId. ') OR (relationship_anonymous.id_one=' .$cleanId. ' AND relationship_anonymous.id_two=person_info.entity_id)) '.
		'left join phone_type on (phone_type.id=entity.phone_type) where '.
		'((place_id=' .$this->db->escape($place_id). ' AND place_type=(select id from checkin_place_type where alias='.$this->db->escape($place_type_alias) . ')) OR (place_type=1 AND "' .$place_type_alias. '"="'.self::TYPE_EVENT. '" AND event.id='.$this->db->escape($place_id). ') OR (place_type=2 AND "' .$place_type_alias. '"="'.self::TYPE_BUSINESS. '" AND venue_id='.$this->db->escape($place_id). ')) '.
		' AND entity.id=' . $specific_id :
		
		//this is to get the business that we're at.
		'select entity.id as id, milu_number, business_info.name as name, entity_type, ifnull(image,"") as image '.($includeReg ? ', push_registration_id, phone_type.alias as phone_alias ' : ''). ', ifnull(( (relationship_general.state_one & ' .Relationship_general::TYPE_REL_BLOCKED. '=0 AND relationship_general.state_two & ' .Relationship_general::TYPE_REL_BLOCKED. '=0) OR entity.id=' .$cleanId.'), 0) as relationship_exists, '.
		'ifnull(if(relationship_anonymous.id_one=' .$cleanId. ', flag_two, flag_one), 0) as flag from entity join business_info on (entity.id=business_info.entity_id) left join photo_entity on (entity.id=photo_entity.owner_id AND is_profile=1) '.
		'left join relationship_general on ((relationship_general.id_one=entity.id AND relationship_general.id_two=' .$cleanId. ') OR (relationship_general.id_one=' .$cleanId. ' AND relationship_general.id_two=entity.id)) '.
		'left join relationship_anonymous on  ((relationship_anonymous.id_one=entity.id AND relationship_anonymous.id_two=' .$cleanId. ') OR (relationship_anonymous.id_one=' .$cleanId. ' AND relationship_anonymous.id_two=entity.id)) '.
		'left join phone_type on (phone_type.id=entity.phone_type) where entity.id=' .$this->db->escape($place_id);
		
		//echo $query;
		$result = $this->db->query($query);
		return $result->row_array();
	}
	
}
?>