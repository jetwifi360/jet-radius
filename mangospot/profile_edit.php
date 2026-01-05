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

$profile_name = isset($_GET['name']) ? mysqli_real_escape_string($conn, $_GET['name']) : '';
if(!$profile_name) {
    echo "<script>window.location='profiles.php';</script>";
    exit;
}

// Fetch existing data
$details = $conn->query("SELECT attribute, value FROM radgroupreply WHERE groupname='$profile_name'");
$checks = $conn->query("SELECT attribute, value FROM radgroupcheck WHERE groupname='$profile_name'");

$profile_data = [
    'price' => '0',
    'type' => 'Prepaid',
    'dl_rate' => '',
    'ul_rate' => '',
    'uptime_limit' => '',
    'uptime_unit' => 'Minutes',
    'expiration_limit' => '',
    'expiration_unit' => 'Days',
    'pool_name' => '',
    'address_list' => '',
    'mikrotik_group' => '', // New
    'hotspot_profile' => '',
    'priority' => '',
    'grace_period' => ''
];

// Helper to process attributes
function processAttr($attr, $val, &$profile_data) {
    if($attr == 'Unit-Price') $profile_data['price'] = $val;
    if($attr == 'Profile-Type') $profile_data['type'] = $val;
    if($attr == 'Mikrotik-Rate-Limit') {
        $parts = explode('/', $val);
        $profile_data['ul_rate'] = str_replace('k', '', $parts[0] ?? '');
        $profile_data['dl_rate'] = str_replace('k', '', $parts[1] ?? '');
    }
    if($attr == 'Max-All-Session') {
        if ($val > 0 && $val % 3600 == 0) {
            $profile_data['uptime_limit'] = $val / 3600;
            $profile_data['uptime_unit'] = 'Hours';
        } else {
            $profile_data['uptime_limit'] = round($val / 60);
            $profile_data['uptime_unit'] = 'Minutes';
        }
    }
    if($attr == 'Access-Period') {
        if ($val > 0 && $val % 2592000 == 0) {
            $profile_data['expiration_limit'] = $val / 2592000;
            $profile_data['expiration_unit'] = 'Months';
        } elseif ($val > 0 && $val % 86400 == 0) {
            $profile_data['expiration_limit'] = $val / 86400;
            $profile_data['expiration_unit'] = 'Days';
        } elseif ($val > 0 && $val % 3600 == 0) {
            $profile_data['expiration_limit'] = $val / 3600;
            $profile_data['expiration_unit'] = 'Hours';
        } else {
            $profile_data['expiration_limit'] = round($val / 60);
            $profile_data['expiration_unit'] = 'Minutes';
        }
    }
    if($attr == 'Framed-Pool') $profile_data['pool_name'] = $val;
    if($attr == 'Mikrotik-Address-List') $profile_data['address_list'] = $val;
    if($attr == 'Mikrotik-Group') $profile_data['mikrotik_group'] = $val;
    if($attr == 'Mikrotik-Hotspot-Profile') $profile_data['hotspot_profile'] = $val;
    if($attr == 'Mikrotik-Grace-Period') $profile_data['grace_period'] = $val;
}

while($d = $details->fetch_assoc()) {
    processAttr($d['attribute'], $d['value'], $profile_data);
}
while($c = $checks->fetch_assoc()) {
    processAttr($c['attribute'], $c['value'], $profile_data);
}

if(isset($_POST['update_profile'])) {
    // Collect Basic Info
    $name = $_POST['profile_name'];
    $price = $_POST['unit_price'];
    $type = $_POST['profile_type'];
    
    // Bandwidth
    $dl_rate = $_POST['rate_down'];
    $ul_rate = $_POST['rate_up'];
    $rate_limit = $ul_rate . "k/" . $dl_rate . "k";
    
    // Limits
    $pool_name = $_POST['pool_name'];
    $address_list = $_POST['address_list'];
    $mikrotik_group = $_POST['mikrotik_group']; // New
    $uptime_limit = $_POST['uptime_limit']; 
    $uptime_unit = $_POST['uptime_unit'];
    $exp_limit = $_POST['expiration_limit'];
    $exp_unit = $_POST['expiration_unit'];
    $grace_period = $_POST['grace_period'];
    $hotspot_profile = isset($_POST['mikrotik_hotspot_profile']) ? $_POST['mikrotik_hotspot_profile'] : '';
    
    // 1. Delete Old Attributes
    $conn->query("DELETE FROM radgroupreply WHERE groupname='$name'");
    // 1b. Delete Old Check Attributes (Max-All-Session & Access-Period)
    $conn->query("DELETE FROM radgroupcheck WHERE groupname='$name' AND attribute IN ('Max-All-Session', 'Access-Period')");
    
    // 2. Insert New Attributes
    function addAttr($conn, $group, $attr, $val, $op='=') {
        if($val != '') {
            $stmt = $conn->prepare("INSERT INTO radgroupreply (groupname, attribute, op, value) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("ssss", $group, $attr, $op, $val);
            $stmt->execute();
        }
    }
    
    addAttr($conn, $name, 'Mikrotik-Rate-Limit', $rate_limit);
    if($pool_name) addAttr($conn, $name, 'Framed-Pool', $pool_name);
    if($address_list) addAttr($conn, $name, 'Mikrotik-Address-List', $address_list);
    if($mikrotik_group) addAttr($conn, $name, 'Mikrotik-Group', $mikrotik_group); // New
    if($hotspot_profile) addAttr($conn, $name, 'Mikrotik-Hotspot-Profile', $hotspot_profile);
    
    // Handle Uptime Limit (Max-All-Session)
    $uptime_seconds = 0;
    if($uptime_limit > 0) {
        $uptime_seconds = ($uptime_unit == 'Hours') ? $uptime_limit * 3600 : $uptime_limit * 60;
    }
    if($uptime_seconds > 0) {
        $stmt = $conn->prepare("INSERT INTO radgroupcheck (groupname, attribute, op, value) VALUES (?, 'Max-All-Session', ':=', ?)");
        $stmt->bind_param("ss", $name, $uptime_seconds);
        $stmt->execute();
    }
    
    // Handle Expiration Validity (Access-Period)
    $exp_seconds = 0;
    if($exp_limit > 0) {
        if($exp_unit == 'Months') $exp_seconds = $exp_limit * 30 * 86400; // Approx 30 days
        elseif($exp_unit == 'Days') $exp_seconds = $exp_limit * 86400;
        elseif($exp_unit == 'Hours') $exp_seconds = $exp_limit * 3600;
        else $exp_seconds = $exp_limit * 60; // Minutes
    }
    if($exp_seconds > 0) {
        $stmt = $conn->prepare("INSERT INTO radgroupcheck (groupname, attribute, op, value) VALUES (?, 'Access-Period', ':=', ?)");
        $stmt->bind_param("ss", $name, $exp_seconds);
        $stmt->execute();
    }
    
    if($grace_period != '') {
        addAttr($conn, $name, 'Mikrotik-Grace-Period', $grace_period);
    }
    
    addAttr($conn, $name, 'Unit-Price', $price, ':=');
    addAttr($conn, $name, 'Profile-Type', $type, ':=');
    
    echo "<div class='alert alert-success'>Profile updated successfully!</div>";
    echo "<script>setTimeout(function(){ window.location='profiles.php'; }, 1500);</script>";
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="h3">Edit Profile: <?php echo htmlspecialchars($profile_name); ?></h2>
    <a href="profiles.php" class="btn btn-secondary"><i class="fas fa-arrow-left me-2"></i>Back to List</a>
</div>

<form method="POST" class="card shadow-sm">
    <div class="card-header bg-white">
        <ul class="nav nav-tabs card-header-tabs" id="profileTabs" role="tablist">
            <li class="nav-item"><a class="nav-link active" id="basic-tab" data-bs-toggle="tab" href="#basic" role="tab">Basic Info</a></li>
            <li class="nav-item"><a class="nav-link" id="limits-tab" data-bs-toggle="tab" href="#limits" role="tab">Service Limits</a></li>
            <li class="nav-item"><a class="nav-link" id="mikrotik-tab" data-bs-toggle="tab" href="#mikrotik" role="tab">Mikrotik Settings</a></li>
        </ul>
    </div>
    
    <div class="card-body">
        <div class="tab-content" id="profileTabsContent">
            
            <!-- Basic Information -->
            <div class="tab-pane fade show active" id="basic" role="tabpanel">
                <h5 class="card-title mb-4 text-primary">Basic Information</h5>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Profile Name</label>
                        <input type="text" name="profile_name" class="form-control" value="<?php echo htmlspecialchars($profile_name); ?>" readonly>
                        <div class="form-text">Profile name cannot be changed directly.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Unit Price <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text">GH₵</span>
                            <input type="number" step="0.01" name="unit_price" class="form-control" value="<?php echo $profile_data['price']; ?>" required>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Download Rate (kbps)</label>
                        <input type="number" name="rate_down" class="form-control" value="<?php echo $profile_data['dl_rate']; ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Upload Rate (kbps)</label>
                        <input type="number" name="rate_up" class="form-control" value="<?php echo $profile_data['ul_rate']; ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Type <span class="text-danger">*</span></label>
                        <select name="profile_type" class="form-select">
                            <option value="Prepaid" <?php if($profile_data['type']=='Prepaid') echo 'selected'; ?>>Prepaid</option>
                            <option value="Postpaid" <?php if($profile_data['type']=='Postpaid') echo 'selected'; ?>>Postpaid</option>
                            <option value="FUP" <?php if($profile_data['type']=='FUP') echo 'selected'; ?>>Fair Usage Policy</option>
                        </select>
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
                            <input type="number" name="expiration_limit" class="form-control" value="<?php echo $profile_data['expiration_limit']; ?>" placeholder="e.g. 30">
                            <select name="expiration_unit" class="form-select" style="max-width: 120px;">
                                <option value="Days" <?php if($profile_data['expiration_unit']=='Days') echo 'selected'; ?>>Days</option>
                                <option value="Months" <?php if($profile_data['expiration_unit']=='Months') echo 'selected'; ?>>Months</option>
                                <option value="Hours" <?php if($profile_data['expiration_unit']=='Hours') echo 'selected'; ?>>Hours</option>
                                <option value="Minutes" <?php if($profile_data['expiration_unit']=='Minutes') echo 'selected'; ?>>Minutes</option>
                            </select>
                        </div>
                        <div class="form-text">Set new validity (leaves unchanged if empty)</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Uptime Limit</label>
                        <div class="input-group">
                            <input type="number" name="uptime_limit" class="form-control" value="<?php echo $profile_data['uptime_limit']; ?>" placeholder="0 for unlimited">
                            <select name="uptime_unit" class="form-select" style="max-width: 120px;">
                                <option value="Minutes" <?php if($profile_data['uptime_unit']=='Minutes') echo 'selected'; ?>>Minutes</option>
                                <option value="Hours" <?php if($profile_data['uptime_unit']=='Hours') echo 'selected'; ?>>Hours</option>
                            </select>
                        </div>
                        <div class="form-text">Current Limit: <?php echo $profile_data['uptime_limit'] ? $profile_data['uptime_limit'].' '.$profile_data['uptime_unit'] : 'Unlimited'; ?></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Grace Period</label>
                        <div class="input-group">
                            <input type="number" name="grace_period" class="form-control" value="<?php echo $profile_data['grace_period']; ?>" placeholder="0">
                            <span class="input-group-text">Sec/Min</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Mikrotik Settings -->
            <div class="tab-pane fade" id="mikrotik" role="tabpanel">
                <h5 class="card-title mb-4 text-primary">Mikrotik Settings</h5>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">IP Pool Name</label>
                        <input type="text" name="pool_name" class="form-control" value="<?php echo $profile_data['pool_name']; ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Address List</label>
                        <input type="text" name="address_list" class="form-control" value="<?php echo $profile_data['address_list']; ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Mikrotik User Profile (Group)</label>
                        <?php if(!empty($mikrotik_profiles)){ ?>
                            <select name="mikrotik_group" class="form-select">
                                <option value="">Select profile</option>
                                <?php foreach($mikrotik_profiles as $mp){ ?>
                                    <option value="<?php echo htmlspecialchars($mp); ?>" <?php if($profile_data['mikrotik_group']==$mp) echo 'selected'; ?>><?php echo htmlspecialchars($mp); ?></option>
                                <?php } ?>
                            </select>
                        <?php } else { ?>
                            <input type="text" name="mikrotik_group" class="form-control" value="<?php echo $profile_data['mikrotik_group']; ?>" placeholder="e.g. default2">
                        <?php } ?>
                        <div class="form-text">Assigns the user to a specific Profile in Mikrotik User Manager / Hotspot</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Hotspot Server Profile</label>
                        <?php if(!empty($mikrotik_profiles)){ ?>
                            <select name="mikrotik_hotspot_profile" class="form-select">
                                <option value="">Select server profile</option>
                                <?php foreach($mikrotik_profiles as $mp){ ?>
                                    <option value="<?php echo htmlspecialchars($mp); ?>" <?php if($profile_data['hotspot_profile']==$mp) echo 'selected'; ?>><?php echo htmlspecialchars($mp); ?></option>
                                <?php } ?>
                            </select>
                        <?php } else { ?>
                            <input type="text" name="mikrotik_hotspot_profile" class="form-control" value="<?php echo $profile_data['hotspot_profile']; ?>" placeholder="e.g. CAFEROOM">
                        <?php } ?>
                    </div>
                </div>
            </div>

        </div>
    </div>
    <div class="card-footer bg-white text-end py-3">
        <button type="submit" name="update_profile" class="btn btn-primary px-4">
            <i class="fas fa-save me-2"></i>Update Profile
        </button>
    </div>
</form>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
