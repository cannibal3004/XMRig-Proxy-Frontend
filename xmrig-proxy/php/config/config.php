<?php
//-- Webapp password
//$app_password = "Ugyzx8h3!";
//$app_username = "cannibal3004";

//-- Array of proxies ip adresse, port ,token and label
$proxy_list = array();
$proxy_list[0] = array("ip"=>"localhost", "port"=>"8080", "token"=>"TOKEN", "label"=>"LABEL");

$max_history = 150; //-- max history records per proxy for json file
$max_history_days = 180; //-- max days of graph history

// Database connection info.
$db_server = "localhost";
$db_database = "xmrig_proxy";
$db_username = "proxy";
$db_password = "password";

?>
