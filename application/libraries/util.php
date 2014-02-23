<?php
class Util {
	const DEFAULT_RESOURCE = "resources/0/";

	const PERSON_INFO = 1;
	const BUSINESS_INFO = 2;
	const ORGANIZATION_INFO = 3;
	const EVENT_INFO = 4;
	
	const TYPE_MAP_ENTITY = "ENTITY";
	const TYPE_MAP_EVENT = "EVENT";
	const TYPE_MAP_PHOTO = "PHOTO";

	function getModelName($val) {
		$tableName;
		switch($val) {
			case self::PERSON_INFO:
				$tableName = "Person_info";
				break;
			case self::BUSINESS_INFO:
				$tableName = "Business_info";
				break;
			case self::ORGANIZATION_INFO:
				$tableName = "Organization_info";
				break;

		}
		return $tableName;
	}
	
	function arrayToSQLArray($array) {
		$CI = &get_instance();
		$sqlArray = "";
		for($i = 0, $size = count ( $array ); $i < $size; $i ++) {
			if (strlen ( $sqlArray ) == 0)
				$sqlArray = $sqlArray . "(" . $CI->db->escape ( $array [$i] );
			else
				$sqlArray = $sqlArray . ", " . $CI->db->escape ( $array [$i] );
		}
		$sqlArray .= (strlen ( $sqlArray ) > 0 ? ")" : "('')");
		
		return $sqlArray;
	}

	function distance($lat1, $lon1, $lat2, $lon2) {
		return sqrt(pow($lat2 - $lat1, 2) + pow($lon2 - $lon1, 2));
	}
	
	/**
	 * Based on:
	 * dlon = lon2 - lon1 
	 * dlat = lat2 - lat1 
	 * a = (sin(dlat/2))^2 + cos(lat1) * cos(lat2) * (sin(dlon/2))^2 
	 * c = 2 * atan2( sqrt(a), sqrt(1-a) ) 
	d = R * c (where R is the radius of the Earth)
	 */
	function haversineDistance($lat1, $lon1, $lat2, $lon2, $unit) {

		$theta = $lon1 - $lon2;
		$dist = sin(deg2rad($lat1)) * sin(deg2rad($lat2)) +  cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos(deg2rad($theta));
		$dist = acos($dist);
		$dist = rad2deg($dist);
		$miles = $dist * 60 * 1.1515;
		$unit = strtolower($unit);

		if ($unit == "m")
		return ($miles * 1.609344) * 1000;
		if ($unit == "km")
		return ($miles * 1.609344);
		else if ($unit == "n")
		return ($miles * 0.8684);
		else
		return $miles;
	}
	
	
	function getHaversineSQLString($latValue, $lonValue, $latName, $lonName, $unit) {
		switch($unit) {
			case 'm': //meters
				$radius = 6373000;
				break;
			case 'km': //kilometers
				$radius = 6373;
				break;
			case 'n': //nautical miles
				$radius = 3442;
				break;
			default: //miles
				$radius = 3961;
		}
		
		
		
		
		return '('. $radius. ' * 2 * ASIN( SQRT( POWER( SIN((' .$latValue. ' - '.$latName. ') * pi()/180 / 2),2) + COS(' .$latValue. ' * pi()/180) * COS(' .$latName. ' *pi()/180) * POWER(SIN(('. $lonValue. ' - ' .$lonName. ') *pi()/180 / 2), 2))))';
	//	return "(" . $radius . " * acos( cos( radians(" . $latValue . ") ) * cos( radians(" . $latName. ") ) * cos( radians(". $lonName. ") - radians(" .$lonValue. ")) + sin( radians(" . $latValue.")) * sin(radians(".$latName."))))";
	}
	
	/**
	 * 
	 * Given a source (the means by which the location was retrieved), this function will return a threshold value that represents the unit of
	 * distance that the source may be off by.  
	 * @param unknown_type $source
	 */
	function getDistanceError($source, $unit) {
		//compute thredhold in meters.
		$threshold = ($source == "GPS" ? 15 : ($source == "WIFI" ?  30 : 50));
		switch($unit) {
			case 'mi':
				$threshold = $threshold/1609.34;
				break;
			case 'km':
				$threshold = $threshold/1000;
				break;
			case 'n':
				$threshold = $threshold/1852;
				break;			
		}
		
		return $threshold;
	}
	
	function convertTimestamp($str) {
		list($date, $time) = explode(' ', $str);
		list($year, $month, $day) = explode('-', $date);
		list($hour, $minute, $second) = explode(':', $time);
		$timestamp = mktime($hour, $minute, $second, $month, $day, $year);
		return $timestamp;
	}

	public function getDefaultImage($type) {
		$profile_image;
		switch($type) {
			case self::PERSON_INFO:
				$profile_image = "user_image.png";
				break;
			case self::BUSINESS_INFO:
				$profile_image = "business_image.jpg";
				break;
			case self::ORGANIZATION_INFO:
				$profile_image = "organization_image.jpg";
				break;
			case self::EVENT_INFO:
				$profile_image = "event_image_blue.jpg";
				break;
		}
		return self::DEFAULT_RESOURCE . "images/" . $profile_image;
	}
}
?>