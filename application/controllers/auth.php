<?php 
class Auth extends CI_Controller {

	const RESOURCE_FOLDER = "resources/";
	const DEFAULT_RESOURCE = "resources/0/";

	function __construct() {
		parent::__construct();
		date_default_timezone_set('GMT'); //sets proper timezone
		$this->db->query("SET time_zone='+0:00'");
		//TODO must set timezone on the server too. mayb we'll set it here?
		//load model and libraries
		$this->load->model('session');
		$this->load->library('errorCode');
	}

	public function index() {
	}

	public function createNewSession() {
		session_start();
		$sessionCreated = session_regenerate_id(false);
		
		if($sessionCreated) {
			$_SESSION['ip-address'] = $_SERVER['REMOTE_ADDR'];
			echo $this->createSuccessJSON(array("token"=>session_id()));
		} else
			echo $this->createErrorJSON(errorcode::SESSION_NOT_CREATED, $this->errorcode->getErrorMessage(errorcode::SESSION_NOT_CREATED));
	}

	public function createSuccessJSON($array) {
		$json = $array;
		$json['success'] = true;
		return json_encode($json);
	}

	public function createErrorJSON($errorNo, $errorMessage) {
		$json = array("success"=>false, "errorCode"=>$errorNo, "message"=>$errorMessage);
		return json_encode($json);
	}
}
?>