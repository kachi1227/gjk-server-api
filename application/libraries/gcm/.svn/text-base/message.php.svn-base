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
 * GCM message.
 *
 * <p>
 * Instances of this class are immutable and should be created using a
 * {@link Builder}. Examples:
 *
 * <strong>Simplest message:</strong>
 * <pre><code>
 * Message message = new Message.Builder().build();
 * </pre></code>
 *
 * <strong>Message with optional attributes:</strong>
 * <pre><code>
 * Message message = new Message.Builder()
 *    .collapseKey(collapseKey)
 *    .timeToLive(3)
 *    .delayWhileIdle(true)
 *    .build();
 * </pre></code>
 *
 * <strong>Message with optional attributes and payload data:</strong>
 * <pre><code>
 * Message message = new Message.Builder()
 *    .collapseKey(collapseKey)
 *    .timeToLive(3)
 *    .delayWhileIdle(true)
 *    .addData("key1", "value1")
 *    .addData("key2", "value2")
 *    .build();
 * </pre></code>
 */
class Message {
	private $collapseKey, $delayWhileIdle, $timeToLive, $data;
	  
  /**
  * Sets the collapseKey property.
  */
  function collapseKey($value) {
  	$this->collapseKey = $value;
  	return $this;
  }
  
  
  
  /**
   * Sets the delayWhileIdle property (default value is {@literal false}).
   */
  function delayWhileIdle($value) {
  	$this->delayWhileIdle = $value;
  	return $this;
  }
  
  /**
   * Sets the time to live, in seconds.
   */
  function timeToLive($value) {
  	$this->timeToLive = $value;
  	return $this;
  }
  
  /**
   * Adds a key/value pair to the payload data.
   */
  function addData($key, $value) {
  	$this->data[key] = value;
  	return $this;
  }
  
  function setData($data) {
  	$this->data = $data;
  	return $this;
  }

  /**
   * Gets the collapse key.
   */
  function getCollapseKey() {
    return $this->collapseKey;
  }

  /**
   * Gets the delayWhileIdle flag.
   */
  function isDelayWhileIdle() {
    return $this->delayWhileIdle;
  }

  /**
   * Gets the time to live (in seconds).
   */
  function getTimeToLive() {
    return $this->timeToLive;
  }

  /**
   * Gets the payload data, which is immutable.
   */
  function getData() {
    return $this->data;
  }

  function toArray() {
  	return get_object_vars($this);
  }
  function toString() {
    $string = "Message(";
    
    if (isset($this->collapseKey))
      $string = string . "collapseKey=" . $this->collapseKey . ", ";
    if (isset($this->timeToLive))
    	$string = string . "timeToLive=" . $this->timeToLive . ", ";
    if(isset($this->delayWhileIdle))
    	$string = string . "delayWhileIdle=" . $this->delayWhileIdle . ", ";

    if (count($this->data) > 0) {
      $string = $string . "data: {";
      foreach ($this->data as $k => $v)  
      	$string = $k . "=" . $v . ",";
      
      $string = substr($string, 0, strlen($string) -1) . "}";
    }
    
    if ($string{strlen($string) - 1} == ' ') 
		$string = substr($string, 0, strlen($string) -2);
    
    $string = $string . ")";
    return $string;
  }

}

?>