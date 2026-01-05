<?php
/**
 * Mikrotik Hotspot Profile to IP Pool Mapping Configuration
 * 
 * This file contains the mapping between Mikrotik hotspot profiles
 * and their corresponding IP pools and NAS IP addresses.
 * 
 * Configure this based on your network setup:
 * - 192.168.5.1/24 pool (caferoom)
 * - 10.10.0.1/22 pool (main)
 */

// Pool mapping configuration
$pool_mapping = array(
    // CafeRoom Hotspot Configuration (192.168.5.1/24)
    'caferoom' => array(
        'nas_ip' => '192.168.5.1',
        'pool_name' => 'caferoom_pool',
        'mikrotik_group' => 'caferoom_users',
        'description' => 'CafeRoom Hotspot - 192.168.5.1/24 network'
    ),
    
    // Main Hotspot Configuration (10.10.0.1/22)
    'main' => array(
        'nas_ip' => '10.10.0.1',
        'pool_name' => 'main_pool',
        'mikrotik_group' => 'main_users',
        'description' => 'Main Hotspot - 10.10.0.1/22 network'
    ),
    
    // Alternative profile names (case-insensitive matching)
    'hsprof1' => array(
        'nas_ip' => '10.10.0.1',
        'pool_name' => 'main_pool',
        'mikrotik_group' => 'main_users',
        'description' => 'Main Hotspot Profile 1 - 10.10.0.1/22 network'
    ),
    
    'cafe' => array(
        'nas_ip' => '192.168.5.1',
        'pool_name' => 'caferoom_pool',
        'mikrotik_group' => 'caferoom_users',
        'description' => 'Cafe Hotspot - 192.168.5.1/24 network'
    )
);

/**
 * Function to get pool configuration by hotspot profile name
 * 
 * @param string $profile_name Mikrotik hotspot profile name
 * @return array|null Pool configuration or null if not found
 */
function get_pool_config_by_profile($profile_name) {
    global $pool_mapping;
    $profile_name = strtolower(trim($profile_name));
    
    // Direct match
    if(isset($pool_mapping[$profile_name])){
        return $pool_mapping[$profile_name];
    }
    
    // Partial match for flexible profile naming
    foreach($pool_mapping as $key => $config){
        if(strpos($profile_name, $key) !== false){
            return $config;
        }
    }
    
    return null;
}

/**
 * Function to get pool configuration by NAS IP address
 * 
 * @param string $nas_ip NAS IP address
 * @return array|null Pool configuration or null if not found
 */
function get_pool_config_by_nas($nas_ip) {
    global $pool_mapping;
    
    foreach($pool_mapping as $config){
        if($config['nas_ip'] === $nas_ip){
            return $config;
        }
    }
    
    return null;
}

/**
 * Function to get all available pool configurations
 * 
 * @return array All pool configurations
 */
function get_all_pool_configs() {
    global $pool_mapping;
    return $pool_mapping;
}
?>