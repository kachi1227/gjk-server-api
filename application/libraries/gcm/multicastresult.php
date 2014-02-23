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

/**
 * Result of a GCM multicast message request .
 */
final class MulticastResult {

	private $success, $failure, $canonicalIds, $multicastId, $retryMulticastIds, $results;


	function __construct($success, $failure, $canonicalIds, $multicastId, $retryMulticastIds = null) {
//		echo "1";
		$this->success = $success;
//		echo "2";
		$this->failure = $failure;
//		echo "3";
		$this->canonicalIds = $canonicalIds;
//		echo "4";
		$this->multicastId = $multicastId;
//		echo "5";
		$this->retryMulticastIds = $retryMulticastIds;
//		echo "6";
		$this->results = array();
//		echo "7";
	}

	/**
	 * Gets the multicast id.
	 */
	function getMulticastId() {
		return $this->multicastId;
	}

	/**
	 * Gets the number of successful messages.
	 */
	function getSuccess() {
		return $this->success;
	}

	/**
	 * Gets the total number of messages sent, regardless of the status.
	 */
	function getTotal() {
		return $this->success + $this->failure;
	}

	/**
	 * Gets the number of failed messages.
	 */
	function getFailure() {
		return $this->failure;
	}

	/**
	 * Gets the number of successful messages that also returned a canonical
	 * registration id.
	 */
	function getCanonicalIds() {
		return $this->canonicalIds;
	}
	
	function addResult($result) {
		$this->results[] = $result;
		return $this;
	}

	/**
	 * Gets the results of each individual message, which is immutable.
	 */
	function getResults() {
		return $this->results;
	}
	
	function retryMulticastIds($retryMulticastIds) {
	
		$this->retryMulticastIds = $retryMulticastIds;
		return $this;
	}

	/**
	 * Gets additional ids if more than one multicast message was sent.
	 */
	function getRetryMulticastIds() {
		return $this->retryMulticastIds;
	}

	
	function toString() {
		$string = "MulticastResult( multicast_id=" .$this->multicastId . ", total="
		.$this->getTotal() . ", success=" . $this->success . ", failure="
		.$this->failure . ", canonical_ids=" .$this->canonicalIds;
		if (count($this->results) > 0) 
			$string = $string . ", results: " + print_var(results);
		
		return $string;
	}

}?>