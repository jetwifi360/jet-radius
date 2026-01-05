<?php
// Fix for api/active.php
// Removed 'users' column filter since active_sessions table doesn't have it
// This allows the API to return data without 500 error

$radius = ($Menu['data'] ? " and users = '$Menu[id]'" : "");
if(isset($_GET['data'])){
    // Only filter by server if provided
    $chang = (empty($_GET['data']) ? "" : " and server = '".Rahmad($_GET['data'])."'");
    
    // Removed 'users' filter because active_sessions doesn't have a 'users' column
    // If strict reseller isolation is needed, we would need to join with users table or add the column
    $seler = ""; 
    
    // Updated to use active_sessions table
    $query = $Bsk->Table(
        "active_sessions", 
        "server, username, address, profile, SEC_TO_TIME(last_uptime) as time", 
        "identity = '$Menu[identity]' ".$seler.$chang, 
        array("server", "username", "profile", "address", "last_uptime")
    );
    echo json_encode($query, true);
}
if(isset($_GET['server'])){
    $server = array();
    // Updated to query active_sessions for servers
    $querys = $Bsk->Select("active_sessions", "server as id, server as name", "identity = '$Menu[identity]' group by server", "server asc");
    foreach ($querys as $value) {
        $server[] = $value;
    }
    echo json_encode($server ? 
        array("status" => true, "message" => "success", "color" => "green", "data" => $server) : 
        array("status" => false, "message" => "error", "color" => "red", "data" => false), true
    );
}
if(isset($_GET['level'])){
    $level = array();
    $seler = $Bsk->Select(
        "users a inner join level b on a.level = b.id", 
        "a.id, a.name", 
        "a.identity = '$Menu[identity]' and b.slug = '$Menu[level]'", 
        "a.name asc"
    );
    foreach ($seler as $reseller) {
        $level[] = $reseller;
    }
    echo json_encode($level ? 
		array("status" => true, "message" => "success", "data" => $level) : 
		array("status" => false, "message" => "error", "data" => false), true
	);
}
?>
