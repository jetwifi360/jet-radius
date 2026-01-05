<?php
$radius = ($Menu['data'] ? "and a.users = '$Menu[id]'" : "");

// Helper to connect to MikroTik
function connect_mikrotik_user($u, $Bsk, $Router, $Auth) {
    // 1. Find NAS IP from active_sessions
    $session = $Bsk->Show("active_sessions", "nas_ip", "username='$u'");
    if(!$session) {
        // Fallback: Check if user is in radacct (maybe just logged off) or just try all NAS?
        // For now, return false if not in active_sessions, effectively skipping disconnect if not online.
        // BUT for reset_traffic, we might want to connect to the NAS where the user is *likely* to be?
        // No, if not active, we can't 'disconnect' them. 
        // For reset-counters, we need to know which router. 
        // If we can't find them in active_sessions, we can try to look at last radacct entry?
        return false;
    }
    $nas_ip = $session['nas_ip'];
    $nas = $Bsk->Show("nas", "*", "nasname='$nas_ip'");
    if(!$nas) return false;
    
    $ports = ($nas['port'] ? ":".$nas['port'] : "");
    return $Router->connect($nas['nasname'].$ports, $nas['username'], $Auth->decrypt($nas['password'], 'BSK-RAHMAD'));
}

// Handle Actions
if(isset($_POST['action'])){
    $action = Rahmad($_POST['action']);
    $u = Rahmad($_POST['username']);
    $resp = array("status"=>false, "message"=>"Unknown action");
    
    if($action == 'activate') {
        // Remove Auth-Type := Reject
        $Bsk->Delete("radcheck", array("username"=>$u, "attribute"=>"Auth-Type", "value"=>"Reject"));
        $resp = array("status"=>true, "message"=>"User activated");
    } 
    elseif($action == 'disable') {
        // Add Auth-Type := Reject
        $chk = $Bsk->Show("radcheck", "*", "username='$u' AND attribute='Auth-Type'");
        if($chk){
            $Bsk->Update("radcheck", array("value"=>"Reject", "op"=>":="), "username='$u' AND attribute='Auth-Type'");
        } else {
            $Bsk->Insert("radcheck", array("username"=>$u, "attribute"=>"Auth-Type", "op"=>":=", "value"=>"Reject", "created"=>date('Y-m-d H:i:s')));
        }
        // Also disconnect
        if(connect_mikrotik_user($u, $Bsk, $Router, $Auth)){
             $uid = $Router->comm("/ip/hotspot/active/print", array("?user" => $u));
             foreach($uid as $user_active) { $Router->comm("/ip/hotspot/active/remove", array(".id" => $user_active['.id'])); }
             $ppp_id = $Router->comm("/ppp/active/print", array("?name" => $u));
             foreach($ppp_id as $ppp_active) { $Router->comm("/ppp/active/remove", array(".id" => $ppp_active['.id'])); }
             $Router->disconnect();
        }
        $resp = array("status"=>true, "message"=>"User disabled");
    }
    elseif($action == 'disconnect') {
        if(connect_mikrotik_user($u, $Bsk, $Router, $Auth)){
             $uid = $Router->comm("/ip/hotspot/active/print", array("?user" => $u));
             foreach($uid as $user_active) { $Router->comm("/ip/hotspot/active/remove", array(".id" => $user_active['.id'])); }
             $ppp_id = $Router->comm("/ppp/active/print", array("?name" => $u));
             foreach($ppp_id as $ppp_active) { $Router->comm("/ppp/active/remove", array(".id" => $ppp_active['.id'])); }
             $Router->disconnect();
             $resp = array("status"=>true, "message"=>"User disconnected");
        } else {
             $resp = array("status"=>false, "message"=>"User not online or NAS unreachable");
        }
    }
    elseif($action == 'reset_traffic') {
        $Bsk->Update("custom_usage", array("total_bytes"=>0, "total_time"=>0), "username='$u'");
        $Bsk->Update("daily_usage", array("total_bytes"=>0, "total_time"=>0), "username='$u'");
        $Bsk->Delete("radacct", array("username"=>$u)); 
        
        // Reset on MikroTik
        if(connect_mikrotik_user($u, $Bsk, $Router, $Auth)){
             $Router->comm("/ip/hotspot/user/reset-counters", array("username" => $u));
             $uid = $Router->comm("/ip/hotspot/active/print", array("?user" => $u));
             foreach($uid as $user_active) { $Router->comm("/ip/hotspot/active/remove", array(".id" => $user_active['.id'])); }
             $Router->disconnect();
        }
        $resp = array("status"=>true, "message"=>"Traffic reset");
    }
    elseif($action == 'delete') {
        if(!$u){
            echo json_encode(array("status"=>false, "message"=>"No username specified"));
            exit;
        }
        // Disconnect first
        if(connect_mikrotik_user($u, $Bsk, $Router, $Auth)){
             $uid = $Router->comm("/ip/hotspot/active/print", array("?user" => $u));
             foreach($uid as $user_active) { $Router->comm("/ip/hotspot/active/remove", array(".id" => $user_active['.id'])); }
             $ppp_id = $Router->comm("/ppp/active/print", array("?name" => $u));
             foreach($ppp_id as $ppp_active) { $Router->comm("/ppp/active/remove", array(".id" => $ppp_active['.id'])); }
             $Router->disconnect();
        }
        
        // Delete from DB
        $Bsk->Delete("radacct", array("username" => $u));
        $Bsk->Delete("radcheck", array("username" => $u));
        $Bsk->Delete("radreply", array("username" => $u));
        $Bsk->Delete("radusergroup", array("username" => $u));
        $Bsk->Delete("custom_usage", array("username" => $u));
        $Bsk->Delete("daily_usage", array("username" => $u));
        
        $resp = array("status"=>true, "message"=>"User deleted");
    }
    elseif($action == 'update_info') {
        $info = array(
            "firstname" => Rahmad($_POST['firstname']),
            "lastname" => Rahmad($_POST['lastname']),
            "phone" => Rahmad($_POST['phone']),
            "email" => Rahmad($_POST['email'])
        );
        $json = json_encode($info);
        $Bsk->Update("radcheck", array("description"=>$json), "username='$u' AND attribute='Cleartext-Password'");
        $resp = array("status"=>true, "message"=>"Info updated");
    }
    elseif($action == 'change_name') {
        $new_u = Rahmad($_POST['new_username']);
        if($new_u && $new_u != $u){
            $chk = $Bsk->Show("radcheck", "username", "username='$new_u'");
            if($chk){
                $resp = array("status"=>false, "message"=>"Username already exists");
            } else {
                $Bsk->Update("radcheck", array("username"=>$new_u), "username='$u'");
                $Bsk->Update("radreply", array("username"=>$new_u), "username='$u'");
                $Bsk->Update("radusergroup", array("username"=>$new_u), "username='$u'");
                $Bsk->Update("radacct", array("username"=>$new_u), "username='$u'");
                $Bsk->Update("custom_usage", array("username"=>$new_u), "username='$u'");
                $Bsk->Update("daily_usage", array("username"=>$new_u), "username='$u'");
                $resp = array("status"=>true, "message"=>"Username changed");
            }
        }
    }
    
    echo json_encode($resp);
    exit;
}

if(isset($_GET['data'])){
    $getUsers = (empty($_GET['users']) ? $Menu['id'] : Rahmad($_GET['users'])); 
    $getGroup = (empty($_GET['data']) ? "" : " and b.groupname = '".Rahmad($_GET['data'])."' ");
    
    // Select basic data
    $radcheck = $Bsk->Table(
        "radcheck a left join radusergroup b on a.username = b.username", 
        "a.id, a.username, b.groupname as profiles, a.description, a.created", 
        "a.identity = '$Menu[identity]' and a.attribute = 'Cleartext-Password' ".$getGroup, 
        array("a.username")
    );

    $pwdless = $Bsk->Select(
        "radusergroup b left join radcheck a on a.username = b.username and a.attribute = 'Cleartext-Password' left join userinfo u on u.username = b.username",
        "b.username, b.groupname as profiles, u.creationdate as created",
        "b.identity = '$Menu[identity]' and a.username IS NULL"
    );
    if($pwdless){
        if(!isset($radcheck['data']) || !is_array($radcheck['data'])) $radcheck['data'] = array();
        foreach($pwdless as $pl){
            $radcheck['data'][] = array(
                'id' => 0,
                'username' => $pl['username'],
                'profiles' => $pl['profiles'],
                'description' => '',
                'created' => ($pl['created'] ?? '')
            );
        }
    }

    // Inject missing fields with real data
    if ($radcheck['data']) {
        $usernames = array_column($radcheck['data'], 'username');
        $safe_usernames = array_map(function($u) { return str_replace("'", "''", $u); }, $usernames);
        $user_list = "'" . implode("','", $safe_usernames) . "'";
        
        // 1. Online Status
        $online_users = array();
        if (!empty($usernames)) {
            $res_online = $Bsk->Select("active_sessions", "username", "username IN ($user_list)");
            if($res_online) {
                foreach($res_online as $o) { $online_users[$o['username']] = true; }
            }

            // 2. Usage Data (Total) - For Remaining Calculation
            $usage_data = array();
            $res_usage = $Bsk->Select("custom_usage", "username, total_bytes, total_time", "username IN ($user_list)");
            if($res_usage) {
                foreach($res_usage as $u) { $usage_data[$u['username']] = $u; }
            }
            
            // 3. Daily Usage - For Table Column
            $daily_usage = array();
            $today = date('Y-m-d');
            $res_daily = $Bsk->Select("daily_usage", "username, total_bytes", "date='$today' AND username IN ($user_list)");
            if($res_daily) {
                foreach($res_daily as $d) { $daily_usage[$d['username']] = $d['total_bytes']; }
            }
            
            // 4. Expiration
            $exp_data = array();
            $res_exp = $Bsk->Select("radcheck", "username, value", "attribute='Expiration' AND username IN ($user_list)");
            if($res_exp) {
                foreach($res_exp as $e) { $exp_data[$e['username']] = $e['value']; }
            }
            
            // 5. Group Limits
            $group_limits = array();
            $res_limits = $Bsk->Select("radgroupcheck", "groupname, value", "attribute='Max-All-Session'");
            if($res_limits){
                foreach($res_limits as $l){ $group_limits[$l['groupname']] = $l['value']; }
            }
        }

        foreach ($radcheck['data'] as &$row) {
            $user = $row['username'];
            
            // Status
            $row['status'] = isset($online_users[$user]) ? '<span class="badge badge-success">Online</span>' : '<span class="badge badge-secondary">Offline</span>';

            $row['firstname'] = ''; $row['lastname'] = ''; 
            
            // Parse Description JSON
            $desc_data = json_decode($row['description'], true);
            if(json_last_error() === JSON_ERROR_NONE && is_array($desc_data)){
                 $row['firstname'] = isset($desc_data['firstname']) ? $desc_data['firstname'] : '';
                 $row['lastname'] = isset($desc_data['lastname']) ? $desc_data['lastname'] : '';
            }

            // Expiration
            // Fix: Don't fallback to created date. If Expiration is missing, it's unlimited.
            $row['expiration'] = isset($exp_data[$user]) ? $exp_data[$user] : '';

            // Daily Traffic
            $d_bytes = isset($daily_usage[$user]) ? $daily_usage[$user] : 0;
            $row['traffic'] = formatBytes($d_bytes);

            $row['parent'] = ''; $row['debts'] = ''; 
            
            // Calculate Remaining Days / Time
            $remaining_str = '-';
            $limit_time = isset($group_limits[$row['profiles']]) ? intval($group_limits[$row['profiles']]) : 0;
            
            if ($limit_time > 0) {
                $used_time = isset($usage_data[$user]) ? intval($usage_data[$user]['total_time']) : 0;
                $rem_seconds = $limit_time - $used_time;
                
                if ($rem_seconds > 0) {
                    $d = floor($rem_seconds / 86400);
                    $h = floor(($rem_seconds % 86400) / 3600);
                    $m = floor(($rem_seconds % 3600) / 60);
                    $s = $rem_seconds % 60;
                    
                    $parts = array();
                    if ($d > 0) $parts[] = "{$d}d";
                    if ($h > 0) $parts[] = "{$h}h";
                    if ($m > 0) $parts[] = "{$m}m";
                    if ($s > 0) $parts[] = "{$s}s";
                    
                    $remaining_str = implode(' ', $parts);
                    if(empty($remaining_str)) $remaining_str = '0s';
                } else {
                    $remaining_str = '<span class="badge badge-danger">Expired</span>';
                }
            } elseif (!empty($row['expiration'])) {
                $exp_ts = strtotime($row['expiration']);
                if ($exp_ts) {
                    $now = time();
                    if ($exp_ts > $now) {
                        $diff = $exp_ts - $now;
                        $days = floor($diff / 86400);
                        $hours = floor(($diff % 86400) / 3600);
                        $remaining_str = "{$days}d {$hours}h";
                    } else {
                        $remaining_str = '<span class="badge badge-danger">Expired</span>';
                    }
                }
            } else {
                 $remaining_str = '<span class="badge badge-success">Unlimited</span>';
            }
            $row['remaining'] = $remaining_str;
        }
    }
	echo json_encode($radcheck, true);
}
if(isset($_GET['user'])){
    $u = Rahmad($_GET['user']);
    $data = array();
    
    $info = $Bsk->Show("radcheck a left join radusergroup b on a.username=b.username", 
        "a.username, a.value as password, b.groupname as profile, a.description, a.created", 
        "a.username = '$u' AND a.attribute='Cleartext-Password'");
        
    if($info){
        $data = $info;
        $data['parent'] = 'admin'; 
        
        // Parse Description JSON
        $desc_data = json_decode($info['description'], true);
        if(json_last_error() === JSON_ERROR_NONE && is_array($desc_data)){
            $data['firstname'] = isset($desc_data['firstname']) ? $desc_data['firstname'] : '';
            $data['lastname'] = isset($desc_data['lastname']) ? $desc_data['lastname'] : '';
            $data['phone'] = isset($desc_data['phone']) ? $desc_data['phone'] : '';
            $data['email'] = isset($desc_data['email']) ? $desc_data['email'] : '';
        } else {
            $data['firstname'] = ''; $data['lastname'] = ''; $data['phone'] = ''; $data['email'] = '';
        }
        
        $exp = $Bsk->Show("radcheck", "value", "username='$u' AND attribute='Expiration'");
        $data['expiration'] = $exp ? $exp['value'] : '';
        
        $online = $Bsk->Show("active_sessions", "username", "username='$u'");
        $data['status'] = $online ? 'active' : 'offline';
        
        $usage = $Bsk->Show("custom_usage", "*", "username='$u'");
        
        $limit_time = 0; $limit_data = 0;
        if($data['profile']){
            $g_time = $Bsk->Show("radgroupcheck", "value", "groupname='".$data['profile']."' AND attribute='Max-All-Session'");
            if($g_time) $limit_time = intval($g_time['value']);
            
            $g_data = $Bsk->Show("radgroupcheck", "value", "groupname='".$data['profile']."' AND attribute='Max-Total-Octets'");
            if($g_data) $limit_data = intval($g_data['value']);
        }
        
        $data['remaining_traffic'] = 'Unlimited';
        $data['remaining_uptime'] = 'Unlimited';

        if($usage){
             // Time
             if($limit_time > 0){
                 $rem_seconds = $limit_time - intval($usage['total_time']);
                 if($rem_seconds > 0){
                    $d = floor($rem_seconds / 86400);
                    $h = floor(($rem_seconds % 86400) / 3600);
                    $m = floor(($rem_seconds % 3600) / 60);
                    $s = $rem_seconds % 60;
                    $parts = array();
                    if ($d > 0) $parts[] = "{$d}d";
                    if ($h > 0) $parts[] = "{$h}h";
                    if ($m > 0) $parts[] = "{$m}m";
                    if ($s > 0) $parts[] = "{$s}s";
                    $data['remaining_uptime'] = implode(' ', $parts);
                 } else {
                    $data['remaining_uptime'] = 'Expired';
                 }
             }
             
             // Data
             if($limit_data > 0){
                 $rem_bytes = $limit_data - intval($usage['total_bytes']);
                 $data['remaining_traffic'] = ($rem_bytes > 0) ? formatBytes($rem_bytes) : 'Exhausted';
             }
             
             $data['remaining_download'] = 0; 
             $data['remaining_upload'] = 0;
        } else {
             if($limit_time > 0) $data['remaining_uptime'] = 'Full';
             if($limit_data > 0) $data['remaining_traffic'] = formatBytes($limit_data);
        }
        
        $data['sessions'] = array();
    }
    echo json_encode(array("status" => true, "data" => $data));
    exit;
}
if(isset($_GET['table'])){
    $get_user = (empty($_GET['users']) ? $Menu['id'] : Rahmad($_GET['users']));
    $get_group = (empty($_GET['table']) ? '' : " and b.groupname = '".Rahmad($_GET['table'])."' ");
    $tables = $Bsk->Table(
        "radcheck a left join radusergroup b on a.username = b.username", 
        "a.id, a.username, b.groupname as profile, a.created", 
        "a.identity = '$Menu[identity]' and a.attribute = 'Cleartext-Password' ".$get_group, 
        array("a.username", "b.groupname", "a.created", "a.id")
    );
	echo json_encode($tables, true);
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
if(isset($_GET['detail'])){
    $id_detail = Rahmad($_GET['detail']);
    $show_detail = $Bsk->Show(
        "radcheck a left join radusergroup b on a.username = b.username", 
        "a.id, a.username, a.value, b.groupname, a.description, a.created", 
        "a.id = '$id_detail' and a.identity = '$Menu[identity]'"
    );
    
    if($show_detail){
        $replace_data = array(
            "id" => $show_detail['id'],
            "username" => $show_detail['username'],
            "passwd" => $show_detail['value'],
            "profiles" => $show_detail['groupname'],
            "firstname" => "", 
            "lastname" => "", 
            "phone" => "", 
            "description" => $show_detail['description'],
            "created" => $show_detail['created']
        );
    } else {
        $replace_data = false;
    }
    
    echo json_encode($replace_data ? 
		array("status" => true, "message" => "success", "data" => $replace_data) : 
		array("status" => false, "message" => "error", "data" => false), true
	);
}

if(isset($_GET['profiles'])){
    $array_profile = array();
    $query_profile = $Bsk->Select(
        "radgroupcheck a left join radgroupreply b on a.groupname = b.groupname", 
        "a.groupname as id, a.groupname as name, a.groupname", 
        "a.identity = '$Menu[identity]' $radius GROUP BY a.groupname", "a.groupname asc"
    );
    foreach ($query_profile as $show_profile) {
        $array_profile[] = $show_profile;
    }
    echo json_encode($array_profile ? 
		array("status" => true, "message" => "success", "data" => $array_profile) : 
		array("status" => false, "message" => "error", "data" => false), true
	);
}
if(isset($_GET['theme'])){
    $themes = array();
    $query_themes = $Bsk->Select("themes a", "a.id, a.name", "a.identity = '$Menu[identity]' and a.type = 'radius' $radius", "a.id asc");
    foreach ($query_themes as $value_themes) {
        $themes[] = $value_themes;
    }
    echo json_encode($themes ? 
		array("status" => true, "message" => "success", "data" => $themes) : 
		array("status" => false, "message" => "error", "data" => false), true
	);
}
if(isset($_POST['username'])){
    $id_users    = Rahmad($_POST['username']);
    $username    = Rahmad($_POST['username']);
    $count_check = count($_POST['radcheck']);
    $count_reply = count($_POST['radreply']);
    for($i=0; $i<$count_check; $i++){
        $attribute = $_POST['attribute'][$i];
        $check_check = $Bsk->Show(
            "radcheck a", "a.username", 
            "a.username = '$id_users' and a.attribute = '$attribute' and a.identity = '$Menu[identity]' $radius"
        );
        if(!empty($_POST['radcheck'][$i])){
            $query_check = array(
                "identity"   => $Menu['identity'],
                "users"      => $Menu['id'],
                "username"   => $username, 
                "attribute"  => $_POST['attribute'][$i],
                "op"         => ":=",
                "value"      => ($_POST['attribute'][$i] == 'Simultaneous-Use' ? $_POST['radcheck'][$i] : ($_POST['attribute'][$i] == 'Max-Data' ? ByteConvert($_POST['radcheck'][$i]) : DateTime($_POST['radcheck'][$i]))),
                "description"=> $_POST['description']
            );
            if($check_check){
                $Bsk->Update("radcheck", $query_check, "username = '$check_check[username]' and attribute = '$attribute' and identity = '$Menu[identity]' ");
            } else {
                $Bsk->Insert("radcheck", array_merge($query_check, array("created" => date('Y-m-d H:i:s'))));
            }
        } else {
            $Bsk->Delete("radcheck", array(
                "username"  => $check_check['username'],
                "attribute" => $attribute,
                "identity"  => $Menu['identity']
            ));
        }
    }
    for($e=0; $e<$count_reply; $e++){
        $attribut = $_POST['attribut'][$e];
        $check_reply = $Bsk->Show(
            "radreply a", "a.username", 
            "a.username = '$id_users' and a.attribute = '$attribut' and a.identity = '$Menu[identity]' $radius"
        );
        if(!empty($_POST['radreply'][$e])){
            $query_reply = array(
                "identity"   => $Menu['identity'],
                "users"      => $Menu['id'],
                "username"   => $username,
                "attribute"  => $_POST['attribut'][$e], 
                "op"         => ":=",
                "value"      => ($_POST['attribut'][$e] == 'Mikrotik-Total-Limit' ? ByteConvert($_POST['radreply'][$e]) : $_POST['radreply'][$e]),
                "description"=> $_POST['description']
            );
            if($check_reply){
                $Bsk->Update("radreply", $query_reply, "username = '$check_reply[username]' and attribute = '$attribut' and identity = '$Menu[identity]' ");
            } else {
                $Bsk->Insert("radreply", array_merge($query_reply, array("created" => date('Y-m-d H:i:s'))));
            }
        } else {
            $Bsk->Delete("radreply", array(
                "username"  => $check_reply['username'],
                "attribute" => $attribut,
                "identity"  => $Menu['identity']
            ));
        }
    }
    $check_group = $Bsk->Show("radusergroup a", "a.username", "a.username = '$id_users' and a.identity = '$Menu[identity]' $radius");
    if(!empty($_POST['groupname'])){
        if($check_group){
            $Bsk->Update("radusergroup", 
                array(
                    "username"  => $username,
                    "groupname" => Rahmad($_POST['groupname'])
                ),
                "username = '$check_group[username]' and identity = '$Menu[identity]' "
            );
        } else {
            $Bsk->Insert("radusergroup", 
                array(
                    "username"  => $username,
                    "groupname" => Rahmad($_POST['groupname']),
                    "identity"  => $Menu['identity'],
                    "users"     => $Menu['id']
                )
            );
        }
    } else {
        $Bsk->Delete("radusergroup", array("username" => $id_users, "identity" => $Menu['identity']));
    }

    echo json_encode($username ? 
		array("status" => true, "message" => "success", "color" => "green", "data" => "Proccess data success") : 
		array("status" => false, "message" => "error", "color" => "red", "data" => "Proccess data failed!"), true
	);
}
if(isset($_POST['qty'])){
    $success = 0; $failed = 0;
    $qty = Rahmad($_POST['qty']);
    $mod = Rahmad($_POST['mode']);
    $lng = Rahmad($_POST['length']);
    $prf = Rahmad($_POST['prefix']);
    $crt = Rahmad($_POST['charecter']);
    $p_size = isset($_POST['p_size']) ? Rahmad($_POST['p_size']) : $lng;
    $p_size = intval($p_size);
    $nas_ip = isset($_POST['nas_ip']) ? Rahmad($_POST['nas_ip']) : '';
    if(!$nas_ip){
        $grp = Rahmad($_POST['groupname']);
        $hp = $Bsk->Show("radgroupreply", "value", "groupname='$grp' AND attribute='Mikrotik-Hotspot-Profile'");
        if($hp && !empty($hp['value'])){
            $sv = strtolower($hp['value']);
            if(strpos($sv, 'caferoom') !== false){
                $nas_ip = '192.168.5.1';
            } elseif(strpos($sv, 'hsprof1') !== false){
                $nas_ip = '10.10.0.1';
            }
        }
        if(!$nas_ip){
            $g = strtolower($grp);
            if(strpos($g, 'caferoom') !== false){
                $nas_ip = '192.168.5.1';
            } elseif(strpos($g, 'hsprof1') !== false){
                $nas_ip = '10.10.0.1';
            }
        }
    }

    // --- INTEGRATION: CREATE BATCH RECORD FOR VOUCHER PRINTING ---
    $current_year = date('Y');
    // Find max sequence for current year to generate Series ID
    $last_batch = $Bsk->Show("card_batches", "batch_name", "batch_name LIKE '$current_year:%' ORDER BY id DESC LIMIT 1");
    if($last_batch && isset($last_batch['batch_name'])){
        $parts = explode(':', $last_batch['batch_name']);
        if(count($parts) == 2 && is_numeric($parts[1])){
            $next_seq = intval($parts[1]) + 1;
        } else {
            $next_seq = 1;
        }
    } else {
        $next_seq = 1;
    }
    $batch_name = $current_year . ':' . $next_seq;
    
    // Get Price
    $profil = Rahmad($_POST['groupname']);
    $price_res = $Bsk->Show("radprice", "price", "groupname='$profil'");
    if($price_res){
        $unit_price = $price_res['price'];
    } else {
        $price_res = $Bsk->Show("radgroupreply", "value", "groupname='$profil' and attribute='Unit-Price'");
        $unit_price = $price_res ? $price_res['value'] : 0;
    }

    // Insert Batch
    $batch_data = array(
        "batch_name" => $batch_name,
        "profile" => $profil,
        "unit_price" => $unit_price,
        "quantity" => $qty,
        "created_by" => ($Menu['username'] ?? ($Users['username'] ?? 'admin')),
        "expiration_date" => (isset($_POST['expiration']) && !empty($_POST['expiration'])) ? $_POST['expiration'] : date('Y-m-d', strtotime('+1 year'))
    );
    $Bsk->Insert("card_batches", $batch_data);
    $batch_info = $Bsk->Show("card_batches", "id", "batch_name='$batch_name'");
    $batch_id = ($batch_info && isset($batch_info['id'])) ? $batch_info['id'] : 0;
    // -------------------------------------------------------------

    for($o=0; $o<$qty; $o++){
        // Generate Serial Number
        $serial_number = $batch_name . '-' . str_pad($o+1, strlen($qty), '0', STR_PAD_LEFT);

        $batch_user = $prf.random_str($lng, $crt);
        $batch_pswd = ($p_size > 0 ? random_str($p_size, $crt) : '');
        $mode = ($mod == 'true' ? $batch_pswd : ($mod == 'username_only' ? '' : $batch_user));
        $post_qty = array(
            "identity"   => $Menu['identity'],
            "users"      => $Menu['id'],
            "username"   => $batch_user,
            "attribute"  => "Cleartext-Password", 
            "op"         => ":=",
            "value"      => $mode,
            "description"=> $_POST['description'],
            "created"    => $_POST['created']
        );
        $add_batch = true;
        if($mod !== 'username_only' && $p_size > 0){
            $add_batch = $Bsk->Insert("radcheck", $post_qty);
        } else {
            $Bsk->Delete("radcheck", array("username"=>$batch_user, "attribute"=>"Cleartext-Password"));
        }
        $Bsk->Insert("userinfo", array(
            "username" => $batch_user,
            "creationby" => ($Menu['username'] ?? 'admin'),
            "creationdate" => date('Y-m-d H:i:s'),
            // Add Batch Info
            "batch_id" => $batch_id,
            "serial_number" => $serial_number,
            "unit_price" => $unit_price,
            "firstname" => "Batch $batch_name",
            "lastname" => "Serial $serial_number"
        ));
        $Bsk->Insert("radusergroup", 
            array(
                "identity"  => $Menu['identity'],
                "users"     => $Menu['id'],
                "username"  => $batch_user,
                "groupname" => Rahmad($_POST['groupname'])
            )
        );
        if(isset($_POST['expiration']) && !empty($_POST['expiration'])){
             $Bsk->Insert("radcheck", array(
                "identity"   => $Menu['identity'],
                "users"      => $Menu['id'],
                "username"   => $batch_user,
                "attribute"  => "Expiration", 
                "op"         => ":=",
                "value"      => $_POST['expiration'], // Format: YYYY-MM-DD HH:MM:SS or similar
                "description"=> $_POST['description'],
                "created"    => $_POST['created']
            ));
        }
        if($nas_ip){
            $Bsk->Delete("radcheck", array("username"=>$batch_user, "attribute"=>"NAS-IP-Address"));
            $Bsk->Insert("radcheck", array(
                "identity"   => $Menu['identity'],
                "users"      => $Menu['id'],
                "username"   => $batch_user,
                "attribute"  => "NAS-IP-Address",
                "op"         => "==",
                "value"      => $nas_ip,
                "description"=> $_POST['description'],
                "created"    => $_POST['created']
            ));
        }
        if($add_batch){ 
            $success++; 
        } else {
            $failed++;
        }
    }
    echo json_encode($qty ? 
		array("status" => true, "message" => "success", "color" => "green", "data" => "Batch data success ".$success." & failed ".$failed) : 
		array("status" => false, "message" => "error", "color" => "red", "data" => "Batch data failed!"), true
	);
}

if(isset($_GET['print'])){
    $number = 0;
    $array_print = array();
    $data_print  = array();
    $id_print    = Rahmad($_GET['print']);
    $type_print  = Rahmad($_GET['type']);
    $type_theme  = (empty($_GET['themes']) ? "" : "and a.id = '".Rahmad($_GET['themes'])."'");
    $where_print = ($type_print == 'batch' ? "and a.created = '$id_print'" : "and a.username = '$id_print'"); 
    $query_theme = $Bsk->Show("themes a", "a.content", "a.identity = '$Menu[identity]' and a.type = 'radius' $radius ".$type_theme, "a.id asc");
    $batch_print = $Bsk->Select("print a", "*", "a.identity = '$Menu[identity]' $radius $where_print" );
    foreach ($batch_print as $value_print) {
        $number++;
        $array_print[] = $value_print;
        $data_print[] = HTMLReplace($query_theme['content'],
            array_replace(
                $value_print, 
                array(
                    "no"        => $number,
                    "period"    => secTime($value_print['period']),
                    "times"     => secTime($value_print['times']),
                    "daily"     => secTime($value_print['daily']),
                    "price"     => Money($value_print['price'], $Config['currency']),
                    "qr_code"   => '<div class="qr-code" data-code="'.$value_print['qr_code'].'"></div>'
                )
            )
        );
    }
    echo json_encode($array_print ? 
		array("status" => true, "message" => "success", "color" => "green", "themes" => $query_theme, "print" => $data_print, "data" => $array_print) : 
		array("status" => false, "message" => "error", "color" => "red", "themes" => false, "data" => "Print data failed!"), true
	);
}
if(isset($_POST['import'])){
    $valid = 0; $failed = 0;
    if(isset($_FILES['file']) && $_FILES['file']['name']!=""){
        try {
            $excelFile = $_FILES['file']['tmp_name'];
            $excelRead = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($excelFile);
            $excelObjc = $excelRead->load($excelFile);
            $worksheet = $excelObjc->getSheet(0);
            $excelRows = $worksheet->getHighestRow();
            for ($x = 2; $x <= $excelRows; $x++){
                $excelData1 = $worksheet->getCell('A'.$x)->getValue();
                $excelData2 = $worksheet->getCell('B'.$x)->getValue();
                
                if(!$excelData1) continue;

                $checkQuery = $Bsk->Show("radcheck", "*", "username = '$excelData1'");
                if(!$checkQuery){
                    $postQuery = array(
                        "identity"   => $Menu['identity'],
                        "users"      => $Menu['id'],
                        "username"   => $excelData1,
                        "attribute"  => "Cleartext-Password", 
                        "op"         => ":=",
                        "value"      => $excelData2,
                        "description"=> $_POST['description'],
                        "created"    => $_POST['created']
                    );
                    $gruopQuery = array(
                        "identity"  => $Menu['identity'],
                        "users"     => $Menu['id'],
                        "username"  => $excelData1,
                        "groupname" => Rahmad($_POST['groupname'])
                    );
                    $Bsk->Insert("radcheck", $postQuery);
                    if(!empty($_POST['groupname'])){
                        $Bsk->Insert("radusergroup", $gruopQuery);
                    }
                    $valid++;
                } else {
                    $failed++;
                }
            }
            echo json_encode(
                array("status" => true, "message" => "success", "color" => "green", "data" => "Import data success ".$valid." & failed ".$failed)
            );
        } catch(Exception $e) {
            echo json_encode(array("status" => false, "message" => "error", "color" => "red", "data" => "Import failed: " . $e->getMessage()));
        }
    } else {
         echo json_encode(array("status" => false, "message" => "error", "color" => "red", "data" => "No file uploaded"));
    }
    exit;
}
?>
