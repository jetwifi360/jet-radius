<?php
/**
 * Mikrotik FreeRadius Pool Assignment Diagnostic Tool
 * 
 * This script helps diagnose and verify the IP pool assignment configuration
 * for your Mikrotik hotspot setup.
 */

require_once 'mikrotik_pool_mapping.php';

// Try to find the config files in common locations
$config_paths = array(
    '../include/config.php',
    '/var/www/html/mangospot/include/config.php',
    './include/config.php'
);

$config_found = false;
foreach($config_paths as $path){
    if(file_exists($path)){
        require_once $path;
        $config_found = true;
        break;
    }
}

if(!$config_found){
    echo "WARNING: Could not find config.php file. Some database tests will be skipped.\n";
    echo "Expected locations: " . implode(', ', $config_paths) . "\n\n";
}

echo "=== Mikrotik FreeRadius Pool Assignment Diagnostic Tool ===\n\n";

// Test the pool mapping configuration
echo "1. Testing Pool Mapping Configuration:\n";
echo "----------------------------------------\n";

$pool_configs = get_all_pool_configs();
echo "Available pool configurations:\n";
foreach($pool_configs as $profile => $config){
    echo "  Profile: $profile\n";
    echo "    NAS IP: {$config['nas_ip']}\n";
    echo "    Pool Name: {$config['pool_name']}\n";
    echo "    Mikrotik Group: {$config['mikrotik_group']}\n";
    echo "    Description: {$config['description']}\n\n";
}

// Test profile lookup functions
echo "2. Testing Profile Lookup Functions:\n";
echo "----------------------------------------\n";

$test_profiles = array('caferoom', 'main', 'hsprof1', 'cafe', 'unknown');
foreach($test_profiles as $profile){
    $config = get_pool_config_by_profile($profile);
    if($config){
        echo "✓ Profile '$profile' found:\n";
        echo "  NAS IP: {$config['nas_ip']}, Pool: {$config['pool_name']}\n";
    } else {
        echo "✗ Profile '$profile' not found in mapping\n";
    }
}

// Test NAS IP lookup
echo "\n3. Testing NAS IP Lookup:\n";
echo "----------------------------------------\n";

$test_nas_ips = array('192.168.5.1', '10.10.0.1', '192.168.1.1');
foreach($test_nas_ips as $nas_ip){
    $config = get_pool_config_by_nas($nas_ip);
    if($config){
        echo "✓ NAS IP '$nas_ip' found:\n";
        echo "  Pool: {$config['pool_name']}, Group: {$config['mikrotik_group']}\n";
    } else {
        echo "✗ NAS IP '$nas_ip' not found in mapping\n";
    }
}

// Database connection test
echo "\n4. Testing Database Connection:\n";
echo "----------------------------------------\n";

if($config_found && class_exists('Connect')){
    try {
        $Bsk = new Connect();
        $Bsk->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        echo "✓ Database connection successful\n";
        
        // Test if radgroupreply table exists and has required attributes
        $tables = $Bsk->query("SHOW TABLES LIKE 'radgroupreply'")->fetchAll();
        if(count($tables) > 0){
            echo "✓ radgroupreply table exists\n";
            
            // Check for required attributes
            $attributes = array('Mikrotik-Hotspot-Profile', 'Framed-Pool', 'Mikrotik-Group');
            foreach($attributes as $attr){
                $count = $Bsk->query("SELECT COUNT(*) as count FROM radgroupreply WHERE attribute='$attr'")->fetch();
                echo "  - $attr: {$count['count']} entries found\n";
            }
        } else {
            echo "✗ radgroupreply table not found\n";
        }
        
    } catch (Exception $e) {
        echo "✗ Database connection failed: " . $e->getMessage() . "\n";
    }
} else {
    echo "⚠ Skipping database tests (config file not found)\n";
}

// Configuration recommendations
echo "\n5. Configuration Recommendations:\n";
echo "----------------------------------------\n";

echo "Based on your setup (192.168.5.1/24 and 10.10.0.1/22 pools):\n\n";

echo "For CafeRoom Hotspot (192.168.5.1/24):\n";
echo "  - Create hotspot profile: 'caferoom' or 'cafe'\n";
echo "  - Configure IP pool: 'caferoom_pool' (192.168.5.1/24)\n";
echo "  - Set NAS IP: 192.168.5.1\n\n";

echo "For Main Hotspot (10.10.0.1/22):\n";
echo "  - Create hotspot profile: 'main' or 'hsprof1'\n";
echo "  - Configure IP pool: 'main_pool' (10.10.0.1/22)\n";
echo "  - Set NAS IP: 10.10.0.1\n\n";

echo "Profile Configuration Steps:\n";
echo "1. Go to your profile management page\n";
echo "2. For each profile, set the Mikrotik Hotspot Profile field\n";
echo "3. Set the IP Pool Name to match your Mikrotik configuration\n";
echo "4. Set the Mikrotik User Profile (Group) as needed\n\n";

echo "=== End of Diagnostic Report ===\n";
?>