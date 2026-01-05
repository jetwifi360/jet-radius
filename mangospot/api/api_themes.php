<?php
require_once "../include/config.php";
require_once "../include/autoload.php";
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

function themes_log($msg){
    $line = '['.date('Y-m-d H:i:s').'] '.(is_string($msg)?$msg:json_encode($msg));
    @file_put_contents('/tmp/themes_api.log', $line."\n", FILE_APPEND);
}
set_exception_handler(function($e){ themes_log('EXCEPTION: '.$e->getMessage()); echo json_encode(array("status"=>false, "message"=>"error", "data"=>"Server exception"), true); exit; });
set_error_handler(function($errno,$errstr,$errfile,$errline){ themes_log('ERROR '.$errno.': '.$errstr.' @ '.$errfile.':'.$errline); echo json_encode(array("status"=>false, "message"=>"error", "data"=>"Server error"), true); exit; });

$Bsk      = new Connect();
$Header   = getallheaders();
$Auth     = new Cipher('aes-256-ecb');
$Host     = (isset($Header['Token'])? Rahmad($Header['Token']) : (isset($Header['token']) ? Rahmad($Header['Token']) : md5($_SERVER['SERVER_NAME'])));
$Api      = (isset($Header['Api'])? $Auth->decrypt($Header['Api'], $Host) : (isset($Header['api']) ? $Auth->decrypt($Header['api'], $Host) : false));
$Key      = (isset($Header['Key'])? $Auth->decrypt($Header['Key'], $Host) : (isset($Header['key']) ? $Auth->decrypt($Header['key'], $Host) : false));

// Fallback: read Api/Key from cookies if headers missing
if(!$Api || !$Key){
    if(isset($_COOKIE['BSK_API']) && isset($_COOKIE['BSK_KEY'])){
        $Api = $Auth->decrypt($_COOKIE['BSK_API'], $Host);
        $Key = $Auth->decrypt($_COOKIE['BSK_KEY'], $Host);
    }
}

$Identity = $Bsk->Show("identity",  "*", "status = 'true'");
$Config   = $Bsk->Show("config",    "*", "id = '$Identity[id]' ");
$Users    = $Bsk->Show("users",     "*", "id = '$Api' and md5(pswd) = '$Key' and status = 'true'");
$Menu     = $Bsk->Show("access",    "*", "id = '$Api' and md5(pswd) = '$Key' and identity = '$Identity[id]'");

if(!$Users || !$Menu){
    themes_log(array('auth'=>'fail','headers'=>getallheaders()));
    echo json_encode(array("status"=>false, "message"=>"error", "data"=>"Unauthorized"), true);
    exit;
}

if(isset($_GET['type'])){
    $filter = isset($_GET['type']) && $_GET['type'] !== '' ? Rahmad($_GET['type']) : 'radius';
    $list = array();
    $where = "identity = '$Menu[identity]' and (users = '$Menu[id]' or users = 0)";
    $where .= ($filter === 'all' ? "" : " and type = '$filter'");
    $query = $Bsk->Select("themes", "id, name", $where, "id desc");
    foreach ($query as $row) { $list[] = $row; }
    echo json_encode($list ? 
          array("status" => true, "message" => "success", "data" => $list) : 
          array("status" => false, "message" => "error", "data" => false), true
    );
}
if(isset($_GET['docs'])){
    $document = array();
    $docs = $Bsk->Select("type", "name, info", "type = 'radius' and status = 'true'", "id asc");
    foreach ($docs as $vals) {
        $document[] = $vals;
    }
    // Add Manual Placeholders
    $document[] = array("name" => "[profile]", "info" => "Profile Name");
    $document[] = array("name" => "[data]", "info" => "Data Limit");
    $document[] = array("name" => "[price]", "info" => "Unit Price");
    $document[] = array("name" => "[username]", "info" => "Username/Code");
    $document[] = array("name" => "[password]", "info" => "Password");
    $document[] = array("name" => "[serial_number]", "info" => "Serial Number");
    $document[] = array("name" => "[qr_code]", "info" => "QR Code");
    $document[] = array("name" => "[created]", "info" => "Creation Date");
    echo json_encode($document ? 
        array("status" => true, "message" => "success", "data" => $document) : 
        array("status" => false, "message" => "error", "data" => false), true
    );
}
if(isset($_GET['detail'])){
    $id_detail = Rahmad($_GET['detail']);
    $query_detail = $Bsk->Show("themes", "*", "id = '$id_detail' and identity = '$Menu[identity]' and (users = '$Menu[id]' or users = 0)");
    echo json_encode($query_detail ? 
		  array("status" => true, "message" => "success", "data" => $query_detail) : 
		  array("status" => false, "message" => "error", "data" => false), true
	);
}
if(isset($_POST['name'])){
    try {
        themes_log(array('post'=>$_POST,'user'=>$Users['id'],'menu'=>$Menu['id']));
        $id_post = isset($_POST['id']) ? Rahmad($_POST['id']) : 0;
        $name = trim($_POST['name']);
        $type = isset($_POST['type']) && $_POST['type'] !== '' ? trim($_POST['type']) : 'radius';
        $content = isset($_POST['content']) ? $_POST['content'] : '';
        $payload = array(
            "name" => $name,
            "type" => $type,
            "content" => $content,
            "status" => 'true',
            "created" => date('Y-m-d H:i:s')
        );
        $check_post = ($id_post ? $Bsk->Show("themes", "id", "id = '$id_post' and identity = '$Menu[identity]' and users = '$Menu[id]'") : false);
        $ok = ($check_post ? 
            $Bsk->Update("themes", $payload, "id = '$check_post[id]' ") : 
            $Bsk->Insert("themes", array_merge($payload, array("identity" => $Menu['identity'], "users" => $Menu['id'])))
        );
        themes_log(array('result'=>$ok ? 'ok' : 'fail'));
        echo json_encode($ok ? 
              array("status" => true, "message" => "success", "color" => "green", "data" => "Proccess data success") : 
              array("status" => false, "message" => "error", "color" => "red", "data" => "Proccess data failed!"), true
        );
    } catch(Exception $e){
        themes_log('CATCH: '.$e->getMessage());
        echo json_encode(array("status"=>false, "message"=>"error", "color"=>"red", "data"=>"Exception: ".$e->getMessage()), true);
    }
}
// List available theme types for UI dropdowns
if(isset($_GET['types'])){
    $types = array(
        array('value'=>'radius','label'=>'Radius'),
        array('value'=>'forgot','label'=>'Forgot'),
        array('value'=>'register','label'=>'Register'),
        array('value'=>'verification','label'=>'Verification'),
        array('value'=>'order','label'=>'Order'),
        array('value'=>'payment','label'=>'Payment'),
        array('value'=>'delivery','label'=>'Delivery')
    );
    echo json_encode(array("status"=>true, "message"=>"success", "data"=>$types), true);
}
if(isset($_GET['fixnull'])){
    $affected = $Bsk->Update("themes", array("type"=>"radius"), "(type IS NULL or type = '') and identity = '$Menu[identity]' and (users = '$Menu[id]' or users = 0)");
    echo json_encode($affected ? 
        array("status"=>true, "message"=>"success", "data"=>"Updated") : 
        array("status"=>false, "message"=>"error", "data"=>"No changes"), true);
}
if(isset($_POST['delete'])){
    $query_delete = $Bsk->Delete("themes", array("id" => Rahmad($_POST['delete']), "identity" => $Menu['identity'], "users" => $Menu['id']));
    echo json_encode($query_delete ? 
		  array("status" => true, "message" => "success", "color" => "green", "data" => "Delete data success") : 
		  array("status" => false, "message" => "error", "color" => "red", "data" => "Delete data failed!"), true
	);
}