<?php
// Enable logging
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', '/tmp/profiles_debug.log');

require_once "../include/autoload.php";

if(isset($_GET['routeros'])){
    header("Content-Type: application/json");
    $Router = new MikroTik();
    $Auth = new Cipher('aes-256-ecb');
    $result = array("status"=>false, "data"=>array(), "message"=>"");
    try{
        $nas = $Bsk->Show("nas", "*", "status='true' and identity = '$Menu[identity]' and users = '$Menu[id]'" );
        if(!$nas){
            $nas = $Bsk->Show("nas", "*", "identity = '$Menu[identity]' and users = '$Menu[id]'" );
        }
        if($nas){
            if(!empty($nas['port'])){ $Router->port = $nas['port']; }
            $pass = $Auth->decrypt($nas['password'], 'BSK-RAHMAD');
            if($Router->connect($nas['nasname'], $nas['username'], $pass)){
                $hs_profiles = $Router->comm("/ip/hotspot/profile/print");
                $pools = $Router->comm("/ip/pool/print");
                $result["status"] = true;
                $result["data"] = array(
                    "hotspot_profiles" => array_map(function($p){ return $p['name'] ?? ''; }, is_array($hs_profiles)?$hs_profiles:array()),
                    "address_pools"    => array_map(function($p){ return $p['name'] ?? ''; }, is_array($pools)?$pools:array())
                );
                $Router->disconnect();
            } else {
                $result["message"] = "RouterOS connect failed";
            }
        } else {
            $result["message"] = "No NAS configured";
        }
    } catch(Exception $e){
        $result["message"] = $e->getMessage();
    }
    echo json_encode($result);
    exit;
}

try {
    if(isset($_GET['data'])){
        $table = $Bsk->Table(
            "profiles",  "groupname as id, groupname, concat(shared, ' ', ppp) as shared, rate, price, discount, quota, period as expired, description", 
            "identity = '$Menu[identity]' and users = '$Menu[id]'", 
            array("groupname", "concat(shared, ' ', ppp)", "rate", "price", "discount", "id")
        );
        echo json_encode($table, true);
    }
    if(isset($_GET['detail'])){
        $id_detail = Rahmad($_GET['detail']);
        $show_detail = $Bsk->Show("profiles", "*", "groupname = '$id_detail' and identity = '$Menu[identity]' and users = '$Menu[id]'");
        $data_quota  = ($show_detail['quota'] ? preg_split('#(?<=\d)(?=[a-z])#i', $show_detail['quota']) : array('', 'B'));

        $framed_pool = $Bsk->Show(
            "radgroupreply", "value",
            "groupname = '$id_detail' and attribute = 'Framed-Pool' and identity = '$Menu[identity]' and users = '$Menu[id]'"
        );
        $hotspot_prof = $Bsk->Show(
            "radgroupreply", "value",
            "groupname = '$id_detail' and attribute = 'Mikrotik-Hotspot-Profile' and identity = '$Menu[identity]' and users = '$Menu[id]'"
        );

        $replace_data = array_replace(
            $show_detail,
            array(
                "quota_numb"=> $data_quota[0],
                "quota_code"=> $data_quota[1],
                "mt_address_pool" => ($framed_pool ? $framed_pool['value'] : ''),
                "mt_hotspot_profile" => ($hotspot_prof ? $hotspot_prof['value'] : '')
            )
        );
        echo json_encode($show_detail ?
            array("status" => true, "message" => "success", "data" => $replace_data) :
            array("status" => false, "message" => "error", "data" => false), true
        );
    }
    if(isset($_POST['groupname'])){
        $post_profile = Rahmad($_POST['id']);
        $groupname    = Rahmad($_POST['groupname']);
        
        // Sanitize description for all operations to prevent JSON crashes in Connect::Update
        $description  = isset($_POST['description']) ? Rahmad($_POST['description']) : '';
        
        // --- radgroupcheck Loop ---
        $count_check = isset($_POST['radgroupcheck']) ? count($_POST['radgroupcheck']) : 0;
        for($i=0; $i<$count_check; $i++){
            if (!isset($_POST['attribute'][$i])) continue;
            
            $attribute = $_POST['attribute'][$i];
            
            // Fetch existing record
            $check_check = $Bsk->Show(
                "radgroupcheck", "groupname", 
                "groupname = '$post_profile' and attribute = '$attribute' and identity = '$Menu[identity]'"
            );
            
            // Get raw value
            $raw_value = isset($_POST['radgroupcheck'][$i]) ? $_POST['radgroupcheck'][$i] : '';
            
            if($raw_value !== ''){
                // Determine value based on attribute type
                if ($attribute == 'Simultaneous-Use') {
                    // Integer, no conversion needed. Sanitize just in case.
                    $value_db = Rahmad($raw_value);

                    // Also set Port-Limit in radgroupreply for NAS enforcement support
                    $query_port_limit = array(
                        "identity"   => $Menu['identity'],
                        "users"      => $Menu['id'],
                        "groupname"  => $groupname,
                        "attribute"  => 'Port-Limit',
                        "op"         => ":=",
                        "value"      => $value_db,
                        "description"=> $description
                    );
                    $check_port = $Bsk->Show("radgroupreply", "groupname", "groupname = '$post_profile' and attribute = 'Port-Limit' and identity = '$Menu[identity]'");
                    if($check_port){
                        $Bsk->Update("radgroupreply", $query_port_limit, "groupname = '$check_port[groupname]' and attribute = 'Port-Limit' and identity = '$Menu[identity]'");
                    } else {
                        $Bsk->Insert("radgroupreply", $query_port_limit);
                    }
                } elseif ($attribute == 'Max-Data') {
                    // ByteConvert expects string
                    $value_db = ByteConvert($raw_value);
                } else {
                    // DateTime expects string
                    $value_db = DateTime($raw_value);
                }
                
                $query_check = array(
                    "identity"   => $Menu['identity'],
                    "users"      => $Menu['id'],
                    "groupname"  => $groupname, 
                    "attribute"  => $attribute,
                    "op"         => ":=",
                    "value"      => $value_db,
                    "description"=> $description
                );
                
                if($check_check){
                    $Bsk->Update("radgroupcheck", $query_check, "groupname = '$check_check[groupname]' and attribute = '$attribute' and identity = '$Menu[identity]' and users = '$Menu[id]'");
                } else {
                    $Bsk->Insert("radgroupcheck", $query_check);
                }
            } else {
                // Delete if empty and exists
                if($check_check){
                    $Bsk->Delete("radgroupcheck", array(
                        "groupname" => $check_check['groupname'],
                        "attribute" => $attribute,
                        "identity"  => $Menu['identity'],
                        "users"     => $Menu['id']
                    ));
                }
                if ($attribute == 'Simultaneous-Use') {
                     $Bsk->Delete("radgroupreply", array(
                        "groupname" => $post_profile,
                        "attribute" => 'Port-Limit',
                        "identity"  => $Menu['identity'],
                        "users"     => $Menu['id']
                    ));
                }
            }
        }
        
        // --- radgroupreply Loop ---
        $count_reply = isset($_POST['radgroupreply']) ? count($_POST['radgroupreply']) : 0;
        for($e=0; $e<$count_reply; $e++){
            if (!isset($_POST['attribut'][$e])) continue; // Note spelling 'attribut'
            
            $attribut = $_POST['attribut'][$e];
            
            $check_reply = $Bsk->Show(
                "radgroupreply", "groupname", 
                "groupname = '$post_profile' and attribute = '$attribut' and identity = '$Menu[identity]' and users = '$Menu[id]'"
            );
            
            $raw_value = isset($_POST['radgroupreply'][$e]) ? $_POST['radgroupreply'][$e] : '';
            
            if($raw_value !== ''){
                if ($attribut == 'Mikrotik-Total-Limit') {
                     $value_db = ByteConvert($raw_value);
                } else {
                     // For other reply attributes (PPP, Rate Limit), sanitize string
                     $value_db = Rahmad($raw_value);
                }

                $query_reply = array(
                    "identity"   => $Menu['identity'],
                    "users"      => $Menu['id'],
                    "groupname"  => $groupname,
                    "attribute"  => $attribut, 
                    "op"         => ":=",
                    "value"      => $value_db,
                    "description"=> $description
                );
                
                if($check_reply){
                    $Bsk->Update("radgroupreply", $query_reply, "groupname = '$check_reply[groupname]' and attribute = '$attribut' and identity = '$Menu[identity]' and users = '$Menu[id]'");
                } else {
                    $Bsk->Insert("radgroupreply", $query_reply);
                }
            } else {
                if($check_reply){
                    $Bsk->Delete("radgroupreply", array(
                        "groupname" => $check_reply['groupname'],
                        "attribute" => $attribut,
                        "identity"  => $Menu['identity'],
                        "users"     => $Menu['id']
                    ));
                }
            }
        }
        
        // --- Price ---
        $check_price = $Bsk->Show("radprice", "groupname", "groupname = '$post_profile' and identity = '$Menu[identity]' and users = '$Menu[id]'");
        
        if(!empty($_POST['price'])){
            $price_val = Rahmad($_POST['price']);
            
            // Fix for "Incorrect integer value: '' for column 'discount'"
            // Check if discount is set and not empty, otherwise default to 0
            $discount_raw = isset($_POST['discount']) ? $_POST['discount'] : '';
            $discount_val = ($discount_raw === '') ? 0 : Rahmad($discount_raw);
            
            if ($check_price) {
                $Bsk->Update("radprice", 
                    array(
                        "groupname" => $groupname,
                        "price"     => $price_val,
                        "discount"  => $discount_val
                    ),
                    "groupname = '$check_price[groupname]' and identity = '$Menu[identity]' and users = '$Menu[id]'"
                );
            } else {
                $Bsk->Insert("radprice", 
                    array(
                        "groupname" => $groupname,
                        "price"     => $price_val,
                        "discount"  => $discount_val,
                        "identity"  => $Menu['identity'],
                        "users"     => $Menu['id']
                    )
                );
            }
        } else {
            if ($check_price) {
                $Bsk->Delete("radprice", array("groupname" => $post_profile, "identity" => $Menu['identity'], "users" => $Menu['id']));
            }
        }
    
        // Update radusergroup if profile name changes
        if($post_profile != $groupname && $post_profile != '-1' && !empty($post_profile)){
            $Bsk->Update("radusergroup", 
                array("groupname" => $groupname), 
                "groupname = '$post_profile' and identity = '$Menu[identity]' and users = '$Menu[id]'"
            );
        }
    
        echo json_encode($groupname ? 
            array("status" => true, "message" => "success", "color" => "green", "data" => "Process data success") : 
            array("status" => false, "message" => "error", "color" => "red", "data" => "Process data failed!"), true
        );
    }
    if(isset($_POST['delete'])){
        $id_delete = Rahmad($_POST['delete']);
        $check_delete = $Bsk->Delete("radgroupcheck", array("groupname" => $id_delete, "identity" => $Menu['identity'], "users" => $Menu['id']));
        $Bsk->Delete("radgroupreply", array("groupname" => $id_delete, "identity" => $Menu['identity'], "users" => $Menu['id']));
        $Bsk->Delete("radusergroup", array("groupname" => $id_delete, "identity" => $Menu['identity'], "users" => $Menu['id']));
        $Bsk->Delete("radprice", array("groupname" => $id_delete, "identity" => $Menu['identity'], "users" => $Menu['id']));
        echo json_encode($check_delete ? 
            array("status" => true, "message" => "success", "color" => "green", "data" => "Delete data success") : 
            array("status" => false, "message" => "error", "color" => "red", "data" => "Delete data failed!"), true
        );
    }
} catch (Exception $e) {
    error_log("Profile Error: " . $e->getMessage());
    echo json_encode(array("status" => false, "message" => "error", "color" => "red", "data" => "System Error: " . $e->getMessage()));
}
?>