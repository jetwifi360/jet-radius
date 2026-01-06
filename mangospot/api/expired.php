<?php
$radius = ($Menu['data'] ? " inner join packet c on a.groupname = c.groupname " : "and a.users = '$Menu[id]'");
if(isset($_GET['data'])){
    // 1. Fetch ALL candidate users (Cleartext-Password)
    // We need to fetch them all to filter by expiration logic in PHP
    $chang = (empty($_GET['data']) ? "" : " and b.groupname = '".addslashes(Rahmad($_GET['data']))."'");
    
    // Filter by specific reseller (users column)
    $getUsers = (empty($_GET['users']) ? $Menu['id'] : Rahmad($_GET['users']));
    $user_filter = " AND a.users = '$getUsers' ";
    
    $users_where = "a.identity = '{$Menu['identity']}' AND a.attribute = 'Cleartext-Password' " . $user_filter . $chang;
    
    if(!empty($_REQUEST['search']['value'])) {
        $st = strtolower($_REQUEST['search']['value']);
        // Use strict prefix match to be consistent with users.php
        $users_where .= " AND lower(a.username) LIKE '$st%' ";
    }
    
    // Fetch all users matching the search/profile criteria
    $query_users = $Bsk->Select(
        "radcheck a left join radusergroup b on a.username = b.username", 
        "a.username, b.groupname as profile, a.created", 
        $users_where
    );
    
    $all_users = array();
    if($query_users){
        foreach($query_users as $u){
            $all_users[] = $u;
        }
    }
    
    // 2. Prepare for Bulk Attribute Fetching
    $usernames = array_column($all_users, 'username');
    $usage_data = array();
    $exp_data = array();
    $group_limits = array();
    
    if (!empty($usernames)) {
        // Chunking to avoid SQL limits if too many users
        $chunks = array_chunk($usernames, 500);
        
        foreach($chunks as $chunk){
             $safe_usernames = array_map(function($u) { return str_replace("'", "''", $u); }, $chunk);
             $user_list = "'" . implode("','", $safe_usernames) . "'";
             
             // Usage
             $res_usage = $Bsk->Select("custom_usage", "username, total_bytes, total_time", "username IN ($user_list)");
             if($res_usage) {
                 foreach($res_usage as $u) { $usage_data[$u['username']] = $u; }
             }
             
             // Expiration Date
             $res_exp = $Bsk->Select("radcheck", "username, value", "attribute='Expiration' AND username IN ($user_list)");
             if($res_exp) {
                 foreach($res_exp as $e) { $exp_data[$e['username']] = $e['value']; }
             }
        }
        
        // Group Limits (Fetch all relevant groups)
        $profiles = array_unique(array_column($all_users, 'profile'));
        $safe_profiles = array_map(function($p) { return str_replace("'", "''", $p); }, $profiles);
        if(!empty($safe_profiles)){
            $prof_list = "'" . implode("','", $safe_profiles) . "'";
            $res_limits = $Bsk->Select("radgroupcheck", "groupname, value", "attribute='Max-All-Session' AND groupname IN ($prof_list)");
            if($res_limits){
                foreach($res_limits as $l){ $group_limits[$l['groupname']] = $l['value']; }
            }
        }
    }
    
    // 3. Filter Expired Users
    $expired_list = array();
    foreach($all_users as $row){
        $user = $row['username'];
        $is_expired = false;
        $reason = "Expired";
        
        // Logic from users.php
        $limit_time = isset($group_limits[$row['profile']]) ? intval($group_limits[$row['profile']]) : 0;
        
        if ($limit_time > 0) {
            $used_time = isset($usage_data[$user]) ? intval($usage_data[$user]['total_time']) : 0;
            $rem_seconds = $limit_time - $used_time;
            
            if ($rem_seconds <= 0) {
                $is_expired = true;
                $reason = "Time Limit Reached";
            }
        } elseif (isset($exp_data[$user])) {
            // Check Date Expiration
            $exp_ts = strtotime($exp_data[$user]);
            if ($exp_ts) {
                if ($exp_ts <= time()) {
                     $is_expired = true;
                     $reason = "Date Expired";
                }
            }
        }
        
        if($is_expired){
            $expired_list[] = array(
                "profile" => $row['profile'],
                "username" => $row['username'],
                "time" => $row['created'],
                "usages" => $reason, 
                "expired" => "Expired",
                "price" => 0,
                "discount" => 0,
                "total" => 0,
                // Helper for frontend (checkbox value)
                "id" => $row['username'] 
            );
        }
    }
    
    // 4. Pagination
    $total = count($expired_list);
    $start = isset($_REQUEST['start']) ? intval($_REQUEST['start']) : 0;
    $length = isset($_REQUEST['length']) ? intval($_REQUEST['length']) : 10;
    
    // Handle "All" (-1)
    if($length == -1) $length = $total;
    
    $data = array_slice($expired_list, $start, $length);
    
    echo json_encode(array(
        "draw" => intval($_REQUEST['draw'] ?? 0),
        "recordsTotal" => $total,
        "recordsFiltered" => $total,
        "data" => $data
    ));
}
if(isset($_GET['profile'])){
    $array_profile = array();
    $query_profile = $Bsk->Select(
        "radgroupcheck a left join radgroupreply b on a.groupname = b.groupname ".$radius, 
        "a.groupname as id, a.groupname as name, a.groupname", 
        "a.identity = '$Menu[identity]' GROUP BY a.groupname", "a.groupname asc"
    );
    foreach ($query_profile as $show_profile) {
        $array_profile[] = $show_profile;
    }
    echo json_encode($array_profile ? 
		array("status" => true, "message" => "success", "data" => $array_profile) : 
		array("status" => false, "message" => "error", "data" => false), true
	);
}
if(isset($_GET['level'])){
    $level = array();
    $seler = $Bsk->Select(
        "users a inner join level b on a.level = b.id", 
        "a.id, a.name", 
        "a.identity = '$Menu[identity]' and (b.slug = '$Menu[level]' or b.id = '$Menu[level]')", 
        "a.id asc"
    );
    foreach ($seler as $reseller) {
        $level[] = $reseller;
    }
    echo json_encode($level ? 
		array("status" => true, "message" => "success", "data" => $level, "value" => $Menu['id']) : 
		array("status" => false, "message" => "error", "data" => false, "value" => false), true
	);
}
if(isset($_GET['code'])){
    $code = array();
    $base = $Bsk->Select("type", "id, name", "type = 'cron' and status = 'true'", "id asc");
    foreach ($base as $type) {
        $code[] = $type;
    }
    echo json_encode($code ? 
        array("status" => true, "message" => "success", "data" => $code) : 
        array("status" => false, "message" => "error", "data" => false), true
    );
}
if(isset($_GET['detail'])){
    $getType = Rahmad($_GET['detail']);
    $showData = $Bsk->Show("type", "info, lower(name) as mode", "id = '$getType' and type = 'cron' and status = 'true' ");
    echo json_encode($showData ? 
        array("status" => true, "message" => "success", "data" => $showData) : 
        array("status" => false, "message" => "error", "data" => false), true
    );
}
if(isset($_POST['delete'])){
    $insql = array();
    $getId = Rahmad($_POST['client']);
    $implod = "'".implode("','", $_POST['delete'])."'";
    $resume = $Bsk->Show(
        "expired", "identity, users, count(*) AS total, sum(price) as price, sum(discount) as discount, sum(total) as income, sum(upload) as upload, sum(download) as download, now() as date",
        "identity = '$Menu[identity]' and users = '$getId' and username in (".$implod.") group by identity, users"
    );
    $saved = $Bsk->Select(
        "expired", "username, profile, time, usages, quota, price, discount, total",
        "identity = '$Menu[identity]' and users = '$getId' and username IN (".$implod.") ", "time asc"
    );
    foreach($saved as $insert){
        $insql[] = $insert;
    }
    $recap = $Bsk->Insert("income", array_merge($resume, array("data" => json_encode($insql, true))));
    foreach($_POST['delete'] as $removeID){
        $Bsk->Delete("radacct",     array("username" => $removeID));
        $Bsk->Delete("radpostauth", array("username" => $removeID));
        $Bsk->Delete("radcheck",    array("username" => $removeID, "identity" => $Menu['identity'], "users" => $getId));
        $Bsk->Delete("radreply",    array("username" => $removeID, "identity" => $Menu['identity'], "users" => $getId));
        $Bsk->Delete("radusergroup",array("username" => $removeID, "identity" => $Menu['identity'], "users" => $getId));
    }
    echo json_encode($recap ? 
		array("status" => true, "message" => "success", "data" => "Delete data success") : 
		array("status" => false, "message" => "error", "data" => "Delete data failed!"), true
    );
}
?>