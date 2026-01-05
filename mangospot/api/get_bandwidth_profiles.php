<?php
header("Content-Type: application/json");
require_once "/var/www/html/mangospot/include/config.php";
require_once "/var/www/html/mangospot/include/mikrotik.php";
require_once "/var/www/html/mangospot/include/cipher.php";
$resp = ["profiles" => []];
try {
    $Bsk = new Connect();
    $Auth = new Cipher("aes-256-ecb");
    $API = new Mikrotik();
    $stmt = $Bsk->prepare("SELECT nasname, username, password, shortname FROM nas WHERE status = 'true'");
    $stmt->execute();
    $routers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($routers as $r) {
        $ip = $r["nasname"];
        $user = $r["username"];
        $pass = $Auth->decrypt($r["password"], "BSK-RAHMAD");
        if ($API->connect($ip, $user, $pass)) {
            $profiles = $API->comm("/ip/hotspot/user/profile/print");
            if (is_array($profiles)) {
                foreach ($profiles as $p) {
                    if (isset($p["name"])) {
                        $resp["profiles"][] = [
                            "name" => $p["name"],
                            "router" => $r["shortname"]
                        ];
                    }
                }
            }
            $API->disconnect();
        }
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => $e->getMessage()]);
    exit;
}
echo json_encode($resp);
?>
