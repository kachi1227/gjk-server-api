<?php

require_once (dirname(__FILE__) . '/gcm/sender.php');
require_once (dirname(__FILE__) . '/gcm/message.php');

class PushManager {
	
	const GCM_API_KEY = "AIzaSyD49wzQo2u1320w35RuURb2fS1tecnSPds";
	
	
	
	const MSG_TYPE = "msg_type";
	const MSG_CONTENT = "msg_content";
	const MSG_SENDER = "msg_sender";
	const MSG_ROOT = "msg_root";
	const KEY_LIVESTREAM = "livestream";
	const KEY_INTERACTION = "interaction";
	const KEY_EDITED_INTERACTION = "edited_interaction";
	const KEY_LOCATION_CHAT =  "location_chat";
	const KEY_CONTACT = "contact";
	const KEY_EVENT_INVITE = "event_invite";
	const KEY_EVENT_LOCATION_CHANGE = "event_location_change";
	//const GCM_API_KEY = "AIzaSyDVAV0lwImOT7aWNb-XF2vXqZ9p9iT7pZw";
	//TODO return android notifier
	//TODO return apple notifier
	private $CI;
	
	private $messageMap = array();
	function __construct() {
		$this->CI = &get_instance();
		
		$this->messageMap[self::KEY_LIVESTREAM] = "";
	}
	
	function getMessageContent($val) {
		return $this->messageMap[$val];
	}
	
	function saveRegistrationId($userId, $registrationId, $phoneType) {
		$this->CI->load->model('Entity', 'entity');
		return $this->CI->entity->saveRegistrationId($userId, $registrationId, $phoneType);
	}
	
	function deleteRegistrationId($userId) {
		$this->CI->load->model('Entity', 'entity');
		return $this->CI->entity->deleteRegistrationId($userId);		
	}
	
	function replaceRegistrationId($oldRegistrationId, $newRegistrationId, $phoneType) {
		$this->CI->load->model('Entity', 'entity');
		return $this->CI->entity->replaceRegistrationId($oldRegistrationId, $newRegistrationId, $phoneType);
	}

	function deleteRegistrationIdWithRegId($registrationId) {
		$this->CI->load->model('Entity', 'entity');
		return $this->CI->entity->deleteRegistrationIdWithRegId($registrationId);
	}
	
	function sendMessage($androidBundle, $iosBundle) {
		$sender = new Sender(self::GCM_API_KEY);
		$message = $this->createAndroidMessage($androidBundle);
		$recipients = $androidBundle[constants::PARAM_REGISTRATION_IDS];
		// this code is no longer useful. if we try to send a message 5 times to a recipient, and they dont accept, then forget about them
 		//while(count($recipients, COUNT_RECURSIVE) > 0) {
 		//	$result = $sender->sendMulti($message, $recipients[0], 5);
 		//	//if any failed, add
 		//	//reAddAndroidRecipient(&$array, $recipient)
 		//}
		foreach($recipients as $recipientGroup) {
			
			$results = $sender->sendMulti($message, $recipientGroup, 5);
			print_r($results);
			foreach($results->getResults() as $result) {
				$canonicalId = $result->getCanonicalRegistrationId();
				$errorName = $result->getErrorCodeName();
				if(isset($canonicalId)) {
					$this->replaceRegistrationId($result->getRegistrationId(), $result->getCanonicalRegistrationId(), "ANDROID");
				} else if($errorName == constants::ERROR_INVALID_REGISTRATION || $errorName == constants::ERROR_NOT_REGISTERED) {
					$this->deleteRegistrationIdWithRegId($result->getRegistrationId());
				}
			}
		}

		// do the same thing for ios
	}
	
	function sendDummyMessage($androidBundle, $iosBundle) {
		echo "YOOO";
		$sender = new Sender(self::GCM_API_KEY);
		
		$message = $this->createAndroidMessage($androidBundle);
		
		$result = $sender->sendMulti($message, array("ABC"), 1);
		var_dump($result);
	}
	
	function createAndroidMessage($params) {
		$message = new Message();
		//var_dump($params);
		if(isset($params[Constants::PARAM_COLLAPSE_KEY]))
			$message->collapseKey($params[Constants::PARAM_COLLAPSE_KEY]);
		if(isset($params[Constants::PARAM_TIME_TO_LIVE]))
			$message->timeToLive($params[Constants::PARAM_TIME_TO_LIVE]);
		if(isset($params[Constants::PARAM_DELAY_WHILE_IDLE]))
			$message->delayWhileIdle($params[Constants::PARAM_DELAY_WHILE_IDLE]);
		if(isset($params[Constants::PARAM_DATA])) {
			$message->setData($params[Constants::PARAM_DATA]);
		}
		return $message;
	}
	
	function createIOSMessage($params) {
		
	}
	
	function partitionPhoneUsers($entities) {
		
		//for($i=0;$i<10000; $i++) {
		//	$entities[] = $entities[0];
		//}
		
		$partition = array();
		foreach($entities as $entity) {
			if(!isset($entity['phone_alias']))
				continue;
			
			$phoneType = $entity['phone_alias'];
			if(!isset($partition[$phoneType])) {
				$partition[$phoneType] = array();
				$partition[$phoneType][count($partition[$phoneType])] = array();
			}
			
			if(count($partition[$phoneType][count($partition[$phoneType]) - 1]) == 1000)
				$partition[$phoneType][count($partition[$phoneType])] = array();
			
			$partition[$phoneType][count($partition[$phoneType]) -1][] = $entity['push_registration_id'];
			
		}
		//var_dump($partition);
		return $partition;
	}
	
	private function reAddAndroidRecipient(&$array, $recipient) {
		if(count($array['ANDROID'][count($array['ANDROID']) - 1]) == 1000)
			$array['ANDROID'][count($array['ANDROID'])] = array();
			
		$array['ANDROID'][count($array['ANDROID']) -1][] = $recipient;
	}
}