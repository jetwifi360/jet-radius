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

// DB-only storage: ensure voucher_templates table exists
$Bsk->exec("CREATE TABLE IF NOT EXISTS voucher_templates (
  id INT PRIMARY KEY,
  identity INT,
  users INT,
  name VARCHAR(255),
  background VARCHAR(255),
  width INT,
  height INT,
  layout LONGTEXT,
  status VARCHAR(10),
  created DATETIME
)");

// Resolve identity/users even if $Menu is not available
$identity_id = isset($Menu['identity']) ? $Menu['identity'] : (isset($Users['identity']) ? $Users['identity'] : (isset($Identity['id']) ? $Identity['id'] : 0));
$user_id = isset($Menu['id']) ? $Menu['id'] : (isset($Users['id']) ? $Users['id'] : 0);

// List templates
if(isset($_GET['list'])){
  $list = array();
  $dbTemplates = $Bsk->Select("voucher_templates", "id, name, width, height, background", 
    "identity = '$identity_id' and (users = '$user_id' or users = 0) and status = 'true'", "id desc");
  foreach($dbTemplates as $dbTemplate){
    $list[] = array(
      'id' => $dbTemplate['id'],
      'name' => $dbTemplate['name'],
      'width' => $dbTemplate['width'],
      'height' => $dbTemplate['height'],
      'background' => $dbTemplate['background'] ?? ''
    );
  }
  echo json_encode(array("status"=>true, "data"=>$list), true);
  exit;
}

// Get detail
if(isset($_GET['detail'])){
  $id = Rahmad($_GET['detail']);
  $dbTemplate = $Bsk->Show("voucher_templates", "*", "id = '$id' and identity = '$identity_id' and (users = '$user_id' or users = 0) and status = 'true'");
  if($dbTemplate){
    echo json_encode(array("status"=>true, "data"=>$dbTemplate), true);
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

  // DB-only storage

  // Database storage for voucher system integration
  $dbData = array(
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
  
  // Check if template already exists in database
  $existing = $Bsk->Show("voucher_templates", "id", "id = '$id' and identity = '$identity_id' and users = '$user_id'");
  if($existing){
    $dbOk = $Bsk->Change("voucher_templates", $dbData, "id = '$id' and identity = '$identity_id' and users = '$user_id'");
  } else {
    $dbOk = $Bsk->Insert("voucher_templates", $dbData);
  }

  if($dbOk !== false){
    echo json_encode(array("status"=>true, "id"=>$id, "background"=>$bgPath), true);
  } else {
    echo json_encode(array("status"=>false, "message"=>"Save failed"), true);
  }
  exit;
}

// Migrate filesystem templates to DB
if(isset($_POST['migrate'])){
  $templateDir = dirname(__FILE__)."/../dist/templates";
  $migrated = 0; $failed = 0;
  if(is_dir($templateDir)){
    foreach(glob($templateDir."/*.json") as $file){
      $content = file_get_contents($file);
      $data = json_decode($content, true);
      if(!$data || !isset($data['id'])){ $failed++; continue; }
      $id = Rahmad($data['id']);
      $dbData = array(
        "id" => $id,
        "identity" => $identity_id,
        "users" => $user_id,
        "name" => $data['name'] ?? ('Template '.$id),
        "background" => $data['background'] ?? '',
        "width" => intval($data['width'] ?? 600),
        "height" => intval($data['height'] ?? 350),
        "layout" => isset($data['layout']) ? (is_array($data['layout']) ? json_encode($data['layout']) : $data['layout']) : '[]',
        "status" => "true",
        "created" => date("Y-m-d H:i:s")
      );
      $existing = $Bsk->Show("voucher_templates", "id", "id = '$id' and identity = '$identity_id' and users = '$user_id'");
      $ok = $existing ? $Bsk->Change("voucher_templates", $dbData, "id = '$id' and identity = '$identity_id' and users = '$user_id'") : $Bsk->Insert("voucher_templates", $dbData);
      if($ok !== false){ $migrated++; } else { $failed++; }
    }
  }
  echo json_encode(array("status"=>true, "migrated"=>$migrated, "failed"=>$failed), true);
  exit;
}

// Delete
if(isset($_POST['delete'])){
  $id = Rahmad($_POST['delete']);
  $dbOk = $Bsk->exec("DELETE FROM voucher_templates WHERE id = '".$id."' AND identity = '".$identity_id."' AND (users = '".$user_id."' OR users = 0)");
  echo json_encode(array("status"=>true, "db_deleted"=>($dbOk!==false)), true);
  exit;
}

echo json_encode(array("status"=>false, "message"=>"Invalid request"));
?>