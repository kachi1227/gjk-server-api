<?php
/*
 * Copyright 2012 Google Inc.
*
* Licensed under the Apache License, Version 2.0 (the "License"); you may not
* use this file except in compliance with the License. You may obtain a copy of
* the License at
*
* http://www.apache.org/licenses/LICENSE-2.0
*
* Unless required by applicable law or agreed to in writing, software
* distributed under the License is distributed on an "AS IS" BASIS, WITHOUT
* WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
* See the License for the specific language governing permissions and
* limitations under the License.
*/

require_once dirname(__FILE__) . '/constants.php';
require_once dirname(__FILE__) . '/result.php';
require_once dirname(__FILE__) . '/multicastresult.php';


/**
 * Helper class to send messages to the GCM service using an API Key.
 */
class Sender {

	const UTF8 = "UTF-8";

	/**
	 * Initial delay before first retry, without jitter (in seconds).
	 */
	const BACKOFF_INITIAL_DELAY = 1000;
	/**
	 * Maximum delay before a retry (in seconds).
	 */
	const MAX_BACKOFF_DELAY = 1024000;


	private $key, $cert;

	/**
	 * Default constructor.
	 *
	 * @param key API key obtained through the Google API Console.
	 */
	function __construct($key) {
		$this->key = self::nonNull($key);
		$CI =& get_instance();
		$this->cert = $CI->config->item('cert_url');
	}
	
	/**
	 * Sends a message to many devices, retrying in case of unavailability.
	 *
	 * <p>
	 * <strong>Note: </strong> this method uses exponential back-off to retry in
	 * case of service unavailability and hence could block the calling thread
	 * for many seconds.
	 *
	 * @param message message to be sent.
	 * @param regIds registration id of the devices that will receive
	 *        the message.
	 * @param retries number of retries in case of service unavailability errors.
	 *
	 * @return combined result of all requests made.
	 *
	 * @throws IllegalArgumentException if registrationIds is {@literal null} or
	 *         empty.
	 * @throws InvalidRequestException if GCM didn't returned a 200 or 503 status.
	 * @throws IOException if message could not be sent.
	 */
	function sendMulti($message, $regIds, $retries) {
		$attempt = 0;
		$multicastResult = null;
		$backoff = self::BACKOFF_INITIAL_DELAY;
		// Map of results by registration id, it will be updated after each attempt
		// to send the messages
		$results = array();
		$unsentRegIds = $regIds; //these are the ids that we originally sent messages to
		$tryAgain = false;
		$multicastIds = array();
		do {
			$attempt++;
			$multicastResult = $this->sendMultiNoRetry($message, $unsentRegIds);
			
			$multicastId = $multicastResult->getMulticastId();
			$multicastIds[] = $multicastId;
			$unsentRegIds = $this->updateStatus($unsentRegIds, $results, $multicastResult);
			$tryAgain = count($unsentRegIds) > 0 && $attempt <= $retries; //if we still have ids that need to be sent message to
			echo $tryAgain;
			if ($tryAgain) {
				$sleepTime = $backoff / 2 + mt_rand(0, $backoff - 1);
				sleep($sleepTime/1000);
				if (2 * $backoff < self::MAX_BACKOFF_DELAY) {
					$backoff *= 2;
				}
			}
		} while ($tryAgain);
		// calculate summary
		
		$success = $failure = $canonicalIds = 0;
		foreach ($results as $result) {
			$messageId = $result->getMessageId();
			$canonicalId = $result->getCanonicalRegistrationId();
			if (isset($messageId)) {
				$success++; //if we just got a message id, then it was sent
				if (isset($canonicalId)) {
					$canonicalIds++; //if we also got a canonical id, then we need to update registration
				}
			} else {
				$failure++; //else some sort of failure
			}
		}
		
		// build a new object with the overall result
		$multicastId = $multicastIds[0]; //this was the first multicast message we sent.
		
		unset($multicastIds[0]); //remove it from our list of multi
		if(count($multicastIds) > 0)
			$multicastIds = array_values($multicastId); //the rest of these will be the ids of multicast messages we had to retry to send
		
		$multicastResult = new MulticastResult($success, $failure, $canonicalIds, $multicastId, count($multicastIds) > 0 ? $multicastIds : null);
		
		// add results, in the same order as the input
		//$result[$regId] will never be null. some values may contain an error if it doesnt send, but itll never be null.
		//var_dump($results);
		foreach ($regIds as $regId) {
			$result = $results[$regId];
			$errorName = $result->getErrorCodeName();
			$canonicalId = $result->getCanonicalRegistrationId();
			if(isset($errorName) || isset($canonicalId))
				$multicastResult->addResult($results[$regId]);
		}
		return $multicastResult;
	}

	/**
	 * Updates the status of the messages sent to devices and the list of devices
	 * that should be retried.
	 *
	 * @param unsentRegIds list of devices that are still pending an update.
	 * @param allResults map of status that will be updated.
	 * @param multicastResult result of the last multicast sent.
	 *
	 * @return updated version of devices that should be retried.
	 */
	private function updateStatus(&$unsentRegIds, &$allResults, &$multicastResult) {
		$results = $multicastResult->getResults(); //results that we got from multicast
		if (count($results) != count($unsentRegIds)) {
			// should never happen, unless there is a flaw in the algorithm
			//they should always be equal. For every id that we sent a message to, we should get a result back
			throw new RuntimeException("Internal error: sizes do not match. " +
	          "currentResults: " + print_r($results) + "; unsentRegIds: " + print_r($unsentRegIds));
		}		
		$newUnsentRegIds = array();
		for ($i = 0, $size = count($unsentRegIds); $i < $size; $i++) {
			$regId = $unsentRegIds[$i];
			$result = $results[$i];
			$allResults[$regId] = $result; //maps the registration id to a result.
			$error = $result->getErrorCodeName();
			if (isset($error) && $error == Constants.ERROR_UNAVAILABLE) {
				$newUnsentRegIds[] = $regId; //if there was an error, then send add id to list of ids that need to be resent
			}
		}
		return $newUnsentRegIds;
	}

	/**
	 * Sends a message without retrying in case of service unavailability. See
	 * {@link #send(Message, List, int)} for more info.
	 *
	 * @return {@literal true} if the message was sent successfully,
	 *         {@literal false} if it failed but could be retried.
	 *
	 * @throws IllegalArgumentException if registrationIds is {@literal null} or
	 *         empty.
	 * @throws InvalidRequestException if GCM didn't returned a 200 status.
	 * @throws IOException if message could not be sent or received.
	 */
	function sendMultiNoRetry($message, $registrationIds) {
		if (count($this->nonNull($registrationIds)) <= 0) {
			throw new InvalidArgumentException("registrationIds cannot be empty");
		}
		$jsonRequest = $message->toArray();
		$jsonRequest[Constants::JSON_REGISTRATION_IDS] = $registrationIds;		
		$ch = $this->post(Constants::GCM_SEND_ENDPOINT, "application/json", json_encode($jsonRequest));
		$output = curl_exec($ch);
 		//echo $output;
 		//echo curl_error($ch);
 		$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
 		if ($status != 200) {
 			throw new InvalidRequestException($status, curl_error($ch));
 		}
 		$jsonResponse = json_decode($output, true);
 		//echo $jsonResponse;
 		//{"multicast_id":6782339717028231855,"success":0,"failure":1,"canonical_ids":0,"results":[{"error":"InvalidRegistration"}]}
 		
 		$success = $jsonResponse[Constants::JSON_SUCCESS];
 		$failure = $jsonResponse[Constants::JSON_FAILURE];
 		$canonicalIds = $jsonResponse[Constants::JSON_CANONICAL_IDS];
 		$multicastId = $jsonResponse[Constants::JSON_MULTICAST_ID];
 		$multiResult = new MulticastResult($success, $failure, $canonicalIds, $multicastId);
		
 		$results = $jsonResponse[Constants::JSON_RESULTS];
 		if (isset($results)) {
 			for($i=0, $size=count($results); $i < $size; $i++) {
 				$jsonResult = $results[$i];
 				$result = new Result($registrationIds[$i], $jsonResult[Constants::JSON_MESSAGE_ID], $jsonResult[Constants::TOKEN_CANONICAL_REG_ID], $jsonResult[Constants::JSON_ERROR]);
 				$multiResult->addResult($result);
 			}
 		}
 		return $multiResult;
	} 

	private function newIoException($responseBody, $e) {

		return new Exception("Error parsing JSON response (" + $responseBody + ")" . ":" . $e->getMessage());
	}

	/**
	 * Sets a JSON field, but only if the value is not {@literal null}.
	 */
	function setJsonField(&$json, $field, $value) {
		if (isset($value)) {
			$json[$field] = $value;
		}
	}


	private function split($line) {
		$split = explode("=", $line, 2);
		if (!$split || count($split) != 2) {
			throw new IOException("Received invalid response line from GCM: " + line);
		}
		return split;
	}

	/**
	 * Make an HTTP post to a given URL.
	 *
	 * @return HTTP response.
	 */
	protected function postWithNoContentType($url, $body) {
		return $this->post(url, "application/x-www-form-urlencoded;charset=UTF-8", body);
	}

	protected function post($url, $contentType, $body) {
		if (!isset($url) || !isset($body)) {
			throw new InvalidArgumentException("arguments cannot be null");
		}

		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: ' . $contentType, 'Authorization: key=' . $this->key));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_FRESH_CONNECT, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
		curl_setopt ($ch, CURLOPT_SSL_VERIFYPEER, TRUE);
		
		curl_setopt ($ch, CURLOPT_CAINFO, $this->cert);
		return $ch;
	}

	/**
	 * Creates a {@link StringBuilder} to be used as the body of an HTTP POST.
	 *
	 * @param name initial parameter for the POST.
	 * @param value initial value for that parameter.
	 * @return StringBuilder to be used an HTTP POST body.
	 */
	protected static function newBody($name, $value) {
		return nonNull($name) . '=' . nonNull($value);
	}

	/**
	 * Adds a new parameter to the HTTP POST body.
	 *
	 * @param body HTTP POST body
	 * @param name parameter's name
	 * @param value parameter's value
	 */
	protected static function addParameter($name, $value) {
		return '&' . nonNull($name) . '=' . nonNull($value);
	}

	static function nonNull($argument) {
		if (!isset($argument)) {
			throw new InvalidArgumentException("argument cannot be null");
		}
		return $argument;
	}
	
	

}

class CustomParserException extends RuntimeException {
	function __construct($message) {
		parent::__construct($message);
	}
}

?>