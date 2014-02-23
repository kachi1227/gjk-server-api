<?php
class Maps {
	

	static function clusterize($entityArray, $zoom) {
		$CI = &get_instance();
		$CI->load->library('util');
		
		$minDistance = (10000000 >> round($zoom)) / 150000;
		
		$clusterizedEntities = array();
		while(count($entityArray)) {
			$entity = array_pop($entityArray);
			$latTotal = $entity['lat'];
			$lonTotal = $entity['lon'];
			$centroid = array('lat'=> $latTotal, 'lon'=>$lonTotal);
			$cluster = null;
			$clusterCount = 1;
			//goes through all the remaining entities in our array
			foreach ($entityArray as $key=>$otherEntity) {
				//if one of these entities is close to the center of our cluster, then add it to the cluster
				if(abs($centroid['lat'] - $otherEntity['lat']) + abs($centroid['lon'] - $otherEntity['lon']) <= $minDistance) {
// 					//if nothing is in the cluster, add the first entity
					
 					if(!isset($cluster)) {
 						$broadType = $entity['broad_type'];
 						$idMap = array(util::TYPE_MAP_ENTITY=>array(), util::TYPE_MAP_EVENT=>array(), util::TYPE_MAP_PHOTO=>array());
 						$idMap[self::getClusterTypeFromBroadId($broadType)][]= $entity['id']; //add the first entity to the proper category
 						$cluster = array('ids'=>$idMap, 'persons'=> intval($broadType == 1), 'businesses'=> intval($broadType == 2), 'organizations' => intval($broadType == 3), 'events'=>intval($broadType == 4));
 					}
 					//this is where we add the otherEntity;
 					$otherBroadType = $otherEntity['broad_type'];
 					$cluster['ids'][self::getClusterTypeFromBroadId($otherBroadType)][] = $otherEntity['id'];
 					$cluster[$type =  $otherBroadType == 1 ? 'persons' : ($otherBroadType == 2 ? 'businesses' : ($otherBroadType == 3 ? 'organizations' : 'events'))] = $cluster[$type]+ 1;
 					//echo  $type;
		
 					//compute new centroid
 					$clusterCount++;
 					$latTotal = $latTotal + $otherEntity['lat'];
 					$lonTotal = $lonTotal + $otherEntity['lon'];
 					$cluster['lat'] = $centroid['lat'] = $latTotal/$clusterCount; 
 					$cluster['lon'] = $centroid['lon'] = $lonTotal/$clusterCount;

 					//echo count($cluster['ids']);
 					
 					unset($entityArray[$key]); //remove item from array ONLY if it's in our cluster!
				}
			}
				
			if(!isset($cluster)) {
				$entity['broad_type'] = self::getClusterTypeFromBroadId($entity['broad_type']);
				$clusterizedEntities[] = array('type'=>'single', 'node'=>$entity);
			} else
				$clusterizedEntities[] = array('type'=>'cluster', 'node'=>$cluster);
		
		}
		return $clusterizedEntities;
	}
	
	static function getClusterTypeFromBroadId($id) {
		
		switch($id) {
			case 1:
			case 2:
			case 3:
				$type = util::TYPE_MAP_ENTITY;
				break;
			case 4:
				$type = util::TYPE_MAP_EVENT;
				break;
		}
		return $type;
	}
		
}
?>