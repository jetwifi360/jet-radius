<?php
// sync_usage.php
// Polls MikroTik for active users and updates custom_usage table
// Bypasses unreliable RADIUS accounting

// Use a lock file to prevent overlapping runs
$lock_file = sys_get_temp_dir() . '/sync_usage.lock';
$fp = @fopen($lock_file, 'w+');
if (!$fp) {
    // If permission denied (e.g. owned by root/www-data), try a user-specific lock file
    // Or just use a different name that we likely have access to
    $lock_file = sys_get_temp_dir() . '/sync_usage_debug.lock';
    $fp = fopen($lock_file, 'w+');
}

if (!flock($fp, LOCK_EX | LOCK_NB)) {
    die("Script is already running.\n");
}

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set include path to handle relative includes in required files
set_include_path(get_include_path() . PATH_SEPARATOR . '/var/www/html/mangospot/include');

// Define constants expected by config.php
if (!defined('ABSPATH')) {
    define('ABSPATH', '/var/www/html/mangospot/include/');
}

// Manually include files to avoid relative path issues if running from elsewhere
require_once '/var/www/html/mangospot/include/config.php';
require_once '/var/www/html/mangospot/include/mikrotik.php';
require_once '/var/www/html/mangospot/include/cipher.php';

// Initialize Database Connection
try {
    $Bsk = new Connect();
} catch (Exception $e) {
    die("DB Connection failed: " . $e->getMessage() . "\n");
}

// Initialize RouterOS API
$API = new Mikrotik();

// Initialize Cipher for password decryption
$Auth = new Cipher('aes-256-ecb');

// Use PDO directly for safety
$stmt = $Bsk->prepare("SELECT id, nasname, username, password, port, identity, shortname FROM nas WHERE status = 'true'");
$stmt->execute();
$routers = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($routers as $router) {
    $nas_ip = $router['nasname'];
    $nas_user = $router['username'];
    $nas_pass_enc = $router['password'];
    $server_name = $router['shortname'];
    $identity = $router['identity'];
    
    // Decrypt password
    $nas_pass = $Auth->decrypt($nas_pass_enc, 'BSK-RAHMAD');
    $nas_port = $router['port'] ? intval($router['port']) : 8728;
    
    echo "Connecting to $nas_ip...\n";
    
    if ($API->connect($nas_ip . ':' . $nas_port, $nas_user, $nas_pass)) {
        echo "Connected.\n";
        
        // Fetch Hotspot Active Users
        $hotspot_users = $API->comm('/ip/hotspot/active/print');
        
        // Fetch PPP Active Users
        $ppp_users = $API->comm('/ppp/active/print');
        
        // Merge users
        if (!is_array($hotspot_users)) $hotspot_users = [];
        if (!is_array($ppp_users)) $ppp_users = [];
        
        $all_users = array_merge($hotspot_users, $ppp_users);
        
        echo "Found " . count($all_users) . " active users.\n";
        
        $seen_ids = [];

        foreach ($all_users as $user) {
            $username = isset($user['user']) ? $user['user'] : (isset($user['name']) ? $user['name'] : null);
            $mt_id = isset($user['.id']) ? $user['.id'] : null;
            
            if (!$username || !$mt_id) continue;
            
            $seen_ids[] = $mt_id;

            $uptime_str = isset($user['uptime']) ? $user['uptime'] : "0s";
            $uptime = parse_uptime($uptime_str);
            
            $bytes_in = isset($user['bytes-in']) ? intval($user['bytes-in']) : 0;
            $bytes_out = isset($user['bytes-out']) ? intval($user['bytes-out']) : 0;
            $total_bytes = $bytes_in + $bytes_out;
            
            $address = isset($user['address']) ? $user['address'] : (isset($user['remote-address']) ? $user['remote-address'] : null);
            $profile = isset($user['profile']) ? $user['profile'] : (isset($user['service']) ? $user['service'] : null);

            // Check active_sessions cache
            $stmt = $Bsk->prepare("SELECT * FROM active_sessions WHERE mikrotik_id = ? AND nas_ip = ?");
            $stmt->execute([$mt_id, $nas_ip]);
            $cached = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $delta_time = 0;
            $delta_bytes = 0;
            
            if ($cached) {
                // Check if it's the same username
                if ($cached['username'] == $username) {
                    if ($uptime >= $cached['last_uptime']) {
                        $delta_time = $uptime - $cached['last_uptime'];
                    } else {
                        // Uptime reset
                        $delta_time = $uptime;
                    }
                    
                    if ($total_bytes >= $cached['last_bytes']) {
                        $delta_bytes = $total_bytes - $cached['last_bytes'];
                    } else {
                        $delta_bytes = $total_bytes;
                    }
                    
                    // Update cache
                    $upd = $Bsk->prepare("UPDATE active_sessions SET last_uptime = ?, last_bytes = ?, server = ?, address = ?, profile = ?, identity = ?, last_seen = NOW() WHERE mikrotik_id = ? AND nas_ip = ?");
                    $upd->execute([$uptime, $total_bytes, $server_name, $address, $profile, $identity, $mt_id, $nas_ip]);
                } else {
                    // ID collision or reuse
                    $delta_time = $uptime;
                    $delta_bytes = $total_bytes;
                    $upd = $Bsk->prepare("UPDATE active_sessions SET username = ?, last_uptime = ?, last_bytes = ?, server = ?, address = ?, profile = ?, identity = ?, last_seen = NOW() WHERE mikrotik_id = ? AND nas_ip = ?");
                    $upd->execute([$username, $uptime, $total_bytes, $server_name, $address, $profile, $identity, $mt_id, $nas_ip]);
                }
                
            } else {
                // New session found
                $delta_time = $uptime;
                $delta_bytes = $total_bytes;
                
                // Insert into cache
                $ins = $Bsk->prepare("INSERT INTO active_sessions (mikrotik_id, nas_ip, username, last_uptime, last_bytes, server, address, profile, identity, last_seen) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                $ins->execute([$mt_id, $nas_ip, $username, $uptime, $total_bytes, $server_name, $address, $profile, $identity]);
            }
            
            // Update custom_usage accumulator and daily_usage
            if ($delta_time > 0 || $delta_bytes > 0) {
                // Check if user exists in custom_usage
                $chk = $Bsk->prepare("SELECT username FROM custom_usage WHERE username = ?");
                $chk->execute([$username]);
                if ($chk->fetch()) {
                    // Update existing user
                    $upd_usage = $Bsk->prepare("UPDATE custom_usage SET total_time = total_time + ?, total_bytes = total_bytes + ?, first_login = COALESCE(first_login, NOW()) WHERE username = ?");
                    $upd_usage->execute([$delta_time, $delta_bytes, $username]);
                } else {
                    // Insert new user
                    $ins_usage = $Bsk->prepare("INSERT INTO custom_usage (username, total_time, total_bytes, first_login) VALUES (?, ?, ?, NOW())");
                    $ins_usage->execute([$username, $delta_time, $delta_bytes]);
                }
                
                // Update Daily Usage
                $daily_chk = $Bsk->prepare("SELECT username FROM daily_usage WHERE username = ? AND date = CURDATE()");
                $daily_chk->execute([$username]);
                if ($daily_chk->fetch()) {
                    $daily_upd = $Bsk->prepare("UPDATE daily_usage SET total_time = total_time + ?, total_bytes = total_bytes + ? WHERE username = ? AND date = CURDATE()");
                    $daily_upd->execute([$delta_time, $delta_bytes, $username]);
                } else {
                    $daily_ins = $Bsk->prepare("INSERT INTO daily_usage (username, date, total_time, total_bytes) VALUES (?, CURDATE(), ?, ?)");
                    $daily_ins->execute([$username, $delta_time, $delta_bytes]);
                }
            }
        }
        
        // Remove stale sessions for this router
        if (!empty($seen_ids)) {
             // Create placeholders string (?,?,?)
             $placeholders = str_repeat('?,', count($seen_ids) - 1) . '?';
             // Add nas_ip to the parameters array
             $params = array_merge([$nas_ip], $seen_ids);
             $del = $Bsk->prepare("DELETE FROM active_sessions WHERE nas_ip = ? AND mikrotik_id NOT IN ($placeholders)");
             $del->execute($params);
        } else {
             // No active users found, clear all for this router
             $del = $Bsk->prepare("DELETE FROM active_sessions WHERE nas_ip = ?");
             $del->execute([$nas_ip]);
        }
        
        $API->disconnect();
    } else {
        echo "Failed to connect to $nas_ip\n";
    }
}

flock($fp, LOCK_UN);
fclose($fp);

function parse_uptime($str) {
    $s = 0;
    if (preg_match('/(\d+)w/', $str, $m)) $s += $m[1] * 604800;
    if (preg_match('/(\d+)d/', $str, $m)) $s += $m[1] * 86400;
    if (preg_match('/(\d+)h/', $str, $m)) $s += $m[1] * 3600;
    if (preg_match('/(\d+)m/', $str, $m)) $s += $m[1] * 60;
    if (preg_match('/(\d+)s/', $str, $m)) $s += $m[1];
    
    if ($s == 0 && is_numeric($str)) $s = intval($str);
    return $s;
}
?>