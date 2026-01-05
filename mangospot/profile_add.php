<?php include('header.php'); include('db.php'); 
require_once '/var/www/html/mangospot/include/autoload.php';

$mikrotik_profiles = [];
$Router = new MikroTik();
$Auth = new Cipher('aes-256-ecb');
$nasRes = $conn->query("SELECT * FROM nas WHERE status='true' LIMIT 1");
if(!$nasRes || $nasRes->num_rows == 0){
    $nasRes = $conn->query("SELECT * FROM nas LIMIT 1");
}
if($nasRes && ($nas = $nasRes->fetch_assoc())){
    if(!empty($nas['port'])){ $Router->port = $nas['port']; }
    $pass = $Auth->decrypt($nas['password'], 'BSK-RAHMAD');
    if($Router->connect($nas['nasname'], $nas['username'], $pass)){
        $list = $Router->comm("/ip/hotspot/profile/print");
        if(is_array($list)){
            foreach($list as $p){ if(isset($p['name'])){ $mikrotik_profiles[] = $p['name']; } }
        }
        $Router->disconnect();
    }
}

if(isset($_POST['save_profile'])) {
    // Collect Basic Info
    $name = $_POST['profile_name'];
    $price = $_POST['unit_price'];
    $type = $_POST['profile_type']; // Prepaid, etc.
    $enabled = isset($_POST['enabled']) ? 1 : 0;
    
    // Bandwidth
    $dl_rate = $_POST['rate_down'];
    $ul_rate = $_POST['rate_up'];
    $rate_limit = $ul_rate . "k/" . $dl_rate . "k"; // Mikrotik format: upload/download
    
    // Limits
    $pool_name = $_POST['pool_name'];
    $address_list = $_POST['address_list'];
    $mikrotik_group = $_POST['mikrotik_group']; // New Mikrotik Group
    $hotspot_profile = isset($_POST['mikrotik_hotspot_profile']) ? $_POST['mikrotik_hotspot_profile'] : '';
    $uptime_limit = $_POST['uptime_limit']; 
    $uptime_unit = $_POST['uptime_unit'];
    $exp_limit = $_POST['expiration_limit'];
    $exp_unit = $_POST['expiration_unit'];
    $grace_period = $_POST['grace_period'];
    
    // Clear old attributes for this group to avoid duplicates
    $conn->query("DELETE FROM radgroupreply WHERE groupname='$name'");
    $conn->query("DELETE FROM radgroupcheck WHERE groupname='$name'");
    
    // Helper to insert attribute
    function addAttr($conn, $group, $attr, $val, $op='=', $table='radgroupreply') {
        if($val != '') {
            $stmt = $conn->prepare("INSERT INTO $table (groupname, attribute, op, value) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("ssss", $group, $attr, $op, $val);
            $stmt->execute();
        }
    }
    
    // Standard Attributes
    addAttr($conn, $name, 'Mikrotik-Rate-Limit', $rate_limit);
    if($pool_name) addAttr($conn, $name, 'Framed-Pool', $pool_name);
    if($address_list) addAttr($conn, $name, 'Mikrotik-Address-List', $address_list);
    if($mikrotik_group) addAttr($conn, $name, 'Mikrotik-Group', $mikrotik_group); // Add Mikrotik-Group
    if($hotspot_profile) addAttr($conn, $name, 'Mikrotik-Hotspot-Profile', $hotspot_profile);
    
    // Handle Uptime Limit (Max-All-Session)
    $uptime_seconds = 0;
    if($uptime_limit > 0) {
        $uptime_seconds = ($uptime_unit == 'Hours') ? $uptime_limit * 3600 : $uptime_limit * 60;
    }
    if($uptime_seconds > 0) {
        // Max-All-Session goes to radgroupcheck for sqlcounter
        addAttr($conn, $name, 'Max-All-Session', $uptime_seconds, ':=', 'radgroupcheck');
    }

    // Handle Expiration Validity (Access-Period)
    $exp_seconds = 0;
    if($exp_limit > 0) {
        if($exp_unit == 'Months') $exp_seconds = $exp_limit * 30 * 86400;
        elseif($exp_unit == 'Days') $exp_seconds = $exp_limit * 86400;
        elseif($exp_unit == 'Hours') $exp_seconds = $exp_limit * 3600;
        else $exp_seconds = $exp_limit * 60; // Minutes
    }
    if($exp_seconds > 0) {
        addAttr($conn, $name, 'Access-Period', $exp_seconds, ':=', 'radgroupcheck');
    }
    
    // Grace Period
    if($grace_period != '') {
        addAttr($conn, $name, 'Mikrotik-Grace-Period', $grace_period);
    }
    
    // Custom Attributes for UI
    addAttr($conn, $name, 'Unit-Price', $price, ':=');
    addAttr($conn, $name, 'Profile-Type', $type, ':=');
    
    echo "<div class='alert alert-success'>Profile '$name' created successfully!</div>";
    echo "<script>setTimeout(function(){ window.location='profiles.php'; }, 1500);</script>";
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="h3">Create New Profile</h2>
    <a href="profiles.php" class="btn btn-secondary"><i class="fas fa-arrow-left me-2"></i>Back to List</a>
</div>

<form method="POST" class="card shadow-sm">
    <div class="card-header bg-white">
        <ul class="nav nav-tabs card-header-tabs" id="profileTabs" role="tablist">
            <li class="nav-item">
                <a class="nav-link active" id="basic-tab" data-bs-toggle="tab" href="#basic" role="tab">Basic Info</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" id="limits-tab" data-bs-toggle="tab" href="#limits" role="tab">Service Limits</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" id="mikrotik-tab" data-bs-toggle="tab" href="#mikrotik" role="tab">Mikrotik Settings</a>
            </li>
        </ul>
    </div>
    
    <div class="card-body">
        <div class="tab-content" id="profileTabsContent">
            
            <!-- Basic Information -->
            <div class="tab-pane fade show active" id="basic" role="tabpanel">
                <h5 class="card-title mb-4 text-primary">Basic Information</h5>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Profile Name <span class="text-danger">*</span></label>
                        <input type="text" name="profile_name" class="form-control" placeholder="e.g., 5Mbps-Monthly" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Unit Price <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text">GH₵</span>
                            <input type="number" step="0.01" name="unit_price" class="form-control" required>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Download Rate (kbps)</label>
                        <input type="number" name="rate_down" class="form-control" placeholder="e.g. 5120 for 5Mbps" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Upload Rate (kbps)</label>
                        <input type="number" name="rate_up" class="form-control" placeholder="e.g. 1024 for 1Mbps" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Type <span class="text-danger">*</span></label>
                        <select name="profile_type" class="form-select">
                            <option value="Prepaid">Prepaid</option>
                            <option value="Postpaid">Postpaid</option>
                            <option value="FUP">Fair Usage Policy</option>
                        </select>
                    </div>
                    <div class="col-md-6 d-flex align-items-center">
                        <div class="form-check form-switch mt-4">
                            <input class="form-check-input" type="checkbox" name="enabled" id="enabledSwitch" checked>
                            <label class="form-check-label" for="enabledSwitch">Enabled</label>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Service Limits -->
            <div class="tab-pane fade" id="limits" role="tabpanel">
                <h5 class="card-title mb-4 text-primary">Service Limits</h5>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Expiration Validity</label>
                        <div class="input-group">
                            <input type="number" name="expiration_limit" class="form-control" placeholder="e.g. 30">
                            <select name="expiration_unit" class="form-select" style="max-width: 120px;">
                                <option value="Days">Days</option>
                                <option value="Months">Months</option>
                                <option value="Hours">Hours</option>
                                <option value="Minutes">Minutes</option>
                            </select>
                        </div>
                        <div class="form-text">Used for Monthly/Weekly plans</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Uptime Limit</label>
                        <div class="input-group">
                            <input type="number" name="uptime_limit" class="form-control" placeholder="0 for unlimited">
                            <select name="uptime_unit" class="form-select" style="max-width: 120px;">
                                <option value="Minutes">Minutes</option>
                                <option value="Hours">Hours</option>
                            </select>
                        </div>
                        <div class="form-text">For hourly cards (overrides Expiration if set)</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Grace Period</label>
                        <div class="input-group">
                            <input type="number" name="grace_period" class="form-control" placeholder="0">
                            <span class="input-group-text">Sec/Min</span>
                        </div>
                        <div class="form-text">Grace period before disconnection</div>
                    </div>
                </div>
            </div>

            <!-- Mikrotik Settings -->
            <div class="tab-pane fade" id="mikrotik" role="tabpanel">
                <h5 class="card-title mb-4 text-primary">Mikrotik Settings</h5>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">IP Pool Name</label>
                        <input type="text" name="pool_name" class="form-control" placeholder="dhcp_pool1">
                        <div class="form-text">Must match a pool name on your Mikrotik</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Address List</label>
                        <input type="text" name="address_list" class="form-control" placeholder="e.g. allowed_users">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Mikrotik User Profile (Group)</label>
                        <?php if(!empty($mikrotik_profiles)){ ?>
                            <select name="mikrotik_group" class="form-select">
                                <option value="">Select profile</option>
                                <?php foreach($mikrotik_profiles as $mp){ ?>
                                    <option value="<?php echo htmlspecialchars($mp); ?>"><?php echo htmlspecialchars($mp); ?></option>
                                <?php } ?>
                            </select>
                        <?php } else { ?>
                            <input type="text" name="mikrotik_group" class="form-control" placeholder="e.g. default2">
                        <?php } ?>
                        <div class="form-text">Assigns the user to a specific Profile in Mikrotik User Manager / Hotspot</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Hotspot Server Profile</label>
                        <?php if(!empty($mikrotik_profiles)){ ?>
                            <select name="mikrotik_hotspot_profile" class="form-select">
                                <option value="">Select server profile</option>
                                <?php foreach($mikrotik_profiles as $mp){ ?>
                                    <option value="<?php echo htmlspecialchars($mp); ?>"><?php echo htmlspecialchars($mp); ?></option>
                                <?php } ?>
                            </select>
                        <?php } else { ?>
                            <input type="text" name="mikrotik_hotspot_profile" class="form-control" placeholder="e.g. CAFEROOM">
                        <?php } ?>
                    </div>
                </div>
            </div>

        </div>
    </div>
    <div class="card-footer bg-white text-end py-3">
        <button type="submit" name="save_profile" class="btn btn-primary px-4">
            <i class="fas fa-save me-2"></i>Create Profile
        </button>
    </div>
</form>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
