<?php
// Drag-and-drop voucher template API
// Stores designer templates as JSON with background image metadata

require_once "../include/config.php";
require_once "../include/autoload.php";
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

$Bsk      = new Connect();
$Header   = getallheaders();
$Auth     = new Cipher('aes-256-ecb');
$Host     = (isset($Header['Token'])? Rahmad($Header['Token']) : (isset($Header['token']) ? Rahmad($Header['Token']) : md5($_SERVER['SERVER_NAME'])));
$Api      = (isset($Header['Api'])? $Auth->decrypt($Header['Api'], $Host) : (isset($Header['api']) ? $Auth->decrypt($Header['api'], $Host) : false));
$Key      = (isset($Header['Key'])? $Auth->decrypt($Header['Key'], $Host) : (isset($Header['key']) ? $Auth->decrypt($Header['key'], $Host) : false));

$Identity = $Bsk->Show("identity",  "*", "status = 'true'");
$Config   = $Bsk->Show("config",    "*", "id = '$Identity[id]' ");
$Users    = $Bsk->Show("users",     "*", "id = '$Api' and md5(pswd) = '$Key' and status = 'true'");
$Menu     = $Bsk->Show("access",    "*", "id = '$Api' and md5(pswd) = '$Key' and identity = '$Identity[id]'");

// Storage: filesystem only - templates stored as JSON files in dist/templates/
$templateDir = dirname(__FILE__)."/../dist/templates";
if(!is_dir($templateDir)) mkdir($templateDir, 0775, true);

// Resolve identity/users even if $Menu is not available
$identity_id = isset($Menu['identity']) ? $Menu['identity'] : (isset($Users['identity']) ? $Users['identity'] : (isset($Identity['id']) ? $Identity['id'] : 0));
$user_id = isset($Menu['id']) ? $Menu['id'] : (isset($Users['id']) ? $Users['id'] : 0);

// List templates
if(isset($_GET['list'])){
  $list = array();
  $files = glob($templateDir."/*.json");
  foreach($files as $file){
    $content = file_get_contents($file);
    $data = json_decode($content, true);
    if($data && isset($data['id'])){
      $list[] = array(
        'id' => $data['id'],
        'name' => $data['name'],
        'width' => $data['width'],
        'height' => $data['height'],
        'background' => $data['background'] ?? ''
      );
    }
  }
  echo json_encode(array("status"=>true, "data"=>$list), true);
  exit;
}

// Get detail
if(isset($_GET['detail'])){
  $id = Rahmad($_GET['detail']);
  $file = $templateDir."/".$id.".json";
  if(file_exists($file)){
    $content = file_get_contents($file);
    $data = json_decode($content, true);
    if($data){ echo json_encode(array("status"=>true, "data"=>$data), true); }
    else { echo json_encode(array("status"=>false), true); }
  } else {
    echo json_encode(array("status"=>false), true);
  }
  exit;
}

// Save (create/update)
if(isset($_POST['name'])){
  $id   = isset($_POST['id']) ? Rahmad($_POST['id']) : '';
  $name = Rahmad($_POST['name']);
  $width = intval($_POST['width'] ?? 600);
  $height = intval($_POST['height'] ?? 350);
  $layout = $_POST['layout'] ?? '{}';
  $bgPath = '';

  // Handle background upload (optional)
  if(!empty($_FILES['background']['name'])){
    $fname = time().'_'.preg_replace('/[^A-Za-z0-9._-]/','_', $_FILES['background']['name']);
    $destDir = dirname(__FILE__)."/../dist/img/templates";
    if(!is_dir($destDir)) mkdir($destDir, 0775, true);
    $dest = $destDir."/".$fname;
    if(move_uploaded_file($_FILES['background']['tmp_name'], $dest)){
      $bgPath = "dist/img/templates/".$fname;
    }
  } else if(!empty($_POST['background'])){
    $b = $_POST['background'];
    if(strpos($b, 'data:image') === 0){
      $m = array();
      if(preg_match('#^data:image/(\w+);base64,(.*)$#', $b, $m)){
        $ext = strtolower($m[1]);
        $data = base64_decode($m[2]);
        $fname = time().'_bg.'.($ext ?: 'png');
        $destDir = dirname(__FILE__)."/../dist/img/templates";
        if(!is_dir($destDir)) mkdir($destDir, 0775, true);
        $dest = $destDir."/".$fname;
        file_put_contents($dest, $data);
        $bgPath = "dist/img/templates/".$fname;
      }
    } else {
      $bgPath = Rahmad($b);
    }
  }

  if(!$id){ $id = time(); }
  // keep previous background if not provided
  if(!$bgPath){
    $prevFile = $templateDir."/".$id.".json";
    if(file_exists($prevFile)){
      $prevContent = file_get_contents($prevFile);
      $prevData = json_decode($prevContent, true);
      if($prevData && isset($prevData['background'])){ 
        $bgPath = $prevData['background']; 
      }
    }
  }

  $data = array(
    "id" => $id,
    "identity" => $identity_id,
    "users" => $user_id,
    "name" => $name,
    "background" => $bgPath,
    "width" => $width,
    "height" => $height,
    "layout" => $layout,
    "status" => "true",
    "created" => date("Y-m-d H:i:s")
  );

  // Filesystem storage only
  $file = $templateDir."/".$id.".json";
  $ok = file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));

  if($ok !== false){
    echo json_encode(array("status"=>true, "id"=>$id, "background"=>$bgPath), true);
  } else {
    echo json_encode(array("status"=>false, "message"=>"Save failed in filesystem"), true);
  }
  exit;
}

// Delete
if(isset($_POST['delete'])){
  $id = Rahmad($_POST['delete']);
  
  // Delete from filesystem only
  $file = $templateDir."/".$id.".json";
  $fsOk = false;
  if(file_exists($file)){
    $fsOk = unlink($file);
  }
  
  echo json_encode(array("status"=>true, "fs_deleted"=>$fsOk), true);
  exit;
}

echo json_encode(array("status"=>false, "message"=>"Invalid request"));
?>