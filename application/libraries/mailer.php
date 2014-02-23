<?php 

require 'Mail.php';
require_once 'Mail/mime.php';

class Mailer {
	
	const GUESTLIST = "GUESTLIST";
	
	private $username = "no-reply@skip2milu.com";
	private $password = 'c4ntr3ply!';
	
	public function sendGuestList($emailAddress, $title, $files) {
		
		$mime = new Mail_mime(array('eol' => "\n"));
		$mime->setHTMLBody('<html><body><p>Here is the Guest List that you requested. Please do not respond to this message.</p></body></html>');
		
		foreach ($files as $file) 
			$mime->addAttachment($file, 'text/plain', str_replace(" ", "_", $title) . ".txt");
		
		$headers = array('From'=>'Milu Team <' .$this->username. '>', 'To'=>$emailAddress, 'Subject'=>$title);

		$mail_object =& Mail::factory('smtp', array('auth'=>TRUE, 'host'=>'ssl://smtp.gmail.com', 'port'=>465, 'username'=>$this->username, 'password'=>$this->password));
		$body = $mime->get();
		$headers = $mime->headers($headers);
		
		$result = $mail_object->send($emailAddress, $headers, $body);
		return !PEAR::isError($result);
	} 
	
	public function sendTempPassword($emailAddress, $tempPassword) {
		
		$mime = new Mail_mime(array('eol' => "\n"));
		$mime->setHTMLBody('<html><body><p>You recently let us know that you have forgotten your password. We\'ve assigned you a temporary password to use. 
				Once you\'ve signed in, create a new password. <br><br>Temporary password: ' .$tempPassword. '<br/><br/> Please do not respond to this message.</p></body></html>');
		
		$headers = array('From'=>'Milu Team <' .$this->username. '>', 'To'=>$emailAddress, 'Subject'=>"Forgot Password Request");
		
		$mail_object =& Mail::factory('smtp', array('auth'=>TRUE, 'host'=>'ssl://smtp.gmail.com', 'port'=>465, 'username'=>$this->username, 'password'=>$this->password));
		$body = $mime->get();
		$headers = $mime->headers($headers);
		
		$result = $mail_object->send($emailAddress, $headers, $body);
		return !PEAR::isError($result);
	}
}


?>