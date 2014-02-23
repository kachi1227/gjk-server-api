<?php
class Relationship_general extends CI_Model {

	const TYPE_REL_ALL = 1;
	const TYPE_REL_REQUEST = 1;
	const TYPE_REL_PENDING = 2;
	const TYPE_REL_IMPERSONAL = 4;
	const TYPE_REL_PERSONAL = 8;
	const TYPE_REL_BLOCKED = 16;	
	
	const ALIAS_REL_PENDING = "PENDING";
	const ALIAS_REL_PERSONAL = "PERSONAL";
	const ALIAS_REL_IMPERSONAL ="IMPERSONAL";
	const ALIAS_IMPERSONAL_PENDING = "IMPERSONAL_PENDING";
	const ALIAS_BLOCKED = "BLOCKED";
	
	function __construct() {
		parent::__construct();
		$this->load->library('errorCode'); //used for returning error codes
		$this->load->library('util');
		$this->load->library('maps');
		$this->load->model('Relationship_state', 'state');
		
	}
	/*
	 * if a relationship does not already exist, or if an impersonal 
	 * relationship exists and we are trying to create a personal
	 * one then create this relationship 
	 */
	function createRelationship($array) {
		//we're trying to friend ourselves.	
		if($array['id'] == $array['their_id'])
			return errorCode::REL_ALREADY_CREATED;
		 
		$id_one = $this->db->escape($array['id'] < $array['their_id'] ? $array['id'] : $array['their_id']);
		$id_two = $this->db->escape($array['id'] < $array['their_id'] ? $array['their_id'] : $array['id']);
		
		
		if((!($state = $this->relationshipAlreadyEstablished($id_one, $id_two))) || 
				(((($establishableRelFlags = $this->getEstablishableRelationships($array['id'], $id_one, $state['state_one'], $id_two, $state['state_two'])) & self::TYPE_REL_IMPERSONAL) > 0 && !isset($array['milu_number'])) || (($establishableRelFlags & self::TYPE_REL_PERSONAL) > 0 && isset($array['milu_number'])))) {
			/**
			 * 05/17/13 - I've looked at this again. TODO I think I have a fix for this. Recreate this situation and see if this relationship_refactoring helps.  
			 * Look at this later. If it means nothing to you, then discard. 
			 * 
			 * imp_to_pending = we already had a personal relationship
			 * so if 24 sent a "follow" to 42, it becomes 24|42 = IMPERSONAL
			 * if 24 sends personal request to 42, then 24|42 = IMP_PENDING //duh
			 * but what about if 42 sends personal request to 24, then 24|42 = IMP_PENDING?
			 * 
			 * You can still figure out the relationship from this?  24 sent out the first request. Now 42 wants to take it a step further. 
			 * 24 should still know that 42 exists, since he/she initiated it. This is really only needed for checkin visibility
			 */
			
			
			if(!$state) {//no relationship
				$this->load->model('Entity', 'entity');
				if(!($this->entity->isStandardUser($array['their_id']) && !isset($array['milu_number']))) {
					$state_one = $array['id'] == $id_one ? (isset($array['milu_number']) ? self::TYPE_REL_REQUEST : self::TYPE_REL_IMPERSONAL) : (isset($array['milu_number']) ? self::TYPE_REL_PENDING : 0);
					$state_two = $array['id'] == $id_two ? (isset($array['milu_number']) ? self::TYPE_REL_REQUEST : self::TYPE_REL_IMPERSONAL) : (isset($array['milu_number']) ? self::TYPE_REL_PENDING : 0);
					
					$their_state = $my_state == $state_one ? $state_two : $state_one;
					return $this->db->insert('relationship_general', array('id_one'=>$id_one, 'id_two'=>$id_two, 'state_one'=>$state_one, 'state_two'=>$state_two));
				} else
					return errorCode::REL_NEED_NUMBER;
			} else {
				$state_one = $array['id'] == $id_one ? (isset($array['milu_number']) ? self::TYPE_REL_REQUEST : self::TYPE_REL_IMPERSONAL) : (isset($array['milu_number']) ? self::TYPE_REL_PENDING : 0);
				$state_two = $array['id'] == $id_two ? (isset($array['milu_number']) ? self::TYPE_REL_REQUEST : self::TYPE_REL_IMPERSONAL) : (isset($array['milu_number']) ? self::TYPE_REL_PENDING : 0);				
				
				
				$query = 'update relationship_general set state_one=state_one|' .$state_one. ', state_two=state_two|' .$state_two. " where ". $this->createRelWhereClause($id_one, $id_two); 
				return $this->db->query($query);

			}
		} else {
			return $state['state_one'] == self::TYPE_REL_PERSONAL ? errorCode::REL_ALREADY_CREATED : errorCode::REL_ALREADY_PENDING;
		}
	}
	/*
	 * 
	 * creates a 'personal' relationship if the requesting user has
	 * accepted. if not, the friendship is either deleted or reverted
	 * back to impersonal depending on what the current state is
	 * 
	 */
	function confirmRelationship($array) {
		$id_one = $this->db->escape($array['id'] < $array['their_id'] ? $array['id'] : $array['their_id']);
		$id_two = $this->db->escape($array['id'] < $array['their_id'] ? $array['their_id'] : $array['id']);
		$state_one = $array['id'] == $id_one ?  ('state_one & ~' . self::TYPE_REL_PENDING) : ('state_one & ~' . self::TYPE_REL_REQUEST);
		$state_two = $array['id'] == $id_two ? ('state_two & ~' . self::TYPE_REL_PENDING) : ('state_two & ~' .self::TYPE_REL_REQUEST);
		
		
		$where = $this->createRelWhereClause($id_one, $id_two); 
		if($array['response']) {
			return $this->db->update('relationship_general', array('state_one'=>self::TYPE_REL_PERSONAL, 'state_two'=>self::TYPE_REL_PERSONAL), $where);					
		} else {
			$query = "update relationship_general set state_one=" .$state_one. ", state_two=" .$state_two. " where ".$where;
			$this->db->query($query);
			$this->db->delete('relationship_general', $where . " AND state_one=0 AND state_two=0"); //this means that we have no relationahsip
		}
	}
	
	function createRelWhereClause($id_one, $id_two) {
		$cleanIdOne = $this->db->escape($id_one);
		$cleanIdTwo = $this->db->escape($id_two);
		return '(id_one='.$cleanIdOne. ' AND id_two='.$cleanIdTwo. ')';
	}
	
	function relationshipAlreadyEstablished($firstId, $secondId) {

		$id_one = $this->db->escape($firstId < $secondId ? $firstId : $secondId);
		$id_two = $this->db->escape($firstId < $secondId ? $secondId : $firstId);
		
		$where = '(id_one='.$id_one. ' AND id_two='.$id_two. ')';
		$result = $this->db->select('state_one, state_two')->from('relationship_general')->where($where)->limit(1)->get();
		return $result->num_rows() > 0 ? $result->row_array() : false;
	}
	
	/**
	 * 
	 * Returns a list of relationships that can still be established by the sender id
	 * 
	 * @param unknown_type $sender_id
	 * @param unknown_type $id_one
	 * @param unknown_type $state_one
	 * @param unknown_type $id_two
	 * @param unknown_type $state_two
	 */
	function getEstablishableRelationships($sender_id, $id_one, $state_one, $id_two, $state_two) {
		$my_state = $sender_id == $id_one ? $state_one : $state_two;
		$their_state = $my_state == $state_one ? $state_two : $state_one;
		$relationship = 0;
		
		
		if($my_state & self::TYPE_REL_BLOCKED || $their_state & self::TYPE_REL_BLOCKED || $my_state & self::TYPE_REL_PERSONAL)
			return $relationship;
		
		
		if(($my_state & self::TYPE_REL_IMPERSONAL) == 0) 
			$relationship |= self::TYPE_REL_IMPERSONAL;
		
		if(($my_state & self::TYPE_REL_PERSONAL) == 0 && (($my_state & self::TYPE_REL_REQUEST) == 0 && ($my_state & self::TYPE_REL_PENDING) == 0))
			$relationship |= self::TYPE_REL_PERSONAL;
		
		return $relationship;
	}
	
	public function doesRelationshipExist($senderId, $recipientId) {
		$id_one = $this->db->escape($senderId < $recipientId ? $senderId : $recipientId);
		$id_two = $this->db->escape($senderId < $recipientId ? $recipientId : $senderId);

		$my_state = $senderId == $id_one ? 'state_one' : 'state_two';
		return $this->db->select($my_state)->from('relationship_general')->where('id_one=' .$id_one. ' AND id_two=' .$id_two. ' AND (state_one=' .Relationship_general::TYPE_REL_PERSONAL. ' OR ' .$my_state. ' & ' .self::TYPE_REL_IMPERSONAL. ' > 0)', NULL, false)->count_all_results() > 0;
		
	}
	
	private function getStateClauseFromRelationshipType($my_state, $their_state) {
		
		$clause = "";
		
		if(($my_state & self::TYPE_REL_REQUEST) > 0) 
			$clause .= ((strlen($clause) > 0 ? " OR " : "") .   "my_state & " .self::TYPE_REL_REQUEST. " > 0");
		if(($their_state & self::TYPE_REL_REQUEST) > 0)
			$clause .= ((strlen($clause) > 0 ? " OR " : "") .   "their_state & " .self::TYPE_REL_REQUEST. " > 0");
		if(($my_state & self::TYPE_REL_PENDING) > 0)
			$clause .= ((strlen($clause) > 0 ? " OR " : "") .   "my_state & " .self::TYPE_REL_PENDING. " > 0");
		if(($their_state & self::TYPE_REL_PENDING) > 0)
			$clause .= ((strlen($clause) > 0 ? " OR " : "") .   "their_state & " .self::TYPE_REL_PENDING. " > 0");
		if(($my_state & self::TYPE_REL_IMPERSONAL) > 0)
			$clause .= ((strlen($clause) > 0 ? " OR " : "") .   "my_state & " .self::TYPE_REL_IMPERSONAL. " > 0");
		if(($their_state & self::TYPE_REL_IMPERSONAL) > 0)
			$clause .= ((strlen($clause) > 0 ? " OR " : "") .   "their_state & " .self::TYPE_REL_IMPERSONAL. " > 0");
		if(($my_state & self::TYPE_REL_PERSONAL) > 0)
			$clause .= ((strlen($clause) > 0 ? " OR " : "") .   "my_state & " .self::TYPE_REL_PERSONAL. " > 0");
		if(($their_state & self::TYPE_REL_PERSONAL) > 0) 
			$clause .= ((strlen($clause) > 0 ? " OR " : "") .   "their_state & " .self::TYPE_REL_PERSONAL. " > 0");
		if(($my_state & self::TYPE_REL_BLOCKED) > 0) 
			$clause .= ((strlen($clause) > 0 ? " OR " : "") .   "my_state & " .self::TYPE_REL_BLOCKED. " > 0");
		if(($their_state & self::TYPE_REL_BLOCKED) > 0) 
			$clause .= ((strlen($clause) > 0 ? " OR " : "") .   "their_state & " .self::TYPE_REL_BLOCKED. " > 0");
		
		if(strlen($clause) == 0) //default
			$clause = "my_state=0 AND their_state=0";
		
		return $clause;
	}
	
	function getContacts($id, $entity_type = null, $my_state=15, $their_state=11, $seperatePending = true) {
		$cleanId = $this->db->escape($id);
		$entityClause = isset($entity_type) ? " AND broad_id=".$entity_type : "";
						
		if($seperatePending) {
			$query = "";
			
			$pendingClause = $this->getStateClauseFromRelationshipType($my_state & (self::TYPE_REL_REQUEST|self::TYPE_REL_PENDING), $their_state & (self::TYPE_REL_REQUEST|self::TYPE_REL_PENDING));
			$nonPendingClause = $this->getStateClauseFromRelationshipType($my_state & ~(self::TYPE_REL_REQUEST|self::TYPE_REL_PENDING), $their_state & ~(self::TYPE_REL_REQUEST|self::TYPE_REL_PENDING));	
			$query = 'select * from ((select 1 as rank, entity.id, milu_number, first_name, last_name, name.name, entity_type, broad_id, ifnull(image, "") as image, if(id_one=' .$cleanId. ', state_one, state_two) as my_state, if(id_one=' .$cleanId. ', state_two, state_one) as their_state '.
					' from relationship_general join (entity, specific_user_type, ((select entity_id as id, first_name, last_name, concat(first_name, " ", last_name) as name from person_info) union all (select entity_id as id, name as first_name, "" as last_name, name as name from business_info) union all (select entity_id as id, name as first_name, "" as last_name, name as name from organization_info)) as name) '.
					' on (((id_one='.$cleanId. ' AND id_two=entity.id) OR (id_two=' .$cleanId. ' AND id_one=entity.id)) AND entity.entity_type=specific_user_type.id AND ((id_one='.$cleanId. ' AND id_two=name.id) OR (id_two=' .$cleanId. ' AND id_one=name.id))) left join photo_entity on (((id_one='.$cleanId. ' AND id_two=owner_id) OR (id_two=' .$cleanId. ' AND id_one=owner_id)) AND is_profile=1) '.
					' where (id_one=' .$cleanId. ' OR id_two=' .$cleanId. ')' .$entityClause. ' having ' .$pendingClause. ')'.
					' union all (select 2 as rank, entity.id, milu_number, first_name, last_name, name.name, entity_type, broad_id, ifnull(image, "") as image, if(id_one=' .$cleanId. ', state_one, state_two) as my_state, if(id_one=' .$cleanId. ', state_two, state_one) as their_state '.
					' from relationship_general join (entity, specific_user_type, ((select entity_id as id, first_name, last_name, concat(first_name, " ", last_name) as name from person_info) union all (select entity_id as id, name as first_name, "" as last_name, name as name from business_info) union all (select entity_id as id, name as first_name, "" as last_name, name from organization_info)) as name) '.
					' on (((id_one='.$cleanId. ' AND id_two=entity.id) OR (id_two=' .$cleanId. ' AND id_one=entity.id)) AND entity.entity_type=specific_user_type.id AND ((id_one='.$cleanId. ' AND id_two=name.id) OR (id_two=' .$cleanId. ' AND id_one=name.id))) left join photo_entity on (((id_one='.$cleanId. ' AND id_two=owner_id) OR (id_two=' .$cleanId. ' AND id_one=owner_id)) AND is_profile=1) '.
					' where (id_one=' .$cleanId. ' OR id_two=' .$cleanId. ')' .$entityClause. ' having (' .$nonPendingClause.') AND my_state & 1=0 AND their_state & 1 = 0)) a order by rank, name';
			
			//echo $query;
			
			$result = $this->db->query($query);
			return $result->result_array();
		} else {
			$havingClause = $this->getStateClauseFromRelationshipType($my_state, $their_state);
			$query = 'select entity.id, milu_number, first_name, last_name, name.name, entity_type, broad_id, ifnull(image, "") as image, if(id_one=' .$cleanId. ', state_one, state_two) as my_state, if(id_one=' .$cleanId. ', state_two, state_one) as their_state '.
			' from relationship_general join (entity, specific_user_type, ((select entity_id as id, first_name, last_name, concat(first_name, " ", last_name) as name from person_info) union all (select entity_id as id, name as first_name, "" as last_name, name as name from business_info) union all (select entity_id as id, name as first_name, "" as last_name, name as name from organization_info)) as name) '.
			' on (((id_one='.$cleanId. ' AND id_two=entity.id) OR (id_two=' .$cleanId. ' AND id_one=entity.id)) AND entity.entity_type=specific_user_type.id AND ((id_one='.$cleanId. ' AND id_two=name.id) OR (id_two=' .$cleanId. ' AND id_one=name.id))) left join photo_entity on (((id_one='.$cleanId. ' AND id_two=owner_id) OR (id_two=' .$cleanId. ' AND id_one=owner_id)) AND is_profile=1) '.
			' where (id_one=' .$cleanId. ' OR id_two=' .$cleanId. ')' .$entityClause. ' having ' .$havingClause. ' order by name';
			
			//echo $query;
			
			$result = $this->db->query($query);
			return $result->result_array();
		}		
	}
	
 function getEntitiesOnMap($data) {
 		$this->load->model('Usertype', 'userType');
    	$filter = $data['filter'];
    	$cleanId = $this->db->escape($data['id']);
    	$cleanMinLat = $this->db->escape($data['min_lat']);
    	$cleanMinLon = $this->db->escape($data['min_lon']);
    	$cleanMaxLat = $this->db->escape($data['max_lat']);
    	$cleanMaxLon = $this->db->escape($data['max_lon']);
    	switch($filter) {
    		case 'person':
    			break;
    		case 'business':
    			break;
    		case 'organization':
    			break;
    		case 'event':
    			break;
    		case 'all':
    		default:
    			
    			$sqlQuery = '(select entity.id as id, name.name, location.lat, location.lon, ifnull(image, "") as image, entity_type, specific_user_type.broad_id as broad_type from entity '.
    			'join (specific_user_type, ((select id, lat, lon from location_curr) union all (select location_perm.id, lat, lon from location_perm join (entity, specific_user_type) on (location_perm.id=entity.id AND entity.entity_type=specific_user_type.id) where broad_id != 1)) as location, ' .
    			'((select entity_id as id, concat(first_name, " ", last_name) as name, log_location, map_visible from person_info) union all (select entity_id as id, name, true as log_location, true as map_visible from business_info) union all (select entity_id as id, name, true as log_location, true as map_visible from organization_info)) as name) '.
    			'on (entity_type=specific_user_type.id AND location.id=entity.id AND entity.id=name.id) left join relationship_general on ((id_one=entity.id AND id_two=' .$cleanId. ') OR (id_one=' .$cleanId. ' AND id_two=entity.id)) '.
    			'left join photo_entity on (entity.id=owner_id AND is_profile=1) where ((state_one is NULL AND state_two is NULL AND entity.id=' .$cleanId.') OR state_one = 8 OR (id_one=' .$cleanId. ' AND state_one & 4 > 0 AND broad_id=2) OR (id_two=' .$cleanId. ' AND state_two & 4 > 0 AND broad_id=2)) '. //everything after state_one=8 is so that we can get businesses that we have an impersonal relationship to show up on the map. 
    			' AND (lat >= ' .$cleanMinLat. ' AND lat <= ' .$cleanMaxLat. ' AND lon >= ' . $cleanMinLon. ' AND lon <= ' . $cleanMaxLon. ') AND log_location=TRUE AND map_visible=TRUE)  union all '.
    			
    			'(select event.id as id, event.name, lat, lon, ifnull(image, "") as image, 8 as entity_type, 4 as broad_type from event '.
    			'left join photo_event on (photo_event.event_id=event.id AND is_flyer=1) left join event_rsvp on (event.id=event_rsvp.event_id AND entity_id=' .$cleanId. ') left join rsvp_state on (rsvp_state.id=event_rsvp.state) where'.
    			'(alias="MAYBE" OR alias="ATTEND" OR creator_id=' .$cleanId. ') AND (lat >= ' .$cleanMinLat. ' AND lat <= ' .$cleanMaxLat. ' AND lon >= ' . $cleanMinLon. ' AND lon <= ' . $cleanMaxLon. ')'.
    			' AND timestampdiff(HOUR, now(), start_time) <= 1 AND end_time > now()) order by name';
    			
    			//echo $sqlQuery;
    			
    			$result = $this->db->query($sqlQuery);
				return maps::clusterize($result->result_array(), $data['zoom']);
    	}
    }
    
    function getPersonalAndFollowers($entityId, $filter) {
    	switch($filter) {
    		case 'person':
    			$query = 'select entity.id from relationship_general join(entity, specific_user_type) on (((id_one=entity.id AND id_two=' .$entityId. ') OR (id_one=' .$entityId. ' AND id_two=entity.id)) AND entity_type=specific_user_type.id)'.
    			' where (state_one = ' .self::TYPE_REL_PERSONAL. ' OR ((id_one=' .$entityId. ' AND state_two & ' .self::TYPE_REL_IMPERSONAL. ' > 0) OR (id_two=' .$entityId. ' AND state_one & ' .self::TYPE_REL_IMPERSONAL. ' > 0))) AND broad_id=(select id from broad_user_type where alias=\'PERSON\')';		
    			break;
    	}
    	//echo $query;
    	$result =  $this->db->query($query);
    	return $result->result_array();
    	
    }
    
    static function getContactQuery($entityId) {
    	//just get personal 
    }
	
}
?>