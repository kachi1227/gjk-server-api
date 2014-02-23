<?php
class Location extends CI_Model {
	
	const MIN_IN_SECONDS = 60;

	function __construct() {
		parent::__construct();
		$this->load->library('errorCode'); //used for returning error codes
		$this->load->library('util');
		//$this->db->query("SET time_zone='+0:00'");
	}
	function addLocationIfNeccessary($data) {
		$locationChanged = false;
		$result = $this->db->select()->from('location_curr')->where('id', $data['id'])->order_by('date_added', 'desc')->limit(1)->get();
		if($result->num_rows() > 0) {
			$loc = $result->row_array();
			
			//if we are more than 10 meters away from our last location && its been more than 2 minutes since our last update, then update
			if($this->util->haversineDistance($loc['lat'], $loc['lon'], $data['lat'], $data['lon'], 'm') > 10 && (time() - $this->util->convertTimestamp($loc['date_added'])) > 2 * self::MIN_IN_SECONDS) {
				$data['date_added'] = date( 'Y-m-d H:i:s', time());
				$this->db->where('id', $data['id']);
				$this->db->update('location_curr', $data);
				$locationChanged = true;
			}	
		} else 
			$this->db->insert('location_curr', $data);
		
		
		$result = $this->db->select('id, lat, lon, source')->from('location_curr')->where('id', $data['id'])->order_by('date_added', 'desc')->limit(1)->get();
		if($result->num_rows() > 0) {
			$locationInfo = array();
			$row = $result->row_array();
			//TODO update event locations
			//must update our moveable event before, so that we dont get checked out
			$this->load->model('Event', 'event');
			if($locationChanged && $this->event->hasActiveEvents($row['id'], true)) {
				$changedEvents = $this->event->updateDynamicLocationOfActiveEvents($row['id'], $row['lat'], $row['lon']);
				$eventIds = array();
				foreach($changedEvents as $event) 
					$eventIds[] = $event['id'];
				
				$locationInfo['dynamic_events'] = $eventIds;
			}
			$this->findPotentialCheckIns($row['id'], $row['lat'], $row['lon'], $row['source'], $locationInfo);
			//TODO prolly do people as well...maybe to find people that are nearby??			
			unset($row['source']);
			array_values($row);
			$locationInfo['location'] = $row;
			return $locationInfo;
		} else 
			return false;
	}
	
	function findNearbyPeople($id, $lat, $lon) {
		//TODO implement 
	}
	
	/**
	 * 
	 * Enter description here ...
	 * @param unknown_type $id
	 * @param unknown_type $lat
	 * @param unknown_type $lon
	 * @param unknown_type $source
	 * @param unknown_type $checkInResults : this is where we will store any pertininent checkin information, such as potential places, or places we've checked into
	 * @return multitype:unknown NULL
	 */
	function findPotentialCheckIns($id, $lat, $lon, $source, &$checkInResults) {
		//keep in mind that if we get here, then we're already 20 meters from our previous location!!!
		
		$this->load->model('Person_info', 'info');
		$this->load->model('Checkin', 'checkin');
		
		if(($currCheckIn = $this->info->getCurrentCheckIn($id)) != NULL) {
			$currCheckIn = $currCheckIn['current_checkin'];
		}
		
		//TODO get all geofences where distance from my location is less than error AND (distance - geofence radius) < 75 m
		//should this handle checkouts as well? Maybe we should change checking in and out to suggestions. then use geofencing to confirm
		
		
		//TODO do not have time to test this stuff out. but we should replace this line of text with checkin->fetchPotentialCheckIns
		$query = '(select event.id as place_id, "EVENT" as type, (select id from checkin_place_type where alias="EVENT") as place_type, event.name as name, '.
				'business_info.entity_id as secondary_place_id, business_info.name as secondary_name, if(business_info.entity_id is not NULL, "BUSINESS", null) as secondary_type, geofence_lat, geofence_lon, geofence_radius, hashtag, ifnull(image, "") as image, '.
				$this->util->getHaversineSQLString($lat, $lon, "geofence_lat", "geofence_lon", "m") . ' as distance from event left join business_info on (business_info.entity_id=event.venue_id) left join photo_event on (event.id=photo_event.event_id AND is_flyer=1) ' .
				'left join event_rsvp on (event.id=event_rsvp.event_id AND event_rsvp.entity_id='. $id . ') where geofence_radius > 0 AND start_time < now() AND end_time > now() AND (event_rsvp.state !=(select id from rsvp_state where alias="DECLINE") OR '.
				'creator_id=' .$id. ') having distance < geofence_radius + ' .$this->util->getDistanceError($source, 'm'). ' order by distance limit 100) union '.
				
				'(select entity_id as place_id, "BUSINESS" as type, (select id from checkin_place_type where alias="BUSINESS") as place_type, business_info.name as name, '.
				'event.id as secondary_place_id, event.name as secondary_name, if(event.id is not NULL, "EVENT", null) as secondary_type, location_perm.geofence_lat, location_perm.geofence_lon, location_perm.geofence_radius, "" as hashtag, ifnull(image, "") as image, '.
				$this->util->getHaversineSQLString($lat, $lon, "location_perm.geofence_lat", "location_perm.geofence_lon", "m") . ' as distance from business_info left join event on (venue_id=business_info.entity_id ' .
						'AND start_time < now() AND end_time > now() AND (creator_id=' .$id. ' OR (select state from event_rsvp where event_rsvp.entity_id=' .$id. ' AND event_rsvp.event_id=event.id order by event_rsvp.event_id desc limit 1) != (select id from rsvp_state where alias="DECLINE"))) '. 
						'left join photo_entity on (business_info.entity_id=photo_entity.owner_id AND is_profile=1) ' .
						'join location_perm on (location_perm.id=business_info.entity_id) where location_perm.geofence_radius > 0 having distance < geofence_radius + ' .$this->util->getDistanceError($source, 'm'). ' order by distance limit 100) order by distance, place_type limit 10';
		
		$result = $this->db->query($query);
		
		//$checkInResults['geofences'] = $result->result_array();
		
		
		$checkedIn = false;
		if($result->num_rows() > 0) {
				
			if($currCheckIn != NULL) {
				$result_array = $result->result_array();
				$checkInRow = $this->checkin->getCheckIn($currCheckIn);
		
				//try to see if where we are currently checked in is in our nearby list
				foreach ($result_array as $row) {
					if($row['place_id'] == $checkInRow['place_id'] && $row['place_type'] == $checkInRow['place_type']) {
						$checkedIn = true;
						$row['count'] = $checkInRow['count'];
						$row['last_checkin_time'] = strtotime($checkInRow['last_checkin_time']) * 1000;
						$checkInResults['checked_into'] = $row;
						break;
					}
				}
				//if it isnt, then check us out of where we are
				if(!$checkedIn) {
					$this->info->updateCurrentCheckIn($id, $currCheckIn, NULL);
					$checkedInRow['type'] = $checkInRow['place_type'] == 1 ? "BUSINESS" : "EVENT";
					$checkInResults['checked_out'] = $checkInRow;
				}
					
			}
				
			//if we're still not checked in and we can automatically check in, then do so.
			if(!$checkedIn && $this->info->isAutomaticCheckIn($id)) {
				$row = $result->row_array();
				if(($checkinId = $this->checkin->insertOrUpdateCheckIn($id, $row))) {
					if(($checkedIn = $this->info->updateCurrentCheckIn($id, -1, $checkinId)) && is_array($checkInResults))
						$checkInResults['checked_into'] = $row;
				}
			} else if(!$checkedIn && is_array($checkInResults)) { //we're still not currently checked in, but automatic is turned off, just show user a list of places
				$checkInResults['checkin_list'] = $result->result_array();
			}
		} else if($currCheckIn != NULL)  {//if we're not near anything and we were previous checked in, then check us out
			$this->info->updateCurrentCheckIn($id, $currCheckIn, NULL);
			$checkedInRow = $this->checkin->getCheckIn($currCheckIn);
			$checkedInRow['type'] = $checkInRow['place_type'] == 1 ? "BUSINESS" : "EVENT";
			$checkInResults['checked_out'] = $checkedInRow;
		}
	}
	
	//order by date added, order descending. 
	function getMostRecentLocation($id) {
		
	}
}
?>