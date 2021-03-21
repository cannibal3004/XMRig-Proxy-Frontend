<?php

header('Content-Type: application/json');
error_reporting(0);

//debug stuff
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// remove before publishing

if(!isset($_POST["cc"])) die;
require('config/config.php');
require('db_login.php');

$db_login = new DB_Login($db_server, $db_database, $db_username, $db_password);

// $use_db_login = true;
// define(USE_DB_LOGIN)
//-- Fix for IIS Server
if(!function_exists('apache_request_headers') ) {
	function apache_request_headers() {
	  $arh = array();
	  $rx_http = '/\AHTTP_/';
	  foreach($_SERVER as $key => $val) {
		if( preg_match($rx_http, $key) ) {
		  $arh_key = preg_replace($rx_http, '', $key);
		  $rx_matches = array();
		  $rx_matches = explode('_', $arh_key);
		  if( count($rx_matches) > 0 and strlen($arh_key) > 2 ) {
			foreach($rx_matches as $ak_key => $ak_val) $rx_matches[$ak_key] = ucfirst($ak_val);
			$arh_key = implode('-', $rx_matches);
		  }
		  $arh[$arh_key] = $val;
		}
	  }
	  return( $arh );
	}
}

$headers = apache_request_headers();
//var_dump($headers);
switch($_POST['cc'])
{
	case 'check_password':
		echo json_encode(verify_password($headers));
	break;

	case 'is_admin':
		if(!verify_password($headers))
			die();
		echo json_encode(user_is_admin($headers));
	break;

	case 'toggle_admin':
		if (!verify_password($headers)){
			die();
		}
		if (user_is_admin($headers)){
			//var_dump($headers);
			echo json_encode(toggle_admin());
		}
	break;

	case 'toggle_active':
		if (!verify_password($headers)){
			die();
		}
		if (user_is_admin($headers)){
			//var_dump($headers);
			echo json_encode(toggle_active());
		}
	break;

	case 'change_password':
		if(!verify_password($headers))
			die();
		echo json_encode(change_password());
		break;

	case 'get_users':
		if(!verify_password($headers))
			die();
		echo json_encode(get_users());
	break;

	case "add_user":
		if (!verify_password($headers))
			die();
		if (user_is_admin($headers)){
			echo json_encode(add_user());
		}
		//echo json_encode(DB_Login::GetUsers());
	break;

	case "delete_users":
		if (!verify_password($headers))
			die();
		if (user_is_admin($headers)){
			echo json_encode(delete_users());
		}
	break;

    case "read_db":
		if (!verify_password($headers))
			die();
		$proxy_id = $_POST["proxy"];
		$db = new SQLite3('proxy.db');
		$db->busyTimeout(5000);
		$db->exec('PRAGMA journal_mode = wal;');		
		$days = intval($_POST["days"]);
		$date = time()-($days * 24 * 60 * 60);
		$date = date('Y-m-d H:i:s', $date);
		$proxy_adress = $proxy_list[$proxy_id]["ip"].":".$proxy_list[$proxy_id]["port"];	
		$results = $db->query("SELECT * FROM 'proxy_$proxy_adress' WHERE date >= '$date'");
		$arr = array(); $i=0;
		while($row = $results->fetchArray()){		
			$arr[$i]['date'] = $row['date'];
			$arr[$i]['value'] = $row['value'];
			$i++;
		}
		$db->close();
		
		echo json_encode($arr);
    break;
	
	case 'write_db':
		// if (!verify_password($headers))
		// 	die();
		$db = new SQLite3('proxy.db');
		$db->busyTimeout(5000); 
		$db->exec('PRAGMA journal_mode = wal;');
		$jobs = get_jobs();
		//-- First compare proxy present in jobs.json with proxy from this php adress list
		$address_list = array();
		foreach($proxy_list as $k=>$v) $address_list[] = $v["ip"].":".$v["port"];
		foreach($jobs as $k=>$v){
			$key = array_search($v["proxy"], $address_list);
			if($key === false){ // if proxy exist on jobs.json and not php adress list , delete datas from jobs.json
				unset($jobs[$k]);
				$jobs = array_values($jobs);
				$jsondata = json_encode($jobs, JSON_PRETTY_PRINT);
				file_put_contents("jobs.json", $jsondata);
			}
		}
		
		foreach($proxy_list as $proxy){
			$proxy_address = $proxy["ip"].":".$proxy["port"];
			$db->query("CREATE TABLE IF NOT EXISTS 'proxy_$proxy_address' ('id_auto' INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, 'date' DATETIME, 'value' NUMERIC);");			
			$summary_array = json_decode(get_curl_data($proxy["ip"], $proxy["port"], "summary", $proxy["token"]),true);
			if(!$summary_array) continue; // if proxy not available !
			$workers_array = json_decode(get_curl_data($proxy["ip"], $proxy["port"], "workers", $proxy["token"]),true);
			if(!empty($workers_array["workers"])) write_workers_stats($workers_array["workers"], $proxy["ip"]."_".$proxy["port"]);
			$val = round($summary_array['hashrate']['total'][2]*1000,2);
			$date = date('Y-m-d H:i'); 
			$db->query("INSERT INTO 'proxy_$proxy_address' ('date' ,'value') VALUES('$date', '$val')");
			$date_max = date( 'Y-m-d H:i', strtotime("-$max_history_days day", strtotime($date)) );
			$db->query("DELETE FROM 'proxy_$proxy_address' WHERE date(date)<date('$date_max')");
			
			//-- Check Jobs
			if($jobs){
				$proxy_config_data = json_decode(get_curl_data($proxy["ip"], $proxy["port"], "config", $proxy["token"]), true);
				if(!$proxy_config_data || sizeof($proxy_config_data["pools"]) <= 1) continue;
				$key = array_search($proxy_address, array_column($jobs, 'proxy'));			
				if($key !== false){	
					//-- Loop Time
					if($jobs[$key]["loop_time"] > 0){
						$pool_time = $jobs[$key]["pool_time"];
						if( (time()-$pool_time)/3600 >= $jobs[$key]["loop_time"]){
							swap_pool($proxy_address, $summary_array, $proxy_config_data, $proxy["token"], $jobs[$key]);						
						}
					}else if(!empty($jobs[$key]["pools"])){
					//-- Loop Percent
						$pool_lists = $jobs[$key]["pools"];
						//-- first verify if pools inside config.json is the same in jobs.json else delete datas from job.json
						foreach($pool_lists as $kk=>$vv){
							$Fkey = array_search($vv["url"], array_column($proxy_config_data["pools"], 'url'));
							if($Fkey === false){
								unset($jobs[$key]["pools"][$kk]);
								$pool_lists = $jobs[$key]["pools"];
								$jsondata = json_encode($jobs, JSON_PRETTY_PRINT);
								file_put_contents("jobs.json", $jsondata);
							}
						}
						$time_on = time() - $jobs[$key]["pool_time"];
						foreach($pool_lists as $k=>$v){
							$pool = $v["url"];
							$minutes_perc = (1440/100)*$v["percent"];
							if($proxy_config_data["pools"][0]["url"] == $pool && $time_on/60 >= $minutes_perc){
								swap_pool($proxy_address, $summary_array, $proxy_config_data, $proxy["token"], $jobs[$key]);
							}
						}					
					}	
				}
			}
		}
		$db->close();
    break;

	case ('proxy_data'):
		if (!verify_password($headers))
			die();
		//endpoint --> config, workers, summary
		$proxy_id = $_POST["proxy"];
		$endpoint = $_POST["endpoint"];
		$data = get_curl_data($proxy_list[$proxy_id]["ip"], $proxy_list[$proxy_id]["port"], $endpoint, $proxy_list[$proxy_id]["token"]);
		if($data){
			$api_data = json_decode($data, true);
		}else{
			$api_data["error"] = 1;
		}
		if($endpoint == "summary"){
			$config_data = get_proxy_configs($proxy_list[$proxy_id]["ip"], $proxy_list[$proxy_id]["port"], $proxy_list[$proxy_id]["token"]);
			if($config_data)$api_data["config_data"] = $config_data;
			$proxy_infos = array(); $i=0;
			foreach($GLOBALS["proxy_list"] as $k => $v){
				$proxy_infos[$i]["id"] = $k;
				$proxy_infos[$i]["label"] = $v["label"];
				$i++;
			}
			$api_data["config_data"]["proxy_infos"] = $proxy_infos;
		}else if($endpoint == "workers"){
			$api_data["workers_stats"] = get_workers_stats($proxy_list[$proxy_id]["ip"]."_".$proxy_list[$proxy_id]["port"]);
		}
		echo json_encode($api_data);
    break;
	
	case 'write_job':
		if (!verify_password($headers))
			die();
		$proxy_id = $_POST["proxy"];
		$job_datas = $_POST["job_datas"];
		$proxy_pool = $_POST["proxy_pool"];
		$job_datas["proxy"] = $proxy_list[$proxy_id]["ip"].":".$proxy_list[$proxy_id]["port"];	
		$job_infos = array("proxy_hashes" => $job_datas["pool_hashes"], "proxy_shares" => $job_datas["pool_shares"], "proxy_pool" => $proxy_pool["url"]);
		echo json_encode(write_job($job_datas, $job_infos));
			
	break;
	
	case 'get_job':
		if (!verify_password($headers))
			die();
		$proxy_id = $_POST["proxy"];
		$jobs = get_jobs($proxy_id);
		if(!$jobs){
			echo json_encode(false);
		}else{
			$ex = false;
			foreach($jobs as $k => $v){
				if($v["proxy"] == $proxy_list[$proxy_id]["ip"].":".$proxy_list[$proxy_id]["port"]) $ex = $k;
			}
			if($ex !== false) echo json_encode($jobs[$ex]); else echo json_encode(false);
		}
	break;
	
	case 'get_history':
		if (!verify_password($headers))
			die();
		$proxy_id = $_POST["proxy"];
		$datas = get_history($proxy_id);
		if($datas) echo json_encode($datas); else echo json_encode('false');
	break;
	
	case 'delete_file':
		if (!verify_password($headers))
			die();
		$file = $_POST["file"];
		$del = false;
		if(file_exists($file)) $del = unlink($file);
		echo json_encode($del);
	break;
	
	case 'put_data':
		if (!verify_password($headers))
			die();
	    $proxy_id = $_POST["proxy"];
		$mode = $_POST["mode"];
		$url = "http://".$proxy_list[$proxy_id]["ip"].":".$proxy_list[$proxy_id]["port"]."/1/config";
		$proxy_config_data = $_POST["proxy_config_data"];
		if($mode == "switch"){
			$summary_array = $_POST["summary_array"];
			$new_pool = $_POST["new_pool"];
			$pool_index =  array_search($new_pool, array_column($proxy_config_data["pools"], 'url'));
			$proxy_address = $proxy_list[$proxy_id]["ip"].":".$proxy_list[$proxy_id]["port"];		
			
			$jobs = get_jobs($proxy_id);
			$key = array_search($proxy_list[$proxy_id]["ip"].":".$proxy_list[$proxy_id]["port"], array_column($jobs, 'proxy'));
			
			if($key === false){
				$job = array("loop_time" => "", "pools" => array(), "proxy" => $proxy_address);
			}else{			
				$job = $jobs[$key];
			}			
			$response = switch_to_pool($proxy_address, $summary_array, $proxy_config_data, $proxy_list[$proxy_id]["token"], $job, $pool_index);
		}else{
			$response = write_config($url, $proxy_config_data, $proxy_list[$proxy_id]["token"]);
		}
		echo json_encode(true);
	break;
	
}

/******************************************************************/
/* 						FUNCTIONS								  */
/******************************************************************/
function get_workers_stats($proxy){
	$myFile = "workers_$proxy.json";
	if (file_exists($myFile)) $data = file_get_contents($myFile); else return false;
	if(!isJson($data)) return false;
	$data = json_decode($data ,true);
	return $data;	
}

function write_workers_stats($stats_array, $proxy){
	$myFile = "workers_$proxy.json";
	if(!file_exists($myFile)){
		$create = json_encode(array(), JSON_PRETTY_PRINT);
		file_put_contents($myFile, $create);
	}
	$jsondata = file_get_contents($myFile);

	if(!isJson($jsondata)){
		$create = json_encode(array(), JSON_PRETTY_PRINT);
		file_put_contents($myFile, $create);
		$jsondata = json_encode(array());
	}
	if( time()-filemtime($myFile) < 3600 && empty($create) ) return false; //write stats only each hour
	
	$workers_stats = json_decode($jsondata, true);
	foreach($stats_array as $k => $v){
		$id = trim($v[0]);
		$hashes = $v[12];
		$key = array_search($id, array_column($workers_stats, 'id'));
		if($key !== false){
			$temp_arr = explode(',', $workers_stats[$key]["datas"]);
			array_push($temp_arr, $hashes);			
			if(sizeof($temp_arr) >= 24) { unset($temp_arr[0]); $temp_arr = array_values($temp_arr); }		
			$workers_stats[$key]["datas"] = implode(",", $temp_arr);
			if ( array_sum($temp_arr) == 0 ){ unset($workers_stats[$key]); $workers_stats = array_values($workers_stats); } // delete old workers
		}else{
			$new_stats = array("id"=>$id, "datas"=>$hashes);
			array_push($workers_stats, $new_stats);
		}
		
	}
	$jsondata = json_encode($workers_stats, JSON_PRETTY_PRINT);
	file_put_contents($myFile, $jsondata);
}

function get_proxy_configs($proxy_ip, $proxy_port, $token){
	$data = get_curl_data($proxy_ip, $proxy_port, "config", $token);
	if($data) return json_decode($data, true);
}
function switch_to_pool($proxy_address, $summary_array, $proxy_config_data, $token, $job, $new_pool){
	$proxy_url = "http://".$proxy_address."/1/config";
	$prev_pool = $proxy_config_data["pools"][0];
	array_move($proxy_config_data["pools"], 0, $new_pool);
	$response = write_config($proxy_url, $proxy_config_data, $token);	
	$job_infos = array("proxy_hashes" => $summary_array["results"]["hashes_total"], "proxy_shares" => $summary_array["results"]["accepted"], "proxy_pool" => $prev_pool["url"]);
	write_job($job, $job_infos);	
}

function swap_pool($proxy_address, $summary_array, $proxy_config_data, $token, $job){
	$proxy_url = "http://".$proxy_address."/1/config";
	$prev_pool = $proxy_config_data["pools"][0];
	unset($proxy_config_data["pools"][0]);
	array_push($proxy_config_data["pools"], $prev_pool);
	$proxy_config_data["pools"] = array_values($proxy_config_data["pools"]);
	$response = write_config($proxy_url, $proxy_config_data, $token);	
	$job_infos = array("proxy_hashes" => $summary_array["results"]["hashes_total"], "proxy_shares" => $summary_array["results"]["accepted"], "proxy_pool" => $prev_pool["url"]);
	write_job($job, $job_infos);
	return json_encode(true);
}


function history_write($datas){
	$myFile = "history.json";
	if(!file_exists($myFile)){
		$create = json_encode(array(), JSON_PRETTY_PRINT);
		file_put_contents($myFile, $create);
	}
	$jsondata = file_get_contents($myFile);
	if(!isJson($jsondata)){
		return false;
	}
	$arr_data = json_decode($jsondata, true);
	$key = array_search($datas["proxy"], array_column($arr_data, 'proxy'));
	
	if($key === false){
		array_push($arr_data, $datas);		   
	}else{
		if( sizeof($arr_data[$key]["history"]) >= $GLOBALS["max_history"] ) unset($arr_data[$key]["history"][0]);
		$arr_data[$key]["history"] = array_values($arr_data[$key]["history"]);
		array_push( $arr_data[$key]["history"], $datas["history"][0]);
	}
	$jsondata = json_encode($arr_data, JSON_PRETTY_PRINT);
	file_put_contents($myFile, $jsondata);
}


function write_job($new_job, $infos){
	$myFile = "jobs.json";
	$new_job["pool_time"] = time();
	$new_job["pool_hashes"] = $infos["proxy_hashes"];
	$new_job["pool_shares"] = $infos["proxy_shares"];
	$hist_datas = array("proxy"=>$new_job["proxy"]);
	if(!file_exists($myFile)){
		$create = json_encode(array(), JSON_PRETTY_PRINT);
		file_put_contents($myFile, $create);
	}
	$jsondata = file_get_contents($myFile);

	if(!isJson($jsondata)){
		$create = json_encode(array(), JSON_PRETTY_PRINT);
		file_put_contents($myFile, $create);
		$jsondata = json_encode(array());
	}
	$jobs_data = json_decode($jsondata, true);
	
		
	$key = array_search($new_job["proxy"], array_column($jobs_data, 'proxy'));
	
	if($key === false){
		array_push($jobs_data, $new_job);		
	}else{
		$time_on = time()-$jobs_data[$key]["pool_time"];
		$hashes_on = $infos["proxy_hashes"]-$jobs_data[$key]["pool_hashes"];
		$shares = $infos["proxy_shares"]-$jobs_data[$key]["pool_shares"];		
		$hist_datas["history"][0] = array("pool"=>$infos["proxy_pool"], "hashes"=>$hashes_on, "start"=>$jobs_data[$key]["pool_time"], "time_on"=>$time_on, "shares"=>$shares) ;
		history_write($hist_datas);			
		$jobs_data[$key] = $new_job;
	}
	$jsondata = json_encode($jobs_data, JSON_PRETTY_PRINT);
	file_put_contents($myFile, $jsondata);
	return true;
}

//-- Write to XMRIG-Proxy config.json
function write_config($url, $proxy_config_data, $token){ 	
	foreach($proxy_config_data as $k=>$v){
		if(is_array($v)){
			foreach($v as $kk=>$vv){
				if($vv == "false") $proxy_config_data[$k][$kk] = false; if($vv == "true")$proxy_config_data[$k][$kk] = true;
			}
		}else{
			if($v == "false") $proxy_config_data[$k] = false; if($v == "true")$proxy_config_data[$k] = true;
		}
	}
	$proxy_config_data = json_encode($proxy_config_data);
	$authorization = "Authorization: Bearer ".$token;
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_HTTPHEADER, array(
		'Content-Type: application/json',
		'Content-Length: ' . strlen($proxy_config_data),
		$authorization) 
	);
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
	curl_setopt($ch, CURLOPT_POSTFIELDS, $proxy_config_data);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 6);
	$response  = curl_exec($ch);
	curl_close($ch);
	return $response;
}

//-- Get History JSON
function get_history($proxy_id){
	$myFile = "history.json";
	if (file_exists($myFile)) $data = file_get_contents($myFile); else return false;
	if(!isJson($data)) return false;
	$data = json_decode($data,true);
	$proxy_adress = $GLOBALS["proxy_list"][$proxy_id]["ip"].":".$GLOBALS["proxy_list"][$proxy_id]["port"];
	$history = array();
	$key = array_search($proxy_adress, array_column($data, 'proxy'));
	if($key !== false) $history = $data[$key]["history"];
	return array_reverse($history);
}

//-- Get Jobs From JSON
function get_jobs(){
	$myFile = 'jobs.json';
	if (file_exists($myFile)) $data = file_get_contents($myFile); else return false;
	if(!isJson($data)) return false;
	return json_decode($data, true);
}

function array_move(&$array,$swap_a,$swap_b){
   list($array[$swap_a],$array[$swap_b]) = array($array[$swap_b],$array[$swap_a]);
}

function isJson($string){
   return is_string($string) && is_array(json_decode($string, true)) && (json_last_error() == JSON_ERROR_NONE) ? true : false;
}

function verify_password($headers){
	global $db_login;
	//var_dump($db_login);
	$enc_creds = explode(":", $headers['Authorization']);
	$username = base64_decode($enc_creds[0]);
	//var_dump($username);
	$password = base64_decode($enc_creds[1]);
	//var_dump($password);
	//var_dump(DB_Login::CheckPassword($username, $password));
	return $db_login->CheckPassword($username, $password);
	
	
}

function user_is_admin($headers){
	global $db_login;
	$enc_creds = explode(":", $headers['Authorization']);
	$username = base64_decode($enc_creds[0]);
	return $db_login->IsAdmin($username) == 1;
}

function toggle_admin(){
	global $db_login;
	$user_id = $db_login->GetUserId($_POST["user"]);
	return $db_login->ToggleAdmin($user_id);
}

function toggle_active(){
	global $db_login;
	$user_id = $db_login->GetUserId($_POST["user"]);
	//var_dump($_POST["user"]);
	return $db_login->ToggleActive($user_id);
}

function add_user(){
	global $db_login;
	//var_dump($_POST);
	$new_user = base64_decode($_POST["new_user"]);
	$new_pass = base64_decode($_POST["new_pass"]);
	$is_admin = base64_decode($_POST["is_admin"]) == "true";
	return $db_login->AddUser($new_user, $new_pass, $is_admin);
}

function delete_users(){
	global $db_login;
	$success = true;
	$del_users = explode(",",$_POST["del_users"]);
	foreach ($del_users as $user) {
		$user_id = $db_login->GetUserId($user);
		$result = $db_login->DeleteUser($user_id);
		if (!$result)
			$success = false;
	}
	return $success;
}

function get_users(){
	global $db_login;
	return $db_login->GetUsers();
}

function get_curl_data($ip, $port, $endpoint, $token){
	$ch = curl_init();
	$authorization = "Authorization: Bearer ".$token;
    curl_setopt ($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json' , $authorization ));
	curl_setopt ($ch, CURLOPT_URL, "http://$ip:$port/1/$endpoint");
	curl_setopt ($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt ($ch, CURLOPT_CONNECTTIMEOUT, 6);
	$response = curl_exec($ch);
	curl_close ($ch);
	return($response); 	
}
?>
