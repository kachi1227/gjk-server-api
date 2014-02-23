<?php
/*
 * Class that retrieves and inserts data into the 'person_info' table
*/
class Message extends CI_Model {

	const CONTENT = "CONTENT";
	const NEWS_STORY = "NEWS_STORY";
	const SHOPPING_LIST = "SHOPPING_LIST";
	const ITEM_INQUIRY = "ITEM_INQUIRY";
	const COUPON = "COUPON";
	const FLYER = "FLYER";
	const JOB_POSTING = "JOB_POSTING";
	const SERVICE_INQUIRY = "SERVICE_INQUIRY";
	const SUGGESTION = "SUGGESTION";
	const DONATION = "DONATION";
	
	const TYPE_INTERACTION 				= "INTERACTION";
	const TYPE_LIVE_STREAM				= "LIVE_STREAM";

	function __construct() {
		parent::__construct();
		$this->load->library('errorCode'); //used for returning error codes
		$this->load->library('util');
		//$this->db->query("SET time_zone='+0:00'");
	}

	private function assignAndRemove(&$array, $key) {
		$value = isset($array[$key]) ?  $array[$key] : null;
		unset($array[$key]);
		return $value;
	}


	function distributeMessage($array) {
		// NOTE: This is backed behind a transaction. if anything fails, we roll everything back!!
		// so we dont need to be as cautious with our integrity checks. mysql will do that
		$interactionInfo = array ();
		
		$this->load->model ( 'datatype', 'datatype' );
		$time = time ();
		$data = array (
				'sender_id' => $this->assignAndRemove ( $array, 'id' ),
				'recipient_id' => $this->assignAndRemove ( $array, 'recipients' ),
				'datatype_id' => $this->datatype->getIdForAlias ( $dataType = $this->assignAndRemove ( $array, 'datatype' ) ),
				'is_public' => $this->assignAndRemove ( $array, 'is_public' ),
				'root_message_id' => $this->assignAndRemove ( $array, 'root_message_id' ),
				'description_values' => $this->assignAndRemove ( $array, 'description_values' ),
				'moment_flag' => 0,
				'date' => date ( 'Y-m-d H:i:s', $time), 
				'last_modified' => date ( 'Y-m-d H:i:s', $time) 
		);
		$attachments = $this->assignAndRemove ( $array, 'attachments' );
		
		if ($dataType == self::DONATION) // TODO keep here until the next release. where we can guarantee that users can't send donations
			return errorCode::MESSAGE_FAILED;
		$id = $this->configureAndInsertMessage ( $array, $dataType, $attachments );
		$data ['table_id'] = $id;
		$recipientArray = $this->determineRecipientsAndSend ( $data, $array, $dataType );
		
		if (count ( $recipientArray ) > 0) {
			// converts our recipients into a sql array.
			$sqlArray = "";
			for($i = 0, $size = count ( $recipientArray ); $i < $size; $i ++) {
				if (strlen ( $sqlArray ) == 0)
					$sqlArray = $sqlArray . "(" . $this->db->escape ( $recipientArray [$i] );
				else
					$sqlArray = $sqlArray . ", " . $this->db->escape ( $recipientArray [$i] );
			}
			$sqlArray .= (strlen ( $sqlArray ) > 0 ? ")" : "('')");
			
			$recipientQuery = 'select id, facebook_id, twitter_id from entity where id in ' . $sqlArray;
			$interactionInfo ['recipients'] = $this->db->query ( $recipientQuery )->result_array ();
		} else
			$interactionInfo ['recipients'] = $recipientArray;
		
		$interactionQuery = 'select max(message.id) as id, sender_id, sender_name, entity_type as sender_entity_type, (select alias from datatype where id=datatype_id) as datatype, root_message_id, is_public, description_values, (unix_timestamp(date) *1000) as date, (unix_timestamp(last_modified) *1000) as last_modified, ifnull(image, "") as sender_image from message ' . 'join (entity, ((select entity_id as id, concat(first_name, " ", last_name) as sender_name from person_info) union all (select entity_id as id, name as sender_name from business_info) union all (select entity_id as id, name as sender_name from organization_info)) as sender_names) ' . 'on (entity.id=sender_id AND sender_names.id=sender_id) left join photo_entity on (owner_id=sender_id AND is_profile=1) where sender_id=' . $data ['sender_id'] . ' AND datatype_id=' . $data ['datatype_id'] . ' AND table_id=' . $data ['table_id'] . ' group by datatype_id, table_id';
		
		$interactionInfo ['interaction'] = $this->db->query ( $interactionQuery )->row_array ();
		
		return $interactionInfo;
	}
	
	
	function configureAndInsertMessage(&$array, $dataType, $attachments = null) {
		$tableName = self::getTableFromDatatypeAlias($dataType);
		switch($dataType) {
			case self::COUPON:
				$array['expiration_date'] = date( 'Y-m-d H:i:s', $array['expiration_date']/1000);
				if(isset($attachments)) 
					$array['image'] = $attachments[0];
				
				$this->db->insert($tableName, $array);
				//errorCode::logError('database', 'message');
				$idResult = $this->db->select('id')->from($tableName)->order_by('id', 'desc')->limit(1)->get()->row_array();
				$id = $idResult['id'];
				break;
			case self::FLYER:
				$array['date'] = date( 'Y-m-d H:i:s', $array['date']/1000);
				if(isset($attachments))
					$array['image'] = $attachments[0];
				$this->db->insert($tableName, $array);
				$idResult = $this->db->select('id')->from($tableName)->order_by('id', 'desc')->limit(1)->get()->row_array();
				$id = $idResult['id'];
				break;
			case self::ITEM_INQUIRY:
				$items = $this->assignAndRemove($array, 'items');
				$array['id'] = NULL; //must do this because at this point there will be nothing inside the array to insert;
				$this->db->insert($tableName, $array);
				$idResult = $this->db->select('id')->from($tableName)->order_by('id', 'desc')->limit(1)->get()->row_array();
				$id = $idResult['id'];
				foreach($items as &$item)
					$item['item_inquiry_id'] = $id;

				unset($item);
				$this->db->insert_batch('item_inquiry_item', $items);
				break;
			case self::JOB_POSTING:
				$reqs = $this->assignAndRemove($array, 'requirements');
				$array['deadline'] = date( 'Y-m-d H:i:s', $array['deadline']/1000);
				$this->db->insert($tableName, $array);
				$idResult = $this->db->select('id')->from($tableName)->order_by('id', 'desc')->limit(1)->get()->row_array();
				$id = $idResult['id'];
				foreach($reqs as &$tag)
					$tag['job_posting_id'] = $id;

				unset($tag);
				$this->db->insert_batch('job_tag_business_employee', $reqs);
				$array['requirements'] = $reqs; //insert back into array, we'll need this later
				break;
			case self::SERVICE_INQUIRY:
				$services = $this->assignAndRemove($array, 'services');
				$array['date_needed'] = date( 'Y-m-d H:i:s', $array['date_needed']/1000);
				$this->db->insert($tableName, $array);
				$idResult = $this->db->select('id')->from($tableName)->order_by('id', 'desc')->limit(1)->get()->row_array();
				$id = $idResult['id'];
				foreach($services as &$service)
					$service['service_id'] = $id;

				unset($service);
				$this->db->insert_batch('service_tag_business_field', $services);
				$array['services'] = $services; //insert back into array, we'll need this later
				break;
			case self::SHOPPING_LIST:
				$items = $this->assignAndRemove($array, 'items');
				$array['pickup_time'] = date( 'Y-m-d H:i:s', $array['pickup_time']/1000);
				$this->db->insert($tableName, $array);
				$idResult = $this->db->select('id')->from($tableName)->order_by('id', 'desc')->limit(1)->get()->row_array();
				$id = $idResult['id'];
				$prefArray = array();
				foreach($items as &$item) {
					$item['shopping_list_id'] = $id;
					if(!isset($prefArray[$item['preference']])) {
						$query = $this->db->select('id')->from('shopping_list_item_preference')->where('alias', $item['preference'])->limit(1)->get();
						$prefArray[$item['preference']] = $query->row()->id;
					}
					$item['preference'] = $prefArray[$item['preference']];
				}
				unset($item);
				$this->db->insert_batch('shopping_list_item', $items);
				break;
			case self::NEWS_STORY:
				if(isset($attachments))
					$array['attachment'] = $attachments[0];
				
				$this->db->insert($tableName, $array); //insert the message
				errorcode::logError("database", "news_story");
				$idResult = $this->db->select('id')->from($tableName)->order_by('id', 'desc')->limit(1)->get()->row_array();
				$id = $idResult['id'];
				break;
			case self::CONTENT:
				
				$array['num_of_attachments'] = isset($attachments) ? count($attachments) : 0;
				
				$this->db->insert($tableName, $array); //insert the message
				$idResult = $this->db->select('id')->from($tableName)->order_by('id', 'desc')->limit(1)->get()->row_array();
				$id = $idResult['id'];
				if(isset($attachments)) {
					$attachmentItems = array();
					foreach($attachments as $file) {
						$attachment = array();
						$attachment['content_id'] = $id;
						$attachment['link'] = $file;
						$attachmentItems[] = $attachment;
					}
					
					$this->db->insert_batch('attachment', $attachmentItems);
				}
				
				break;				
			default:
				$this->db->insert($tableName, $array); //insert the message
				$idResult = $this->db->select('id')->from($tableName)->order_by('id', 'desc')->limit(1)->get()->row_array();
				$id = $idResult['id'];
				break;
		}
		return $id;
	}

	function determineRecipientsAndSend($messageData, $actionData, $dataType) {
		$confirmedRecipients = array();
		switch($dataType) {
			case self::SERVICE_INQUIRY:
				$serviceIds = array();
				$services = $actionData['services'];
				foreach($services as $service)
					$serviceIds[] = $service['tag_id'];
				$this->load->model('Tag', 'tag');
				$recipientArray = $this->tag->getBusinessForService($serviceIds, $actionData['lat'], $actionData['lon']);
				if(!isset($recipientArray) || count($recipientArray) == 0) {
					$messageData['recipient_id'] = null;
					$this->db->insert('message', $messageData);
				} else {
					foreach($recipientArray as $recipient) {
						$messageData['recipient_id'] = $recipient['id'];
						if($messageData['recipient_id'] == $messageData['sender_id'])
							continue;
						$this->db->insert('message', $messageData);
						$confirmedRecipients[] = $messageData['recipient_id'];
					}
				}
				break;
			case self::JOB_POSTING:
				if(in_array(-1, $messageData['recipient_id'])) {
					$jobReqs = array();
					$requirements = $actionData['requirements'];
					foreach($requirements as $requirement)
						$jobReqs[] = $requirement['tag_id'];
					$this->load->model('Tag', 'tag');
					$recipientArray = $this->tag->getPeopleForJobPosting($messageData['sender_id'], $jobReqs);
						
					if(!isset($recipientArray) || count($recipientArray) == 0) {
						$messageData['recipient_id'] = null;
						$this->db->insert('message', $messageData);
					} else {
						foreach($recipientArray as $recipient) {
							$messageData['recipient_id'] = $recipient['id'];
							if($messageData['recipient_id'] == $messageData['sender_id'])
								continue;
							$this->db->insert('message', $messageData);
							$confirmedRecipients[] = $messageData['recipient_id'];
						}
					}
				} else {
					$recipientArray = $messageData['recipient_id'];
					foreach($recipientArray as $recipient) {
						$messageData['recipient_id'] = $recipient;
						if($messageData['recipient_id'] == $messageData['sender_id'])
							continue;
						$this->db->insert('message', $messageData); //else insert message with recipient
						$confirmedRecipients[] = $messageData['recipient_id'];
					}
				}
				break;
			case self::FLYER: //TODO figure out how we're gonna send this thing
			case self::DONATION: //TODO actually configure w/ paypal;
			default:
				if(!isset($messageData['recipient_id']))
					$this->db->insert('message', $messageData); //if no recipient, just insert message as is
				else if(in_array(-1, $messageData['recipient_id'])) {
					$this->load->model('Relationship_general', 'rel');
					$recipientArray = $this->rel->getPersonalAndFollowers($messageData['sender_id'], 'person'); //send to everyone
					foreach($recipientArray as $recipient) {
						$messageData['recipient_id'] = $recipient['id'];
						if($messageData['recipient_id'] == $messageData['sender_id'])
							continue;
						$this->db->insert('message', $messageData);
						$confirmedRecipients[] = $messageData['recipient_id'];
					}
						
				} else {
					$recipientArray = $messageData['recipient_id'];
					foreach($recipientArray as $recipient) {
						$messageData['recipient_id'] = $recipient;
						if($messageData['recipient_id'] == $messageData['sender_id'])
							continue;
						$this->db->insert('message', $messageData); //else insert message with recipient
						$confirmedRecipients[] = $messageData['recipient_id'];
					}

				}
				break;
		}
		
		return $confirmedRecipients;
	}
	
	function editMessage($array) {
		//id, message_id, datatype, updates
		//we probably don't even need this here. But for later iterations, where we may want to keep note of who was the last person to edit a message, this might come into play
		$cleanId = $this->db->escape($array['id']); 
		$cleanMessageId = $this->db->escape($array['message_id']);
		
		$query = $this->db->select('table_id')->from('message')->where('id=' .$cleanMessageId. ' AND datatype_id=(select id from datatype where alias="' .$array['datatype']. '")', NULL, FALSE)->get();
		if($query->num_rows() > 0) {
			$interactionInfo = array();
			
			$tableId = $query->row()->table_id;
			switch($array['datatype']) {
				case self::SHOPPING_LIST:
					$updates = $array['updates'];
					$prefArray = array();
					if(isset($updates['added'])) {
						$addedItems = $updates['added'];
						foreach($addedItems as &$item) {
							$item['shopping_list_id'] = $tableId;
							if(!isset($prefArray[$item['preference']])) {
								$query = $this->db->select('id')->from('shopping_list_item_preference')->where('alias', $item['preference'])->limit(1)->get();
								$prefArray[$item['preference']] = $query->row()->id;
							}
							$item['preference'] = $prefArray[$item['preference']];
						}
						unset($item);
						$this->db->insert_batch('shopping_list_item', $addedItems);
						
						unset($updates['added']); //remove from updates array
					}
					if(isset($updates['modified'])) {
						$modItems = $updates['modified'];
						foreach($modItems as &$item) {
							$item['shopping_list_id'] = $tableId;
							if(!isset($prefArray[$item['preference']])) {
								$query = $this->db->select('id')->from('shopping_list_item_preference')->where('alias', $item['preference'])->limit(1)->get();
								$prefArray[$item['preference']] = $query->row()->id;
							}
							$item['preference'] = $prefArray[$item['preference']];
						}
						unset($item);
						$this->db->update_batch('shopping_list_item', $modItems, 'id');
						
						unset($updates['modified']); //remove from updates array
					}
					if(isset($updates['deleted'])) {
						$this->db->where('id IN ' .$this->util->arrayToSQLArray($updates['deleted']), NULL, FALSE);
						$this->db->delete('shopping_list_item');
						
						unset($updates['deleted']); //remove from updates array
					}
					if(isset($updates['shopping_list_updates'])) {
						$shoppingListUpdate = $updates['shopping_list_updates'];
						if(isset($shoppingListUpdate['pickup_time'])) 
							$shoppingListUpdate['pickup_time'] = date( 'Y-m-d H:i:s', $shoppingListUpdate['pickup_time']/1000);
						$this->db->update('shopping_list', $shoppingListUpdate, array("id"=>$tableId));
					}
					
					$messageUpdate = array();
					if(isset($updates['message_updates'])) {
						$messageUpdate = $updates['message_updates'];	
					}
					$messageUpdate['last_modified'] = date ( 'Y-m-d H:i:s', time());
					$this->db->update('message', $messageUpdate, array('id'=>$cleanMessageId));
					
					break;
				case self::ITEM_INQUIRY:
					$updates = $array['updates'];
					if(isset($updates['added'])) {
						$addedItems = $updates['added'];
						foreach($addedItems as &$item)
							$item['item_inquiry_id'] = $tableId;
						
						unset($item);
						$this->db->insert_batch('item_inquiry_item', $addedItems);
					
						unset($updates['added']); //remove from updates array
					}
					if(isset($updates['modified'])) {
						$modItems = $updates['modified'];
						foreach($modItems as &$item)
							$item['item_inquiry_id'] = $tableId;
						
						unset($item);
						$this->db->update_batch('item_inquiry_item', $modItems, 'id');
					
						unset($updates['modified']); //remove from updates array
					}
					if(isset($updates['deleted'])) {
						$this->db->where('id IN ' .$this->util->arrayToSQLArray($updates['deleted']), NULL, FALSE);
						$this->db->delete('item_inquiry_item');
					
						unset($updates['deleted']); //remove from updates array
					}
						
					$messageUpdate = array();
					if(isset($updates['message_updates']))
						$messageUpdate = $updates['message_updates'];
						
					$messageUpdate['last_modified'] = date ( 'Y-m-d H:i:s', time());
					$this->db->update('message', $messageUpdate, array('id'=>$cleanMessageId));
						
					break;
			}
			
			$interactionQuery = 'select max(message.id) as id, sender_id, sender_name, entity_type as sender_entity_type, (select alias from datatype where id=datatype_id) as datatype, root_message_id, is_public, description_values, (unix_timestamp(date) *1000) as date, (unix_timestamp(last_modified) *1000) as last_modified, ifnull(image, "") as sender_image from message join (entity, ((select entity_id as id, concat(first_name, " ", last_name) as sender_name from person_info) union all '.
			' (select entity_id as id, name as sender_name from business_info) union all (select entity_id as id, name as sender_name from organization_info)) as sender_names) on (entity.id=sender_id AND sender_names.id=sender_id) left join photo_entity on (owner_id=sender_id AND is_profile=1) where datatype_id=(select id from datatype where alias="' .$array['datatype']. '") AND table_id=' . $tableId . ' group by datatype_id, table_id';
			
			$interactionInfo ['interaction'] = $this->db->query ( $interactionQuery )->row_array ();
			
			$recipientQuery = 'select id, facebook_id, twitter_id from entity where id in ' . $sqlArray;
			
			$recipientQuery = 'select sender_id as id from message where sender_id != '.$cleanId. ' AND datatype_id=(select id from datatype where alias="' .$array['datatype']. '") AND table_id=' .$tableId. ' union select recipient_id as id from message where recipient_id != '.$cleanId. ' AND datatype_id=(select id from datatype where alias="' .$array['datatype']. '") AND table_id=' .$tableId;
			$interactionInfo ['recipients'] = $this->db->query ( $recipientQuery )->result_array ();
			
			$editorQuery = 'select entity.id, name, entity_type, ifnull(image, "") as image from entity join (select entity_id as id, concat(first_name, " ", last_name) as name from person_info union all '.
			' select entity_id as id, name as sender_name from business_info union all select entity_id as id, name as sender_name from organization_info) names on (names.id=entity.id) '.
			' left join photo_entity on (owner_id=entity.id AND is_profile=1) where entity.id=' .$cleanId;
			
			$interactionInfo['editor_info'] = $this->db->query($editorQuery)->row_array();
			
			return $interactionInfo;
			
		} else {
			return errorCode::MESSAGE_FAILED;
		}
	}
	
	function getInteractions($id) {
		//need to get the number of new ones, since last time

		//DONT UPDATE TIME UNLESS SUCCESSFUL.

		$cleanId = $this->db->escape($id);
		$query = '(select distinct name, image, message.sender_id, entity_type, (select alias from datatype where id=datatype_id) as datatype, is_public, description_values, moment_flag, (unix_timestamp(date) * 1000) as date, ifnull(recents, 0) as new from message join '.
				' (select concat(first_name, " ", last_name) as name, ifnull(image, "") as image, sender_id, entity_type, max(date) as last_date from message join (entity, person_info) on (entity.id=sender_id AND person_info.entity_id=sender_id) left join photo_entity on (is_profile=1 AND owner_id=sender_id) where recipient_id=' .$cleanId. ' group by sender_id) as s2 on (s2.sender_id=message.sender_id AND s2.last_date=message.date) '.
				'left join (select sender_id, count(message.id) as recents from message left join last_update_time on (recipient_id=last_update_time.id AND last_update_time.type=(select id from update_type where alias="INTERACTION")) where date > ifnull(time, 0) AND recipient_id=' .$cleanId. ' group by sender_id) as s3 on (message.sender_id=s3.sender_id)) union all' .

				'(select distinct name, image, message.sender_id, entity_type, (select alias from datatype where id=datatype_id) as datatype, is_public, description_values, moment_flag, (unix_timestamp(date) * 1000) as date, ifnull(recents, 0) as new from message join '.
				' (select name, ifnull(image, "") as image, sender_id, entity_type, max(date) as last_date from message join (entity, business_info) on (entity.id=sender_id AND business_info.entity_id=sender_id) left join photo_entity on (is_profile=1 AND owner_id=sender_id) where recipient_id=' .$cleanId. ' group by sender_id) as s2 on (s2.sender_id=message.sender_id AND s2.last_date=message.date) '.
				'left join (select sender_id, count(message.id) as recents from message left join last_update_time on (recipient_id=last_update_time.id AND last_update_time.type=(select id from update_type where alias="INTERACTION")) where date > ifnull(time, 0) AND recipient_id=' .$cleanId. ' group by sender_id) as s3 on (message.sender_id=s3.sender_id)) union all' .

				'(select distinct name, image, message.sender_id, entity_type, (select alias from datatype where id=datatype_id) as datatype, is_public, description_values, moment_flag, (unix_timestamp(date) * 1000) as date, ifnull(recents, 0) as new from message join '.
				' (select name, ifnull(image, "") as image, sender_id, entity_type, max(date) as last_date from message join (entity, organization_info) on (entity.id=sender_id AND organization_info.entity_id=sender_id) left join photo_entity on (is_profile=1 AND owner_id=sender_id) where recipient_id=' .$cleanId. ' group by sender_id) as s2 on (s2.sender_id=message.sender_id AND s2.last_date=message.date) '.
				'left join (select sender_id, count(message.id) as recents from message left join last_update_time on (recipient_id=last_update_time.id AND last_update_time.type=(select id from update_type where alias="INTERACTION")) where date > ifnull(time, 0) AND recipient_id=' .$cleanId. ' group by sender_id) as s3 on (message.sender_id=s3.sender_id)) order by new desc, name asc limit 100';

		$result = $this->db->query($query);

		$insertQuery = 'insert into last_update_time values(' .$cleanId. ', (select id from update_type where alias="INTERACTION"), now()) on duplicate key update time=now()';
		$this->db->query($insertQuery);

		return $result->result_array();

	}

	function getInteractionsWithUser($myId, $theirId, $lastMessageId = -1) {
		$this->load->model('moment', 'moment');
		//THE FIRST time through we want the most recent 100 interactions in ASC order
		//all subsequent times we want the next 100 in only ascending order
		
		
		//where sender id=their_id AnD recipient=my_id OR sender_id=my_id AND recipient_id=their_id AND root_message_id=NULL

		$cleanMyId = $this->db->escape($myId);
		$cleanTheirId = $this->db->escape($theirId);
		
		
		$query = 'select a.id, a.sender_id, a.sender_name, sender_entity_type, a.recipient_id, group_concat(concat(message.recipient_id, ":", recipient_names.recipient_name) separator "|") as recipients, a.datatype, a.is_public, a.root_message_id, a.moment_flag, a.description_values, a.date, a.last_modified, a.table_id, sender_image ' .
				'from (select if(root_message_id is null, root_id, message.id) as id, sender_id, sender_name, entity_type as sender_entity_type, recipient_id, datatype_id, (select alias from datatype where id=datatype_id) as datatype, is_public, root_message_id, '.
				'(select case when root_sender_id=' .$cleanMyId. ' AND root_moment_flag & ' . moment::FLAG_SENDER. '> 0 then if(sender_id=' .$cleanMyId. ',' .moment::FLAG_SENDER. ',' .moment::FLAG_RECIPIENT. ') '.
				'when (select moment_flag from message where id= (select min(id) from message where (root_message_id=root_id OR (root_message_id is null AND datatype_id=root_datatype AND table_id=root_table_id)) AND (sender_id=' .$cleanMyId. ' OR recipient_id=' .$cleanMyId. '))) & ' .moment::FLAG_RECIPIENT. ' > 0 then if(sender_id=' .$cleanMyId. ', ' .moment::FLAG_SENDER. ', ' .moment::FLAG_RECIPIENT. ') else 0 end) as moment_flag, '.
				'description_values, (unix_timestamp(date) *1000) as date, (unix_timestamp(last_modified) * 1000) as last_modified, table_id, ifnull(image, "") as sender_image from '.
				'(select * from message join (select min(id) as root_id, sender_id as root_sender_id, moment_flag as root_moment_flag, datatype_id as root_datatype, table_id as root_table_id from message group by root_datatype, root_table_id) as root_message on (message.root_message_id=root_id OR (root_message_id is NULL AND message.table_id=root_table_id AND message.datatype_id=root_datatype))) as message '.
				'join (entity, ((select entity_id as id, concat(first_name, " ", last_name) as sender_name from person_info) union all (select entity_id as id, name as sender_name from business_info) union all (select entity_id as id, name as sender_name from organization_info)) as sender_names) '.
				'on (entity.id=sender_id AND sender_names.id=sender_id) left join photo_entity on (owner_id=sender_id AND is_profile=1) where ((sender_id=' .$cleanTheirId. ' AND recipient_id=' .$cleanMyId. ') OR (sender_id=' .$cleanMyId. ' AND recipient_id=' .$cleanTheirId. 
				')) AND (message.id in (select max(id) from message where root_message_id is not null AND ((sender_id=' .$cleanTheirId. ' AND recipient_id=' .$cleanMyId. ') OR (sender_id=' .$cleanMyId. ' AND recipient_id=' .$cleanTheirId. ')) group by root_message_id) OR '.
				'(message.id not in (select distinct root_message_id from message where root_message_id is not null) AND root_message_id is null) AND message.id >' .$lastMessageId. ') order by last_modified '. ($lastMessageId < 0 ? 'desc' : 'asc'). ' limit 100) a ' .
				' join (message, ((select entity_id as id, concat(first_name, " ", last_name) as recipient_name from person_info) union all (select entity_id as id, name as recipient_name from business_info) union all (select entity_id as id, name as recipient_name from organization_info)) as recipient_names) '.
				' on (a.table_id=message.table_id AND message.datatype_id=a.datatype_id AND message.recipient_id=recipient_names.id) group by message.table_id, datatype order by a.last_modified asc';
		//echo $query;
		$result = $this->db->query($query);
		return $result->result_array();

	}

	/**
	 * 
	 * This is used to get a single interaction
	 * 
	 * @param unknown_type $messageId
	 * @param unknown_type $datatype
	 * @param unknown_type $lastMessageId
	 * @return multitype:
	 */
	function getInteractionItem($messageId, $datatype, $lastMessageId = -1) {
		//get the table from datatype;
		//get all from the table

		//get the entire conversation thread.

		$rootResult = $this->db->select('ifnull(root_message_id, id) as root_message_id, table_id', FALSE)->from('message')->where('id', $messageId)->get()->row_array();
		
		$rootMessageId = $rootResult['root_message_id'];
		$tableId = $rootResult['table_id'];
		
		//TODO do proper moment check here!! is this a user's moment? can a user moment this?
		
		switch($datatype) {
			case self::CONTENT:
				$sqlQuery = 'select max(message.id) as id, "' . self::TYPE_INTERACTION. '" as type,  sender_id, sender_name, entity_type, root_message_id, (unix_timestamp(date) * 1000) as date, content, mood_id, ifnull(image, "") as image, ' .
				'(select group_concat(distinct attachment.link separator "|") from attachment where table_id=attachment.content_id) as attachments, group_concat(concat(recipient_id, ":", recipient.name) separator "|") as recipients from message join (entity, content, '.
				'((select entity_id as id, concat(first_name, " ", last_name) as sender_name from person_info) union all (select entity_id as id, name as sender_name from business_info) union all (select entity_id as id, name as sender_name from organization_info)) as sender_names)'.
				' on (sender_id=sender_names.id AND sender_id=entity.id AND datatype_id=(select id from datatype where alias="CONTENT") AND table_id=content.id) left join photo_entity on (sender_id=owner_id AND is_profile=1)'.
						' left join ((select entity_id as id, concat(first_name, " ", last_name) as name from person_info) union all (select entity_id as id, name from business_info) union all (select entity_id as id, name from organization_info)) as recipient on recipient_id=recipient.id '.
						'where (message.table_id=(select table_id from message where id=' .$rootMessageId. ') OR root_message_id=' .$rootMessageId. ') AND message.id > ' .$lastMessageId. ' group by table_id order by date ASC';
				
				//commented this out to add recipient list properly
				/*$sqlQuery = 'select max(message.id) as id, "' .self::TYPE_INTERACTION. '" as type, results.sender_id, results.sender_name, results.entity_type, results.root_message_id, results.date, results.content, results.mood_id, results.image, results.attachments, group_concat(concat(results.recipient_id, ":", results.recipient_name) separator "|") as recipients from message join (select distinct sender_id, sender_name, recipient_id, recipient.name as recipient_name, datatype_id, table_id, entity_type, root_message_id, (unix_timestamp(date) * 1000) as date, content, mood_id, ifnull(image, "") as image, (select group_concat(distinct attachment.link separator "|") from attachment where table_id=attachment.content_id) as attachments from message join (entity, content, '.
				'((select entity_id as id, concat(first_name, " ", last_name) as sender_name from person_info) union all (select entity_id as id, name as sender_name from business_info) union all (select entity_id as id, name as sender_name from organization_info)) as sender_names)'.
				' on (sender_id=sender_names.id AND sender_id=entity.id AND datatype_id=(select id from datatype where alias="CONTENT") AND table_id=content.id) left join photo_entity on (sender_id=owner_id AND is_profile=1)'.
				' left join ((select entity_id as id, concat(first_name, " ", last_name) as name from person_info) union all (select entity_id as id, name from business_info) union all (select entity_id as id, name from organization_info)) as recipient on recipient_id=recipient.id '.
				'where (message.id=' .$rootMessageId. ' OR root_message_id=' .$rootMessageId. ') AND message.id > ' .$lastMessageId. ') as results on (results.sender_id=message.sender_id AND results.datatype_id=message.datatype_id AND results.table_id = message.table_id) group by results.table_id order by date ASC';*/

				//echo $sqlQuery;
				$interaction =  array("items"=>$this->db->query($sqlQuery)->result_array());
				break;

			case self::NEWS_STORY:
				$interaction = $this->db->select('title, content, attachment, (unix_timestamp(date) * 1000) as date')->from('news_story')->join('message', 'message.table_id=news_story.id AND datatype_id=(select id from datatype where alias="NEWS_STORY")')->where('news_story.id', $tableId)->get()->row_array();
				break;
					
			case self::SHOPPING_LIST:

				$interaction = $this->db->select('tax, (unix_timestamp(pickup_time) * 1000) as pickup_time, (unix_timestamp(date) * 1000) as date', FALSE)->from('shopping_list')->join('message', 'message.table_id=shopping_list.id AND datatype_id=(select id from datatype where alias="SHOPPING_LIST")')->where('shopping_list.id', $tableId)->get()->row_array();
				$interaction['items'] = $this->db->select('shopping_list_item.id, item, price, num_requested, num_available, order, shopping_list_item_preference.alias as preference')->from('shopping_list_item')->join('shopping_list_item_preference', 'shopping_list_item.preference=shopping_list_item_preference.id')->where('shopping_list_id', $tableId)->order_by('order asc, shopping_list_item.id asc')->get()->result_array();
				break;

			case self::ITEM_INQUIRY:
				$interaction = array('items'=>$this->db->select('item_inquiry_item.id, item, num_requested, num_available, order')->from('item_inquiry_item')->where('item_inquiry_id', $tableId)->order_by('order asc, item_inquiry_id asc')->get()->result_array());
				break;

			case self::COUPON:
				$interaction = $this->db->select('item_name, offer, (unix_timestamp(expiration_date) * 1000) as expiration_date, details, promotion_code, image, (unix_timestamp(date) * 1000) as date')->from('coupon')->join('message', 'message.table_id=coupon.id AND datatype_id=(select id from datatype where alias="COUPON")')->where('coupon.id', $tableId)->get()->row_array();
				break;
					
			case self::FLYER:
				$interaction = $this->db->select('title, lat, lon, (unix_timestamp(date) * 1000) as date, details, contact_info, image')->from('flyer')->where('id', $tableId)->get()->row_array();
				break;
					
			case self::JOB_POSTING:
				$interaction = $this->db->select('position, department, (unix_timestamp(deadline) * 1000) as deadline, description, (unix_timestamp(date) * 1000) as date')->from('job_posting')->join('message', 'message.table_id=job_posting.id AND datatype_id=(select id from datatype where alias="JOB_POSTING")')->where('job_posting.id', $tableId)->get()->row_array();
				errorCode::logError("database", "job_posting");
				$interaction['requirements'] = $this->db->select('alias, name')->from('job_tag_business_employee')->join('tag_business_employee', 'job_tag_business_employee.tag_id=tag_business_employee.id')->where('job_posting_id', $tableId)->get()->result_array();
				break;
				
			case self::SERVICE_INQUIRY:
				$interaction = $this->db->select('lat, lon, (unix_timestamp(date_needed) * 1000) as date_needed, details, (unix_timestamp(date) * 1000) as date')->from('service_inquiry')->join('message', 'message.table_id=service_inquiry.id AND datatype_id=(select id from datatype where alias="SERVICE_INQUIRY")')->where('service_inquiry.id', $tableId)->get()->row_array();
				$interaction['services'] = $this->db->select('alias, name')->from('service_tag_business_field')->join('tag_business_field', 'service_tag_business_field.tag_id=tag_business_field.id')->where('service_id', $tableId)->get()->result_array();
				break;
				
			case self::SUGGESTION:
				$interaction = $this->db->select('content, (unix_timestamp(date) * 1000) as date')->from('suggestion')->join('message', 'message.table_id=suggestion.id AND datatype_id=(select id from datatype where alias="SUGGESTION")')->where('suggestion.id', $tableId)->get()->row_array();
				break;
				
			case self::DONATION:
				$interaction = $this->db->select('amount, for, (unix_timestamp(date) * 1000) as date')->from('donation')->join('message', 'message.table_id=donation.id AND datatype_id=(select id from datatype where alias="DONATION")')->where('donation.id', $tableId)->get()->row_array();
				break;
		}

		return $interaction;
	}
	
	function getCompleteInteractionWithUser($myId, $theirId, $messageId, $datatype) {
		$this->load->model('moment', 'moment');
		
		$cleanMyId = $this->db->escape($myId);
		$cleanTheirId = $this->db->escape($theirId);
		
		$rootResult = $this->db->select('ifnull(root_message_id, id) as root_message_id, table_id', FALSE)->from('message')->where('id', $messageId)->get()->row_array();
		
		$rootMessageId = $rootResult['root_message_id'];
		$tableId = $rootResult['table_id'];
		
		
		$query = 'select a.id, a.sender_id, a.sender_name, sender_entity_type, a.recipient_id, group_concat(concat(message.recipient_id, ":", recipient_names.recipient_name) separator "|") as recipients, a.datatype, a.is_public, a.root_message_id, a.moment_flag, a.description_values, a.date, a.last_modified, a.table_id, sender_image from' .
				
				'(select if(root_message_id is null, root_id, message.id) as id, sender_id, sender_name, entity_type as sender_entity_type, recipient_id, datatype_id, (select alias from datatype where id=datatype_id) as datatype, is_public, root_message_id, '.
				'(select case when root_sender_id=' .$cleanMyId. ' AND root_moment_flag & ' . moment::FLAG_SENDER. '> 0 then if(sender_id=' .$cleanMyId. ',' .moment::FLAG_SENDER. ',' .moment::FLAG_RECIPIENT. ') '.
				'when (select moment_flag from message where id= (select min(id) from message where (root_message_id=root_id OR (root_message_id is null AND datatype_id=root_datatype AND table_id=root_table_id)) AND (sender_id=' .$cleanMyId. ' OR recipient_id=' .$cleanMyId. '))) & ' .moment::FLAG_RECIPIENT. ' > 0 then if(sender_id=' .$cleanMyId. ', ' .moment::FLAG_SENDER. ', ' .moment::FLAG_RECIPIENT. ') else 0 end) as moment_flag, '.
				'description_values, (unix_timestamp(date) *1000) as date, (unix_timestamp(last_modified) * 1000) as last_modified, table_id, ifnull(image, "") as sender_image from '.
				
				//this is going to create a table called message that contains all the rows from message in addition about the root message (root_id, who the root_sender was, what the moment_flag of the root message is, etc)
				'(select * from message join (select min(id) as root_id, sender_id as root_sender_id, moment_flag as root_moment_flag, datatype_id as root_datatype, table_id as root_table_id from message group by root_datatype, root_table_id) as root_message on (message.root_message_id=root_id OR (root_message_id is NULL AND message.table_id=root_table_id AND message.datatype_id=root_datatype))) as message '.
				
				//going to join this table with entity as well as sender names and then with photos. So now we'll be able to get the name & photo of the entity
				'join (entity, ((select entity_id as id, concat(first_name, " ", last_name) as sender_name from person_info) union all (select entity_id as id, name as sender_name from business_info) union all (select entity_id as id, name as sender_name from organization_info)) as sender_names) '.
				'on (entity.id=sender_id AND sender_names.id=sender_id) left join photo_entity on (owner_id=sender_id AND is_profile=1) ' .
				
				//these next three lines say that we should get only messages where sender_id = their id or our id (or vice versa) AND
				//where the id is the last message between two users OR it's the id of an interaction with no responses
				'where ((sender_id=' .$cleanTheirId. ' AND recipient_id=' .$cleanMyId. ') OR (sender_id=' .$cleanMyId. ' AND recipient_id=' .$cleanTheirId.'))'.
				'AND (message.table_id=(select table_id from message where id=' .$rootMessageId. ') OR root_message_id=' .$rootMessageId. ') AND message.datatype_id=(select id from datatype where alias="' .$datatype.'") order by message.id desc limit 1) a ' .
		
				' join (message, ((select entity_id as id, concat(first_name, " ", last_name) as recipient_name from person_info) union all (select entity_id as id, name as recipient_name from business_info) union all (select entity_id as id, name as recipient_name from organization_info)) as recipient_names) '.
				' on (a.table_id=message.table_id AND message.datatype_id=a.datatype_id AND message.recipient_id=recipient_names.id) group by message.table_id, datatype order by a.last_modified asc';
		//echo $query;
		$result = $this->db->query($query);
		$interaction= array();
		$interaction['interaction_info'] = $result->row_array();
		$interaction['interaction'] = $this->getInteractionItem($messageId, $datatype);
		
		return $interaction;
	}
	
	function getStream($json) {
		$this->load->model('Usertype', 'userType');
		$this->load->model('moment', 'moment');
		$this->load->model('Relationship_general', 'rel');		
		$cleanId = $this->db->escape($json['id']);

		if(!isset($json['message_range']))
			$messageIdClause =  "message.id > 0";
		else if($json['message_range'][0] == -1 && $json['message_range'][1] == -1 && isset($json['livestream_range'])) {
			//need to check if we're going up or down
			if($json['livestream_range'][1] == -1)
				$messageClause = "message.date >= ifnull((select date from event_livestream_item where id=" . $this->db->escape($json['livestream_range'][0]). "), 0)";
			else
				$messageClause = "message.date >= ifnull((select date from event_livestream_item where id=" .$json['livestream_range'][0]. "), 0) AND message.date =< ifnull((select date from event_livestream_item where id=" . $json['livestream_range'][1]. "), 0)";
			 
		} else if($json['message_range'][1] == -1)
			$messageIdClause =  "message.id > " . $this->db->escape($json['message_range'][0]);
		else 
			$messageIdClause = "message.id > " .$json['message_range'][0]. ' AND message.id <' . $json['message_range'][1];
		
		if(!isset($json['livestream_range']))
			$livestreamIdClause =  "event_livestream_item.id > 0";
		else if($json['livestream_range'][0] == -1 && $json['livestream_range'][1] == -1 && isset($json['message_range'])) {//we'll use message_range to see what times we should pull from
			
			if($json['message_range'][1] == -1)
				$livestreamIdClause = "event_livestream_item.date >= ifnull((select date from message where id=" . $this->db->escape($json['message_range'][0]). "), 0)";
			else
				$livestreamIdClause = "event_livestream_item.date >= ifnull((select date from message where id=" .$json['message_range'][0]. "), 0) AND event_livestream_item.date =< ifnull((select date from message where id=" . $json['message_range'][1]. "), 0)";
			
		} else if($json['livestream_range'][1] == -1)
			$livestreamIdClause =  "event_livestream_item.date > ifnull((select date from event_livestream_item where id=" . $this->db->escape($json['livestream_range'][0]). "), 0)";
		else 
			$livestreamIdClause = "event_livestream_item.date > ifnull((select date from event_livestream_item where id=" .$json['livestream_range'][0]. "), 0) AND event_livestream_item.date < ifnull((select date from event_livestream_item where id=" . $json['livestream_range'][1]. "), 0)"; //on error this will return an empty
		
		
		//TODO if message_range + livestream_range are both not set, then regular order by date DESC works fine. 
		//if either one of these are set, what we need to do instead is a order by date ASC limit 100, then order by date DESC
		
		$gettingNew = (isset($json['message_range']) && ($json['message_range'][0] != -1 && $json['message_range'][1] == -1)) ||
		(isset($json['livestream_range']) && ($json['livestream_range'][0] != -1 && $json['livestream_range'][1] == -1));
		
		
		if($json['all']) {
			$sqlQuery = ($gettingNew ? 'select * from (' : '') .'(select max(message.id) as id, sender_id, name.name as sender_name, entity_type, datatype.alias, (unix_timestamp(date) * 1000) as date, root_message_id, description_values as content, ifnull(image, "") as image, table_id, "' .self::TYPE_INTERACTION. '" as type, ' .
			'(select group_concat(distinct attachment.link separator "|") from attachment where table_id=attachment.content_id) as attachments, group_concat(concat(recipient_id, ":", recipient.name) separator "|") as recipients '.
			'from message join(datatype, entity, ((select entity_id as id, concat(first_name, " ", last_name) as name from person_info) union all (select entity_id as id, name from business_info) union all (select entity_id as id, name from organization_info)) as name) '.
			'on (datatype.id=datatype_id AND sender_id=entity.id AND sender_id=name.id) left join relationship_general on ((id_one=sender_id AND id_two=' .$cleanId. ') OR (id_one=' .$cleanId. ' AND id_two=sender_id)) ' .
			'left join ((select entity_id as id, concat(first_name, " ", last_name) as name from person_info) union all (select entity_id as id, name from business_info) union all (select entity_id as id, name from organization_info)) as recipient on recipient_id=recipient.id '.
			'left join photo_entity on (sender_id=owner_id AND is_profile=1) where ((state_one is NULL AND state_two is NULL AND entity.id=' .$cleanId.') OR (state_one = ' . Relationship_general::TYPE_REL_PERSONAL. ' OR (id_one=' .$cleanId. ' AND state_one & ' .Relationship_general::TYPE_REL_IMPERSONAL. ' > 0) OR (id_two=' .$cleanId. ' AND state_two & ' .Relationship_general::TYPE_REL_IMPERSONAL. ' > 0))) AND '. 
			'is_public=1 AND ' .$messageIdClause. ' group by datatype.alias, table_id) union all ' .
			
			
			'(select event_livestream_item.id, event.id as sender_id, event.name as sender_name, 8 as entity_type, (select alias from livestream_type where id=stream_type) as alias, (unix_timestamp(event_livestream_item.date) * 1000) as date, null as root_message_id, ' .
			'concat(if(relationship_general.state_one=' .Relationship_general::TYPE_REL_PERSONAL. ' OR event_livestream_item.entity_id=' .$cleanId.', concat(first_name, " ", last_name),"Someone"), if(stream_type !=(select id from livestream_type where alias="COMMENT")," ", ": "), comment) as content, '.
			'ifnull(image, "") as image, null as table_id, "' .self::TYPE_LIVE_STREAM. '" as type, (select source from media_livestream where livestream_item_id=event_livestream_item.id) as attachments, null as recipients '. 
			'from event_livestream_item join (person_info, event) on (person_info.entity_id=event_livestream_item.entity_id AND event.id=event_livestream_item.event_id) left join event_rsvp on (event.id=event_rsvp.event_id AND event_rsvp.entity_id=' .$cleanId. ') left join rsvp_state on (rsvp_state.id=event_rsvp.state) ' .
			'left join relationship_general on ((id_one=event_livestream_item.entity_id AND id_two=' .$cleanId. ') OR (id_one=' .$cleanId. ' AND id_two=event_livestream_item.entity_id)) left join photo_event on (photo_event.event_id=event_livestream_item.event_id AND is_flyer=1) where'.
    		'(alias="MAYBE" OR alias="ATTEND" OR creator_id=' .$cleanId. ') AND hidden=0 AND ' .$livestreamIdClause. ') order by date ' .($gettingNew ? 'asc': 'desc'). ' LIMIT 100' . ($gettingNew ? ') as a order by date desc' : '');
			
			//echo $sqlQuery;
		} else {
			
			$clusterEntities = $json['ids'];
			$entityArray = "";
			$eventArray = "";
			$photoArray = "";
			$size = count($clusterEntities);
			//converts our recipients into a sql array.
			for($i = 0; $i < $size; $i++) {
				$clusterEntity = $clusterEntities[$i];
				switch($clusterEntity['type']) {
					case util::TYPE_MAP_ENTITY:
						$sqlArray = &$entityArray;
						break;
					case util::TYPE_MAP_EVENT:
						$sqlArray  = &$eventArray;
						break;
					case util::TYPE_MAP_PHOTO:
						$sqlArray = &$photoArray;
				}
					
				if(strlen($sqlArray) == 0)
					$sqlArray = $sqlArray . "(" . $this->db->escape($clusterEntity['id']);
				else
					$sqlArray = $sqlArray . ", " . $this->db->escape($clusterEntity['id']);
			}
			
			$entityArray.= (strlen($entityArray) > 0 ? ")": "('')");
			$eventArray.= (strlen($eventArray) > 0 ? ")": "('')");
			$photoArray.= (strlen($photoArray) > 0 ? ")": "('')");
				
			
			$sqlQuery = '(select max(message.id) as id, sender_id, name.name as sender_name, entity_type, datatype.alias, (unix_timestamp(date) * 1000) as date, root_message_id, description_values as content, ifnull(image, "") as image, table_id, "' .self::TYPE_INTERACTION. '" as type, ' .
					'(select group_concat(distinct attachment.link separator "|") from attachment where table_id=attachment.content_id) as attachments, group_concat(concat(recipient_id, ":", recipient.name) separator "|") as recipients '.
					'from message join(datatype, entity, ((select entity_id as id, concat(first_name, " ", last_name) as name from person_info) union all (select entity_id as id, name from business_info) union all (select entity_id as id, name from organization_info)) as name) '.
					'on (datatype.id=datatype_id AND sender_id=entity.id AND sender_id=name.id) left join photo_entity on (sender_id=owner_id AND is_profile=1) '.
					'left join ((select entity_id as id, concat(first_name, " ", last_name) as name from person_info) union all (select entity_id as id, name from business_info) union all (select entity_id as id, name from organization_info)) as recipient on recipient_id=recipient.id '.
					'where is_public=1 AND ' .$messageIdClause. ' AND sender_id IN ' .$entityArray. ' group by datatype.alias, table_id) union all ' .
			
			
					'(select event_livestream_item.id, event.id as sender_id, event.name as sender_name, 8 as entity_type, (select alias from livestream_type where id=stream_type) as alias, (unix_timestamp(event_livestream_item.date) * 1000) as date, null as root_message_id, ' .
					'concat(if(relationship_general.state_one=' .Relationship_general::TYPE_REL_PERSONAL. ' OR event_livestream_item.entity_id=' .$cleanId.', concat(first_name, " ", last_name),"Someone"), if(stream_type !=(select id from livestream_type where alias="COMMENT")," ", ": "), comment) as content, '.
					'ifnull(image, "") as image, null as table_id, "' .self::TYPE_LIVE_STREAM. '" as type, (select source from media_livestream where livestream_item_id=event_livestream_item.id) as attachments, null as recipients '.
					'from event_livestream_item join (person_info, event) on (person_info.entity_id=event_livestream_item.entity_id AND event.id=event_livestream_item.event_id) ' .
					'left join relationship_general on ((id_one=event_livestream_item.entity_id AND id_two=' .$cleanId. ') OR (id_one=' .$cleanId. ' AND id_two=event_livestream_item.entity_id)) left join photo_event on (photo_event.event_id=event_livestream_item.event_id AND is_flyer=1) where '.
					'event.id IN ' .$eventArray.' AND ' .$livestreamIdClause. ' AND hidden=0) order by date DESC LIMIT 100';
						
		}
		//echo $sqlQuery;
		$result = $this->db->query($sqlQuery);
		return $result->result_array();
	}

	function getMessagesAfterTime($json) {
		$cleanId = $this->db->escape($json['id']);
		$cleanTime = $this->db->escape($json['last_notified']);

		$sqlQuery = '(select distinct concat(first_name, " ", last_name) as name from message join (datatype, entity, person_info) on (datatype.id=datatype_id AND sender_id=person_info.entity_id AND sender_id=entity.id) ' .
				'where (select broad_id from specific_user_type where id=entity_type)=(select id from broad_user_type where alias=\'PERSON\') AND recipient_id=' .$cleanId. ' AND sender_id !=' .$cleanId. ' AND unix_timestamp(date) > ' .$cleanTime. ') union all ' .

				//business
		'(select distinct business_info.name from message join (datatype, entity, business_info) on (datatype.id=datatype_id AND sender_id=business_info.entity_id AND sender_id=entity.id) ' .
		'where (select broad_id from specific_user_type where id=entity_type)=(select id from broad_user_type where alias=\'BUSINESS\') AND recipient_id=' .$cleanId. ' AND sender_id !=' .$cleanId. ' AND unix_timestamp(date) > ' .$cleanTime. ') union all ' .

		//organization
		'(select distinct organization_info.name from message join (datatype, entity, organization_info) on (datatype.id=datatype_id AND sender_id=organization_info.entity_id AND sender_id=entity.id) ' .
		'where (select broad_id from specific_user_type where id=entity_type)=(select id from broad_user_type where alias=\'ORGANIZATION\') AND recipient_id=' .$cleanId. ' AND sender_id !=' .$cleanId. ' AND unix_timestamp(date) > ' .$cleanTime. ') order by name ASC';

		$sqlCountQuery = 'select count(name) as total from ((select concat(first_name, " ", last_name) as name from message join (datatype, entity, person_info) on (datatype.id=datatype_id AND sender_id=person_info.entity_id AND sender_id=entity.id) ' .
				'where (select broad_id from specific_user_type where id=entity_type)=(select id from broad_user_type where alias=\'PERSON\') AND recipient_id=' .$cleanId. ' AND sender_id !=' .$cleanId. ' AND unix_timestamp(date) > ' .$cleanTime. ') union all ' .

				//business
		'(select distinct business_info.name from message join (datatype, entity, business_info) on (datatype.id=datatype_id AND sender_id=business_info.entity_id AND sender_id=entity.id) ' .
		'where (select broad_id from specific_user_type where id=entity_type)=(select id from broad_user_type where alias=\'BUSINESS\') AND recipient_id=' .$cleanId. ' AND sender_id !=' .$cleanId. ' AND unix_timestamp(date) > ' .$cleanTime. ') union all ' .

		//organization
		'(select distinct organization_info.name from message join (datatype, entity, organization_info) on (datatype.id=datatype_id AND sender_id=organization_info.entity_id AND sender_id=entity.id) ' .
		'where (select broad_id from specific_user_type where id=entity_type)=(select id from broad_user_type where alias=\'ORGANIZATION\') AND recipient_id=' .$cleanId. ' AND sender_id !=' .$cleanId. ' AND unix_timestamp(date) > ' .$cleanTime. ')) countMessage order by name ASC';

		//echo $sqlCountQuery;
		$output = array();
		$output['items'] = $this->db->query($sqlQuery)->result_array();
		$total = $this->db->query($sqlCountQuery)->row_array();
		$output['total'] = $total['total'];
		//print_r($output);
		return $output;
	}

	static function getTableFromDatatypeAlias($alias) {
		$tableName;

		switch($alias) {
			case self::CONTENT:
				$tableName = 'content';
				break;
			case self::NEWS_STORY:
				$tableName = 'news_story';
				break;
			case self::SHOPPING_LIST:
				$tableName = 'shopping_list';
				break;
			case self::ITEM_INQUIRY:
				$tableName = 'item_inquiry';
				break;
			case self::COUPON:
				$tableName = 'coupon';
				break;
			case self::FLYER:
				$tableName = 'flyer';
				break;
			case self::JOB_POSTING:
				$tableName = 'job_posting';
				break;
			case self::SERVICE_INQUIRY:
				$tableName = 'service_inquiry';
				break;
			case self::SUGGESTION:
				$tableName = 'suggestion';
				break;
			case self::DONATION:
				$tableName = 'donation';
				break;

		}

		return $tableName;
	}
}
?>