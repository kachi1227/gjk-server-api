<?php

class Event extends CI_Model {
	function __construct() {
		parent::__construct();
		$this->load->library('errorCode'); //used for returning error codes
		$this->load->library('util');
	}


	function createEvent($eventData) {
		if(isset($eventData['invitees_array']))
			unset($eventData['invitees_array']);
		if(isset($eventData['tags']))
			unset($eventData['tags']);
		
		$eventData['start_time'] = date( 'Y-m-d H:i:s', $eventData['start_time']/1000);
		$eventData['end_time'] = date( 'Y-m-d H:i:s', $eventData['end_time']/1000);
		if(isset($eventData['rsvp_deadline']))
			$eventData['rsvp_deadline'] = date( 'Y-m-d H:i:s', $eventData['rsvp_deadline']/1000);
		
		$result = $this->db->select()->from('event')->order_by('id', 'desc')->limit(1)->get();
		$id = $result->num_rows() > 0 ? $result->row()->id : 0;
		$this->db->insert('event', $eventData);
		$result = $this->db->select("id, name, creator_id, lat, lon, geofence_lat, geofence_lon, geofence_radius, address, venue_id, (unix_timestamp(start_time) * 1000) as start_time, (unix_timestamp(end_time) * 1000) as end_time, (unix_timestamp(rsvp_deadline) * 1000) as rsvp_deadline, hashtag, details, allow_rating, is_creators_moment, (unix_timestamp(date_created) * 1000) as date_created", FALSE)->from('event')->order_by('id', 'desc')->limit(1)->get();
		if($result->num_rows() > 0) {
			$event = $result->row_array();
			return $event['id'] > $id ? $event : false;
		}
		return false;
	}
	
	function editInfo($data) {
		$entityId = $this->db->escape($data['id']);
		$eventId = $this->db->escape($data['event_id']);
		unset($data['id']);
		unset($data['event_id']);	
		
		//deal with the invitees
		if(isset($data['invitees_array'])) {
			$this->load->model('Event_rsvp', 'rsvp');
			if(isset($data['invitees_array']['removed']))
				$this->rsvp->removeRsvps($eventId, $data['invitees_array']['removed']);
			if(isset($data['invitees_array']['added']))
			$this->rsvp->addRsvps($eventId, $data['invitees_array']['added']);
			unset($data['invitees_array']);
		}
		
		//deal with any tag modifications we may have made
		if(isset($data['tags'])) {
			$this->load->model('Tag', 'tag');
			$this->tag->modifyTags($eventId, 'event', $data['tags']['added'], $data['tags']['removed']);
			unset($data['tags']);
		}

		//modify any of the time columns, if they're set
		if(isset($data['start_time']))
			$data['start_time'] = date( 'Y-m-d H:i:s', $data['start_time']/1000);
		if(isset($data['end_time']))
			$data['end_time'] = date( 'Y-m-d H:i:s', $data['end_time']/1000);
		if(isset($data['rsvp_deadline']))
			$data['rsvp_deadline'] = date( 'Y-m-d H:i:s', $data['rsvp_deadline']/1000);
		
		//now we're ready
		if(count($data) > 0) 
			$this->db->where('id', $eventId)->update('event', $data);		
		return $this->fetchEvent(array('id'=>$entityId, 'event_id'=>$eventId));			
	}

	function fetchEvents($data) {
		$this->load->model('Relationship_general', 'rel');
		
		$cleanId = $this->db->escape($data['id']);
		
		$timeValue = array();
		$timeValue[0] = $data['time_value'][0] . "_time"; //escaping this returns trash 'start_time' instead of start_time
		
		$timeValue[1] = (count($data['time_value']) > 1 ? $data['time_value'][1] : $data['time_value'][0]). "_time";
		
		//if data['time_range'][1] == -1, sign = > AND start_time > time_range[0]
		//else sign = $data_time_range[] and reverse sign. so if less than, then greater than. if greater than, then less than
		
		//-1 is the sentinal value that we use to indicate infinity
		if($data['time_range'][1] == -1) {
			$sign = ">";
			$startTimeClause = $timeValue[0] . " " . $sign . " '"  . date( 'Y-m-d H:i:s', $this->db->escape($data['time_range'][0])/1000) . "'";
		} else {
			$sign = $data['time_range'][0] < $data['time_range'][1] ? ">" : "<";
			$startTimeClause = $timeValue[0] . " " . $sign . " '" . date( 'Y-m-d H:i:s', $this->db->escape($data['time_range'][0])/1000) . "' AND " .$timeValue[1] . " " . ($sign == ">" ? " < " : " > ") . " '" . date( 'Y-m-d H:i:s', $this->db->escape($data['time_range'][1])/1000) . "'";
		}
		

		//int rsvpType, int limit
		//get all my events. that havent started (start_time > start_range) OR that have started, but havent ended. (end_time > start_range)
		//we would never have to AND
		//started AND not ended
		
		//endTimeRange = [now, -1] //infinity
		//idRange = [58, 0] //58 going down. 58 to 59 is going up

		$idRangeClause = "";
		if(isset($data['id_range'])) {
			$idPivot = $data['id_range'][0];
			$idRangeClause = " AND event.id " . ($idPivot < $data['id_range'][1] ? ">" : "<") . $idPivot;
		}

		//we might have to call joins! fuck!!!
		$lastIdClause = isset($data['last_event_id']) ? " AND event.id " . $sign . $data['last_event_id'] : "";

		$rsvpClause = isset($data['rsvp_alias']) && in_array(strtoupper($data['rsvp_alias']), array("INVITE", "ATTEND","MAYBE", "DECLINE")) ? "event_rsvp.state=(select id from rsvp_state where alias='" . strtoupper($data['rsvp_alias']) . "')"  : "";
		//events that I'm attending OR that I created...should past events be included here? We have a ton of work to do
		
		$rsvpClauseEmpty = strlen($rsvpClause) <= 0;
		$includeMine = isset($data['include_mine']) && $data['include_mine'];
		
		//	rsvp_state + includeMine = WHERE (event_rsvp.state=(select id from rsvp_state where alias='INVITE') OR event.creator_id=24)
		//	rsvp_state + !includeMine =  WHERE (event_rsvp.state=(select id from rsvp_state where alias='INVITE') AND event.creator_id != 24)
		//!rsvp_state + includeMine - we don't care about the rsvp state, mine are alrady included by default
		//	!rsvp_state + !includeMine WHERE (creator_id != 24)
		$includeMineClause = (!$rsvpClauseEmpty ? ($includeMine ? "OR event.creator_id" : "AND event.creator_id") : (!$includeMine ? "event.creator_id" : "")) . ( !$rsvpClauseEmpty ? ($includeMine  ? "=" : "!=") .$cleanId : (!$includeMine ? "!=".$cleanId : ""));
		
		$rsvpAndMineClause = strlen($rsvpClause . $includeMineClause) > 0 ? ' AND (' . $rsvpClause . $includeMineClause . ')' : "";
		$distanceClause = (isset($data['lat']) && isset($data['lon']) ? $this->util->getHaversineSQLString($data['lat'], $data['lon'], "lat", "lon", "mi") : -1) . ' as distance ';
		
		
		$orderBy = isset($data['order_by']) ? $data['order_by'] : 'start_time'; 
		$limit = isset($data['limit']) ? $data['limit'] : 100;
		
		

		//!!!! TODO we can only see the new events of friends + events that arent private + events that are nearby!!
		//user the same 'is_personal' logic that we setup for checkins. I can only see MY FRIENDS (PERSONAL) events
		//I can see the events of any store or business, period. as long as its not private. Only if it is public
		//all events have to be nearby, if I'm not invited to it
		
		//the query for ALL events is going to be (rsvp IS NOT NULL or != DECLINE) OR if rsvp_alias is not included
		//WHERE (closeby, creator is personal friend or business AND is not private) OR event_rsvp != BLOCKED (i.e. we've been invited or already said we're going)
		

		$sqlQuery = 'select event.id, event.name as name, lat, lon, geofence_lat, geofence_lon, geofence_radius, (unix_timestamp(start_time) * 1000) as start_time, (unix_timestamp(end_time) * 1000) as end_time, (unix_timestamp(rsvp_deadline) * 1000) as rsvp_deadline, names.name as creator_name, creator_id=' .$cleanId. ' as is_mine, event_rsvp.state as rsvp, allow_rating, invite_only, dynamic_location, (unix_timestamp(date_created) * 1000) as date_created, ifnull(image, "' . $this->util->getDefaultImage(util::EVENT_INFO) . '") as image, '. $distanceClause.
				'from event join (entity, specific_user_type, ((select entity_id as id, concat(first_name, " ", last_name) as name from person_info) union all (select entity_id as id, name from business_info) union all (select entity_id as id, name from organization_info)) as names) on (creator_id=entity.id AND entity.entity_type = specific_user_type.id AND creator_id=names.id) ' . 
				"left join event_rsvp on (event.id=event_rsvp.event_id AND event_rsvp.entity_id=". $cleanId . ") " .
				'left join relationship_general on ((id_one=creator_id AND id_two=' .$cleanId. ') OR (id_one=' .$cleanId. ' AND id_two=creator_id))'.
				'left join photo_event on (event.id=photo_event.event_id AND is_flyer=1) where ' . $startTimeClause . $idRangeClause . $lastIdClause . $rsvpAndMineClause.
				' AND (relationship_general.state_one=' .Relationship_general::TYPE_REL_PERSONAL. ' OR creator_id=' .$cleanId.' OR broad_id != 1) having (((distance > 0 AND distance < 15) OR (rsvp IS NOT NULL AND rsvp != (select id from rsvp_state where alias="DECLINE"))) AND invite_only= FALSE) OR '.
				'(invite_only=TRUE AND rsvp IS NOT NULL AND rsvp != (select id from rsvp_state where alias="DECLINE"))' . ($includeMine ? " OR is_mine" : "");

		//echo $sqlQuery;
		$result = $this->db->query($sqlQuery);
		return $result->result_array();
	}
	
	function fetchEvent($data) {
		$this->load->model('Event_rsvp', 'rsvp');
		$this->load->model('Tag', 'tag');
		$this->load->model('Event_comment', 'comment');
		
		$cleanEventId = $this->db->escape($data['event_id']);
		$cleanEntityId = $this->db->escape($data['id']);
		
		
		
		$result = $this->db->select('event.id, event.name as name, hashtag, venue_id, (select name from business_info where entity_id=venue_id) as venue_name, lat, lon, geofence_lat, geofence_lon, geofence_radius, (unix_timestamp(start_time) * 1000) as start_time, (unix_timestamp(end_time) * 1000) as end_time, (unix_timestamp(rsvp_deadline) * 1000) as rsvp_deadline,'.$this->getCreatorNameQuery(). ', creator_id=' .$cleanEntityId. ' as is_mine, details, allow_rating, invite_only, dynamic_location, ifnull(image, "") as image, '.
				'if(creator_id='.$cleanEntityId. ', is_creators_moment, is_moment) as is_moment, ((select count(entity_id) from event_rsvp where event_id='.$cleanEventId.' AND (state=(select id from rsvp_state where alias="ATTEND") OR state=(select id from rsvp_state where alias="MAYBE"))) + if((select broad_id from specific_user_type where id=(select entity_type from entity where id=creator_id))=1, 1, 0)) as going, (select count(entity_id) from event_rsvp where event_id='.$cleanEventId.' and state=(select id from rsvp_state where alias="INVITE")) as invited, '.
				'(select concat(avg(rating), "|", count(rating)) from event_rating where event_id=' .$cleanEventId. ' and rating >= 7) as rating_positive, (select concat(avg(rating), "|", count(rating)) from event_rating where event_id=' .$cleanEventId. ' and rating < 7) as rating_negative, if((creator_id=' .$cleanEntityId.'), (select id from rsvp_state where alias="ATTEND"), event_rsvp.state) as rsvp ', false)->
		from('event')->join('event_rsvp', 'event_id=event.id AND entity_id='.$cleanEntityId, 'left')->join('photo_event', 'event.id=photo_event.event_id AND is_flyer=1', 'left')->where('event.id', $cleanEventId)->get();
		//var_dump($result);
		//errorCode::logError(errorCode::ERROR_DATABASE, $this->db->_error_number(), $this->db->_error_message(), "event");
		if($result->num_rows() > 0) 
			return array('info'=>$result->row_array(), 'rsvps'=>$this->rsvp->getRsvps($cleanEventId), 'tags'=>$this->tag->getEventTags($cleanEventId), 'comments'=>$this->comment->getEventComments($cleanEventId, 0, 10));
		else
			return errorCode::EVENT_NOT_RETRIEVED;
			
		
		
// 		//must account for person who created it as well, is he/she going?. if business, then no
// 		$sqlQuery =  'select event.id, event.name as name, lat, lon, (unix_timestamp(start_time) * 1000) as start_time, (unix_timestamp(end_time) * 1000) as end_time, '.$this->getCreatorNameQuery(). ', creator_id=' .$cleanEntityId. ' as is_mine, details, allow_rating,  ifnull(image, "' . $this->util->getDefaultImage(util::EVENT_INFO) . '") as image, '.
// 				'if(creator_id='.$cleanEntityId. ', is_creators_moment, is_moment) as is_moment, ((select count(entity_id) from event_rsvp where event_id='.$cleanEventId.' and state=(select id from rsvp_state where alias="ATTEND")) + if((select broad_id from specific_user_type where id=(select entity_type from entity where id=creator_id))=1, 1, 0)) as going, (select count(entity_id) from event_rsvp where event_id='.$cleanEventId.' and state=(select id from rsvp_state where alias="INVITE")) as invited, '.
// 				'(select concat(avg(rating), "|", count(rating)) from event_rating where event_id=' .$cleanEventId. ' and rating >= 65) as rating_positive, (select concat(avg(rating), "|", count(rating)) from event_rating where event_id=' .$cleanEventId. ' and rating < 65) as rating_negative, if((creator_id=' .$cleanEntityId.'), (select id from rsvp_state where alias="ATTEND"), event_rsvp.state) as rsvp '.
// 				'from event left join event_rsvp on (event_id=event.id AND entity_id='.$cleanEntityId. ') left join photo_event on (event.id=photo_event.event_id AND is_flyer=1) where event.id=' .$cleanEventId;
// 		 $this->getEventComments($cleanEventId, 0, 10);
	}
	
	function getBasicInfo($data) {
		$cleanEventId = $this->db->escape($data['event_id']);
		$cleanEntityId = $this->db->escape($data['id']);
		//average rating, number of ratings, name, lat, lon
		$result = $this->db->select('event.id, name, (unix_timestamp(start_time) * 1000) as start_time, (unix_timestamp(end_time) * 1000) as end_time, lat, lon, geofence_lat, geofence_lon, geofence_radius, ifnull(image, "") as image, allow_rating', false)->from('event')->join('photo_event', 'event.id=photo_event.event_id AND is_flyer=1', 'left')->where('event.id', $cleanEventId)->get();
		errorCode::logError("database", "event");
		if($result->num_rows() > 0) {
			$row = $result->row_array();
			$this->load->model('Checkin', 'checkin');
			$this->load->model('Event_rating', 'rating');
			
			$attendees = $this->checkin->getAllEntitiesCheckedInAtPlace($data['id'], $data['event_id'], "EVENT", false);
			$row['attendee_count'] =  $attendees ?  count($attendees) : 0;
			
			$ratingInfo = $this->rating->getEventRatingInfo($cleanEntityId, $cleanEventId);
			$row['my_rating'] = $ratingInfo['my_rating'];
			$row['avg'] = $ratingInfo['avg'];
			$row['avg_percent'] = $ratingInfo['avg_percent'];
			$row['rating_count'] = $ratingInfo['count'];
			return $row;
		} else 
			return errorCode::EVENT_NOT_RETRIEVED;		
		
		
	}
	
	function getGuestList($id, $eventId) {
		$cleanId = $this->db->escape($id);
		$cleanEventId = $this->db->escape($eventId); 
		$this->load->model('Relationship_general', 'rel');
		
		$query = 'select names.id as id, name, entity_type, ifnull(image, "") as image, (select alias from rsvp_state where id=rsvp_state) as rsvp_state, if(id_one is NOT NULL AND id_two is NOT NULL, (if(id_one=' .$id. ', state_one, state_two)), NULL)  as my_state, if(id_one is NOT NULL AND id_two is NOT NULL, (if(id_one=' .$id. ', state_two, state_one)), NULL)  as their_state from (select entity_id, state as rsvp_state from event_rsvp where event_id=' .$cleanEventId. 
		' AND (state=(select id from rsvp_state where alias="ATTEND") OR state=(select id from rsvp_state where alias="MAYBE")) union select creator_id, (select id from rsvp_state where alias="ATTEND") as rsvp_state from event where id=' .$cleanEventId. 
		') as attendees join (entity, ((select entity_id as id, concat(first_name, " ", last_name) as name from person_info) union all (select entity_id as id, name from business_info) union all ' .
		'(select entity_id as id, name from organization_info)) as names) on (attendees.entity_id=entity.id AND attendees.entity_id=names.id) left join photo_entity on (attendees.entity_id=photo_entity.owner_id AND is_profile=1) '.
		'left join relationship_general on ((id_one=' .$cleanId. ' AND id_two=attendees.entity_id) OR (id_one=attendees.entity_id AND id_two=' .$cleanId. ')) having my_state is NULL or (my_state & ' .Relationship_general::TYPE_REL_BLOCKED. '=0 AND their_state & ' .Relationship_general::TYPE_REL_BLOCKED. '=0) order by rsvp_state, name';
		$result = $this->db->query($query);
		return $result->result_array();
	}
		
	function getCreatorNameQuery() {
		return 'if((select broad_id from specific_user_type where id=(select entity_type from entity where id=creator_id))=1,(select concat(first_name, " ", last_name) from person_info where entity_id=creator_id), (if((select broad_id from specific_user_type where id=(select entity_type from entity where id=creator_id))=2, (select name from business_info where entity_id=creator_id), (select name from organization_info where entity_id=creator_id)))) as creator_name';
	}
	
	
	function hasActiveEvents($entityId, $onlyDynamic) {
		$cleanId = $this->db->escape($entityId);
		$totalCount = $this->db->select('id')->from('event')->where('creator_id=' .$cleanId. ' AND start_time <= now() AND end_time > now()' . ($onlyDynamic ? ' AND dynamic_location=true' : ''), NULL, FALSE)->count_all_results();
		return $totalCount > 0;
	}
	
	function updateDynamicLocationOfActiveEvents($entityId, $lat, $lon) {
		$cleanId = $this->db->escape($entityId);
		$this->db->where('creator_id=' .$cleanId. ' AND start_time <= now() AND end_time > now() AND dynamic_location=true', NULL, FALSE);
		$this->db->update('event', array('lat' => $lat,'lon' => $lon));
		$result = $this->db->select('id')->from('event')->where('creator_id=' .$cleanId. ' AND start_time <= now() AND end_time > now() AND dynamic_location=true', NULL, FALSE)->get();
		if($result->num_rows() > 0)
			return $result->result_array();
	}
}
?>