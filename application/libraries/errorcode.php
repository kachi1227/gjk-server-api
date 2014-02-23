<?php 
class ErrorCode {
	
	const ERROR_DATABASE = 0;
	
	const INVALID_SESSION = 1;
	const INVALID_USER_TYPE = 2;
	const USER_ALREADY_EXISTS = 3;
	const MISSING_DATA = 4;
	const REGISTRATION_FAILED = 5;
	const PHOTO_UPLOAD = 6;
	const INVALID_CRED = 7;
	const UPDATE_FAILED = 8;
	const REL_ALREADY_PENDING = 9;
	const REL_ALREADY_CREATED = 10;
	const REL_NOT_CREATED = 11;
	const REL_NEED_NUMBER = 12;
	const MESSAGE_FAILED = 13;
	const USER_NOT_RETRIEVED = 14;
	const EVENT_NOT_CREATED = 15;
	const EVENT_NOT_RETRIEVED = 16;
	const PUSH_REG_NOT_DELETED = 17;
	const PUSH_REG_NOT_SAVED = 18;
	const INTERACTION_NOT_RECEIVED = 19;
	const FILE_NOT_UPLOADED = 20;
	const MOMENT_NOT_ADDED	= 21;
	const MOMENT_NOT_REMOVED = 22;
	const MAIL_NOT_SENT = 23;
	const UPDATE_EVENT_FAILED = 24;
	const BUCKET_LIST_NOT_CREATED = 25;
	const UPDATE_BUCKET_LIST_FAILED = 26;
	const SESSION_NOT_CREATED = 27;
	const CHECKIN_NOT_UPDATED = 28;
	const BAD_USER_PERMISSION = 29;
	const INVALID_USER = 30;
	const UPDATE_MESSAGE_FAILED = "Message could not be updated";
	
	private $errorArray = array();
	function __construct() {
		$this->errorArray[self::INVALID_SESSION] = "Session is invalid";
		$this->errorArray[self::INVALID_USER_TYPE] = "The user type provided is not valid";
		$this->errorArray[self::USER_ALREADY_EXISTS] = "The email provided is already in use";
		$this->errorArray[self::MISSING_DATA] = "Not all required fields have been provided";
		$this->errorArray[self::REGISTRATION_FAILED] = "Could not add user. Try again later";
		$this->errorArray[self::PHOTO_UPLOAD] = "Photo could not be uploaded";
		$this->errorArray[self::INVALID_CRED] = "Invalid username/password combination";
		$this->errorArray[self::UPDATE_FAILED] = "Could not update user information";
		$this->errorArray[self::REL_ALREADY_PENDING] = "Contact request has already been sent";
		$this->errorArray[self::REL_ALREADY_CREATED] = "Contact already exists";
		$this->errorArray[self::REL_NOT_CREATED] = "Could not send request to contact";
		$this->errorArray[self::REL_NEED_NUMBER] = "Milu Number needed to request contact";
		$this->errorArray[self::MESSAGE_FAILED] = "Message could not be sent";
		$this->errorArray[self::USER_NOT_RETRIEVED] = "User information could not be retrieved";
		$this->errorArray[self::EVENT_NOT_CREATED] = "Event could not be created. Insert error.";
		$this->errorArray[self::EVENT_NOT_RETRIEVED] = "Event information could not be retrieved";
		$this->errorArray[self::PUSH_REG_NOT_DELETED] = "Could not delete existing push registration id";
		$this->errorArray[self::PUSH_REG_NOT_SAVED] = "Could not save push registration id";
		$this->errorArray[self::INTERACTION_NOT_RECEIVED] = "Could not retreive new interactions";
		$this->errorArray[self::FILE_NOT_UPLOADED] = "Could not upload file";
		$this->errorArray[self::MOMENT_NOT_ADDED] = "Moment could not be addded";
		$this->errorArray[self::MOMENT_NOT_REMOVED] = "Moment could not be removed";
		$this->errorArray[self::MAIL_NOT_SENT] = "Email could not be sent";
		$this->errorArray[self::UPDATE_EVENT_FAILED] = "Could not update event information";
		$this->errorArray[self::BUCKET_LIST_NOT_CREATED] = "Could not create bucket list item";
		$this->errorArray[self::UPDATE_BUCKET_LIST_FAILED] = "Could not update bucket list information";
		$this->errorArray[self::SESSION_NOT_CREATED] = "Could not create new session";
		$this->errorArray[self::CHECKIN_NOT_UPDATED] = "Count not update checkin status";
		$this->errorArray[self::BAD_USER_PERMISSION] = "You do not have permission to view this user's profile";
		$this->errorArray[self::INVALID_USER] = "No user exists with the provided email or milu number";
	}
	
	function getErrorMessage($val) {
		return $this->errorArray[$val];
	}
	
	function isError($code) {
		if(is_numeric($code))
			return isset($this->errorArray[$code]);
		else
			return false;
	}
	
	public static function logError($errorType = -1, $errTable) {
			$CI =&get_instance();
			
			switch($errorType) {
			case self::ERROR_DATABASE:
				log_message("error", "Problem Inserting to ".$errTable.": ".$CI->db->_error_message()." (".$CI->db->_error_number().")");
				break;
		}			 
	}
			
}
?>