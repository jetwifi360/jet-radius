<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

$mock = isset($_REQUEST['mock']) ? (int)$_REQUEST['mock'] : 0;
if($mock){
    echo json_encode(array(
        "status"=>true,
        "data"=>array(
            "hotspot_profiles" => array("default","main","caferoom"),
            "address_pools"    => array("main_pool","caferoom_pool"),
            "hotspot_servers"  => array("main","caferoom"),
            "queue_types"      => array("default","only-hardware-queue","pcq-download-default")
        ),
        "message"=>""
    ), true);
    exit;
}

require_once "../include/config.php";
require_once "../include/autoload.php";

$Bsk    = new Connect();
$Router = new MikroTik();
$Auth   = new Cipher('aes-256-ecb');
$Header = getallheaders();
$Host   = (isset($Header['Token'])? Rahmad($Header['Token']) : (isset($Header['token']) ? Rahmad($Header['Token']) : md5($_SERVER['SERVER_NAME'])));
$Api    = (isset($Header['Api'])? $Auth->decrypt($Header['Api'], $Host) : (isset($Header['api']) ? $Auth->decrypt($Header['api'], $Host) : false));
$Key    = (isset($Header['Key'])? $Auth->decrypt($Header['Key'], $Host) : (isset($Header['key']) ? $Auth->decrypt($Header['key'], $Host) : false));

$Identity = $Bsk->Show("identity",  "*", "status = 'true'");
$Config   = $Bsk->Show("config",    "*", "id = '$Identity[id]' ");
$Users    = $Bsk->Show("users",     "*", "id = '$Api' and md5(pswd) = '$Key' and status = 'true'");
$Menu     = $Bsk->Show("access",    "*", "id = '$Api' and md5(pswd) = '$Key' and identity = '$Identity[id]'");

$result = array("status"=>false, "data"=>array(), "message"=>"");

$ip = isset($_REQUEST['ip']) ? trim($_REQUEST['ip']) : '';
$username = isset($_REQUEST['username']) ? trim($_REQUEST['username']) : '';
$password = isset($_REQUEST['password']) ? $_REQUEST['password'] : '';
$port = isset($_REQUEST['port']) ? (int)$_REQUEST['port'] : 0;
$nas_id = isset($_REQUEST['nas_id']) ? trim($_REQUEST['nas_id']) : '';
$nas_ip = isset($_REQUEST['nas_ip']) ? trim($_REQUEST['nas_ip']) : '';
$mock = 0;

try {
    $targetHost = '';
    $targetUser = '';
    $targetPass = '';
    $targetPort = 0;

    if($ip && $username && $password){
        $targetHost = $ip;
        $targetUser = $username;
        $targetPass = $password;
        $targetPort = $port;
    } else {
        $nas = false;
        if($nas_id){
            $nas = $Bsk->Show("nas", "*", "id='".Rahmad($nas_id)."'" );
        } else if($nas_ip){
            $nas = $Bsk->Show("nas", "*", "nasname='".Rahmad($nas_ip)."'" );
            if(!$nas){
                $nas = $Bsk->Show("nas", "*", "nasname like '".Rahmad($nas_ip)."%'" );
            }
        }
        if(!$nas){
            if($Menu){
                $nas = $Bsk->Show("nas", "*", "status='true' and identity = '$Menu[identity]' and users = '$Menu[id]'" );
                if(!$nas){
                    $nas = $Bsk->Show("nas", "*", "identity = '$Menu[identity]' and users = '$Menu[id]'" );
                }
            }
            if(!$nas){
                $nas = $Bsk->Show("nas", "*", "status='true'" );
            }
            if(!$nas){
                $nas = $Bsk->Show("nas", "*", "id > 0" );
            }
        }
        if($nas){
            $targetHost = $nas['nasname'];
            $targetUser = $nas['username'];
            $targetPass = $Auth->decrypt($nas['password'], 'BSK-RAHMAD');
            $targetPort = (int)($nas['port'] ?: 0);
        }
    }

    if(!$targetHost || !$targetUser || !$targetPass){
        $result['message'] = 'Missing RouterOS credentials';
        echo json_encode($result, true);
        exit;
    }

    if($targetPort){
        $Router->port = $targetPort;
    }

    if($Router->connect($targetHost, $targetUser, $targetPass)){
        $hs_profiles = $Router->comm("/ip/hotspot/profile/print");
        $pools = $Router->comm("/ip/pool/print");
        $hs_servers = $Router->comm("/ip/hotspot/print");
        $queue_types = $Router->comm("/queue/type/print");

        $result['status'] = true;
        $result['data'] = array(
            "hotspot_profiles" => array_values(array_filter(array_map(function($p){ return isset($p['name']) ? $p['name'] : ''; }, is_array($hs_profiles)?$hs_profiles:array()))),
            "address_pools"    => array_values(array_filter(array_map(function($p){ return isset($p['name']) ? $p['name'] : ''; }, is_array($pools)?$pools:array()))),
            "hotspot_servers"  => array_values(array_filter(array_map(function($s){ return isset($s['name']) ? $s['name'] : (isset($s['server']) ? $s['server'] : ''); }, is_array($hs_servers)?$hs_servers:array()))),
            "queue_types"      => array_values(array_filter(array_map(function($q){ return isset($q['name']) ? $q['name'] : ''; }, is_array($queue_types)?$queue_types:array())))
        );
        $Router->disconnect();
    } else {
        $result['message'] = 'RouterOS connect failed';
    }
} catch (Exception $e){
    $result['message'] = $e->getMessage();
}

echo json_encode($result, true);
?>