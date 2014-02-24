<?php 
class ErrorCode {
	
	const ERROR_DATABASE = 0;
	
	const INVALID_SESSION = 1;
	const USER_ALREADY_EXISTS = 2;
	const MISSING_DATA = 3;
	const REGISTRATION_FAILED = 4;
	const PHOTO_UPLOAD = 5;
	const INVALID_CRED = 6;
	const UPDATE_FAILED = 7;
	const USER_NOT_RETRIEVED = 8;
	const GROUP_NOT_CREATED = 9;
	const GROUP_NOT_DELETED = 10;
	const GROUP_MEMBER_NOT_ADDED = 11;
	const GROUP_MEMBER_NOT_DELETED = 12;
	const MESSAGE_FAILED = 13;
	const PUSH_REG_NOT_DELETED = 17;
	const PUSH_REG_NOT_SAVED = 18;
	const FILE_NOT_UPLOADED = 20;
	const MAIL_NOT_SENT = 23;
	const SESSION_NOT_CREATED = 27;
	const INVALID_USER = 30;
	
	private $errorArray = array();
	function __construct() {
		$this->errorArray[self::INVALID_SESSION] = "Session is invalid";
		$this->errorArray[self::USER_ALREADY_EXISTS] = "The email provided is already in use";
		$this->errorArray[self::MISSING_DATA] = "Not all required fields have been provided";
		$this->errorArray[self::REGISTRATION_FAILED] = "Could not add user. Try again later";
		$this->errorArray[self::PHOTO_UPLOAD] = "Photo could not be uploaded";
		$this->errorArray[self::INVALID_CRED] = "Invalid username/password combination";
		$this->errorArray[self::UPDATE_FAILED] = "Could not update user information";
		$this->errorArray[self::USER_NOT_RETRIEVED] = "User information could not be retrieved";
		$this->errorArray[self::GROUP_NOT_CREATED] = "Group could not be created.";
		$this->errorArray[self::GROUP_NOT_DELETED] = "Group could not be removed.";
		$this->errorArray[self::GROUP_MEMBER_NOT_ADDED] = "Could not add new member(s) to group.";
		$this->errorArray[self::GROUP_MEMBER_NOT_DELETED] = "Member(s) could not be deleted from group";
		$this->errorArray[self::MESSAGE_FAILED] = "Message could not be sent";
		$this->errorArray[self::PUSH_REG_NOT_DELETED] = "Could not delete existing push registration id";
		$this->errorArray[self::PUSH_REG_NOT_SAVED] = "Could not save push registration id";
		$this->errorArray[self::FILE_NOT_UPLOADED] = "Could not upload file";
		$this->errorArray[self::MAIL_NOT_SENT] = "Email could not be sent";
		$this->errorArray[self::SESSION_NOT_CREATED] = "Could not create new session";
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