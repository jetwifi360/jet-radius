<?php
header("Content-Type: application/json");
require_once "/var/www/html/mangospot/include/config.php";
require_once "/var/www/html/mangospot/include/mikrotik.php";
require_once "/var/www/html/mangospot/include/cipher.php";
$Bsk = new Connect();
$Auth = new Cipher("aes-256-ecb");
$API = new Mikrotik();
$payload = json_decode(file_get_contents("php://input"), true);
if (!$payload) { http_response_code(400); echo json_encode(["status"=>"error","message"=>"Invalid JSON"]); exit; }
// hotspot-server value is "ip|server_name"
$hs = isset($payload["hotspot-server"]) ? explode("|", $payload["hotspot-server"]) : [];
if (count($hs) != 2) { http_response_code(400); echo json_encode(["status"=>"error","message"=>"Invalid hotspot server selection"]); exit; }
$nas_ip = $hs[0];
$server_name = $hs[1];
// fetch router credentials
$stmt = $Bsk->prepare("SELECT username, password FROM nas WHERE nasname = ? LIMIT 1");
$stmt->execute([$nas_ip]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) { http_response_code(404); echo json_encode(["status"=>"error","message"=>"Router not found"]); exit; }
$user = $row["username"];
$pass = $Auth->decrypt($row["password"], "BSK-RAHMAD");
if (!$API->connect($nas_ip, $user, $pass)) { http_response_code(502); echo json_encode(["status"=>"error","message"=>"Failed to connect to router"]); exit; }
$chars = ;
$types = isset($payload[chars]) ? $payload[chars] : [numbers,uppercase];
if (in_array(numbers, $types)) $chars .= 0123456789;
if (in_array(uppercase, $types)) $chars .= ABCDEFGHIJKLMNOPQRSTUVWXYZ;
if (in_array(lowercase, $types)) $chars .= abcdefghijklmnopqrstuvwxyz;
function rand_str($len, $pool) { $out=; $n=strlen($pool); for($i=0;$i<$len;$i++){ $out .= $pool[random_int(0,$n-1)]; } return $out; }
$count = max(1, intval($payload[user-count] ?? 1));
$loginLen = max(1, intval($payload[login-length] ?? 6));
$passLen = intval($payload[password-length] ?? 0);
$profile = $payload[bandwidth-limit] ?? ;
$limit = ($payload[time-limit] ?? 1) . ($payload[time-unit] ?? m);
$created = [];
for ($i=0; $i<$count; $i++) {
    $username = rand_str($loginLen, $chars ?: 0123456789);
    $password = $passLen>0 ? rand_str($passLen, $chars ?: 0123456789) : $username;
    $cmd = [
        name => $username,
        password => $password,
        server => $server_name,
        profile => $profile,
        limit-uptime => $limit,
        comment => Price:
