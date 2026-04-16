<?php
// ── TOTP 2FA — server-side verification ──────────────────────
// Uses Time-based One-Time Password (RFC 6238) — works with
// Google Authenticator, Authy, any TOTP app.
// Set TOTP_SECRET in your .env (generate once, store safely)

function totp_verify($secret, $code, $window = 1) {
    $code = preg_replace('/\s/', '', $code);
    if (strlen($code) !== 6 || !ctype_digit($code)) return false;

    // Base32 decode
    $base32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = strtoupper($secret);
    $bits = '';
    foreach (str_split($secret) as $c) {
        $pos = strpos($base32, $c);
        if ($pos === false) continue;
        $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
    }
    $key = '';
    foreach (str_split($bits, 8) as $b) {
        if (strlen($b) === 8) $key .= chr(bindec($b));
    }

    $time = floor(time() / 30);
    for ($i = -$window; $i <= $window; $i++) {
        $t = pack('N*', 0) . pack('N*', $time + $i);
        $hash = hash_hmac('sha1', $t, $key, true);
        $offset = ord($hash[19]) & 0xf;
        $otp = (
            ((ord($hash[$offset])   & 0x7f) << 24) |
            ((ord($hash[$offset+1]) & 0xff) << 16) |
            ((ord($hash[$offset+2]) & 0xff) <<  8) |
             (ord($hash[$offset+3]) & 0xff)
        ) % 1000000;
        if (str_pad($otp, 6, '0', STR_PAD_LEFT) === $code) return true;
    }
    return false;
}

// TOTP verify endpoint
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['totp_check'])) {
    header('Content-Type: application/json');

    // Rate limit TOTP attempts: 5 per IP per 5 minutes
    $ip        = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $rate_file = sys_get_temp_dir() . '/totp_rate_' . md5($ip) . '.json';
    $now       = time();
    $rate_data = file_exists($rate_file) ? json_decode(file_get_contents($rate_file), true) : ['count' => 0, 'start' => $now];
    if (($now - $rate_data['start']) > 300) $rate_data = ['count' => 0, 'start' => $now];
    if ($rate_data['count'] >= 5) {
        http_response_code(429);
        echo json_encode(['valid' => false, 'blocked' => true]);
        error_log("[SECURITY] TOTP rate limit hit for IP: {$ip}");
        exit;
    }
    $rate_data['count']++;
    file_put_contents($rate_file, json_encode($rate_data), LOCK_EX);

    $secret = getenv('TOTP_SECRET');
    $code   = $_POST['code'] ?? '';

    if (!$secret) {
        // 2FA not configured — skip (dev mode)
        echo json_encode(['valid' => true, 'configured' => false]);
        exit;
    }

    $valid = totp_verify($secret, $code);
    if (!$valid) error_log("[SECURITY] Failed 2FA attempt from IP: {$ip}");
    echo json_encode(['valid' => $valid, 'configured' => true]);
    exit;
}

// ── HTTP Basic Auth gate — first line of defence ─────────────
$gate_user = getenv('ADMIN_GATE_USER') ?: 'admin';
$gate_pass = getenv('ADMIN_GATE_PASS') ?: '';

if (!empty($gate_pass)) {
    $provided_user = $_SERVER['PHP_AUTH_USER'] ?? '';
    $provided_pass = $_SERVER['PHP_AUTH_PW']   ?? '';
    if ($provided_user !== $gate_user || !hash_equals($gate_pass, $provided_pass)) {
        header('WWW-Authenticate: Basic realm="Admin Access"');
        header('HTTP/1.0 401 Unauthorized');
        echo 'Access denied.';
        exit;
    }
}

// ============================================================
// SECURITY: Firebase config loaded from environment variables.
// ============================================================
$firebaseConfig = [
  "apiKey"            => getenv('FIREBASE_API_KEY'),
  "authDomain"        => getenv('FIREBASE_AUTH_DOMAIN'),
  "databaseURL"       => getenv('FIREBASE_DATABASE_URL'),
  "projectId"         => getenv('FIREBASE_PROJECT_ID'),
  "storageBucket"     => getenv('FIREBASE_STORAGE_BUCKET'),
  "messagingSenderId" => getenv('FIREBASE_MESSAGING_SENDER_ID'),
  "appId"             => getenv('FIREBASE_APP_ID')
];

// ── Admin login rate limiting (server-side) ───────────────────
// Max 10 login attempts per IP per 15 minutes
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_login_check'])) {
    header('Content-Type: application/json');
    $ip        = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $rate_file = sys_get_temp_dir() . '/admin_login_' . md5($ip) . '.json';
    $window    = 900; // 15 minutes
    $max_req   = 10;
    $now       = time();
    $rate_data = file_exists($rate_file) ? json_decode(file_get_contents($rate_file), true) : ['count' => 0, 'start' => $now];

    if (($now - $rate_data['start']) > $window) {
        $rate_data = ['count' => 0, 'start' => $now];
    }

    if ($rate_data['count'] >= $max_req) {
        http_response_code(429);
        echo json_encode(["blocked" => true]);
        error_log("[SECURITY] Admin login rate limit hit for IP: {$ip} at " . date('Y-m-d H:i:s'));
        exit;
    }

    $rate_data['count']++;
    file_put_contents($rate_file, json_encode($rate_data), LOCK_EX);
    echo json_encode(["blocked" => false]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>JavaGoat Admin | Premium Dashboard</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <style>
        :root {
            --primary: #10b981;
            --primary-dark: #059669;
            --primary-soft: #d1fae5;
            --bg-body: #f8fafc;
            --bg-card: #ffffff;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --border: #e2e8f0;
            --sidebar-width: 260px;
            --danger: #ef4444;
            --warning: #f59e0b;
            --info: #3b82f6;
        }

        * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }

        body {
            margin: 0;
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: var(--bg-body);
            color: var(--text-main);
            display: flex;
            height: 100vh;
            overflow: hidden;
        }

        /* --- Custom Scrollbar --- */
        ::-webkit-scrollbar { width: 5px; height: 5px; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }

        /* --- Sidebar --- */
        .sidebar {
            width: var(--sidebar-width);
            background-color: #0f172a;
            display: flex;
            flex-direction: column;
            padding: 24px;
            transition: all 0.3s ease;
            z-index: 100;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 1.4rem;
            font-weight: 800;
            color: white;
            margin-bottom: 40px;
            letter-spacing: -0.5px;
        }
        .logo i { color: var(--primary); }

        .nav-links { list-style: none; padding: 0; margin: 0; flex: 1; }
        .nav-item {
            display: flex;
            align-items: center;
            padding: 12px 16px;
            color: #94a3b8;
            text-decoration: none;
            border-radius: 12px;
            margin-bottom: 8px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.2s;
        }
        .nav-item i { width: 24px; font-size: 1.1rem; }
        .nav-item:hover { background: rgba(255,255,255,0.05); color: white; }
        .nav-item.active { background: var(--primary); color: white; }

        .logout-btn { color: #f87171; margin-top: auto; }

        /* --- Main Layout --- */
        .main-wrapper {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            position: relative;
        }

        .top-bar {
            height: 70px;
            background: white;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            padding: 0 40px;
            justify-content: space-between;
        }

        .mobile-toggle { display: none; font-size: 1.5rem; cursor: pointer; }

        .content-area {
            flex: 1;
            overflow-y: auto;
            padding: 40px;
        }

        /* --- Sections --- */
        .section { display: none; }
        .section.active { display: block; animation: fadeIn 0.4s ease; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

        .header-flex {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }
        .header-flex h1 { font-size: 1.8rem; font-weight: 800; margin: 0; letter-spacing: -1px; }

        /* --- Stats Card --- */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }
        .stat-card {
            background: white;
            padding: 24px;
            border-radius: 20px;
            border: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 20px;
        }
        .stat-icon {
            width: 54px; height: 54px;
            background: var(--primary-soft);
            color: var(--primary);
            border-radius: 14px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.4rem;
        }
        .stat-info h3 { margin: 0; font-size: 1.6rem; font-weight: 800; }
        .stat-info p { margin: 2px 0 0; color: var(--text-muted); font-size: 0.9rem; font-weight: 600; }

        /* --- Orders Table --- */
        .table-container {
            background: white;
            border-radius: 20px;
            border: 1px solid var(--border);
            overflow: hidden;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);
        }
        table { width: 100%; border-collapse: collapse; }
        th {
            background: #f8fafc;
            padding: 16px 24px;
            text-align: left;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--text-muted);
            border-bottom: 1px solid var(--border);
        }
        td {
            padding: 18px 24px;
            border-bottom: 1px solid var(--border);
            font-size: 0.95rem;
            vertical-align: middle;
        }
        tr:last-child td { border-bottom: none; }

        .customer-info { line-height: 1.4; }
        .customer-info .name { font-weight: 700; display: block; }
        .customer-info .phone { font-size: 0.85rem; color: var(--text-muted); }

        .item-list { font-size: 0.85rem; color: var(--text-muted); max-width: 250px; line-height: 1.5; }
        
        /* Status Select Styling */
        .status-select {
            padding: 8px 12px;
            border-radius: 8px;
            font-weight: 700;
            font-size: 0.8rem;
            border: 1px solid transparent;
            cursor: pointer;
            outline: none;
            transition: all 0.2s;
            width: 140px;
        }
        
        .status-placed { background: #fef3c7; color: #92400e; }
        .status-preparing { background: #e0e7ff; color: #3730a3; }
        .status-delivery { background: #ffedd5; color: #9a3412; }
        .status-delivered { background: #d1fae5; color: #065f46; }

        /* --- Buttons & Inputs --- */
        .btn-add {
            background: var(--primary);
            color: white;
            padding: 12px 24px;
            border-radius: 12px;
            border: none;
            font-weight: 700;
            cursor: pointer;
            display: flex; align-items: center; gap: 8px;
            transition: 0.3s;
        }
        .btn-add:hover { background: var(--primary-dark); transform: translateY(-2px); }

        /* --- Grid for Menu/Restaurants --- */
        .menu-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 24px;
        }
        .admin-card {
            background: white; border-radius: 20px; overflow: hidden;
            border: 1px solid var(--border); position: relative;
        }
        .admin-card img { width: 100%; height: 160px; object-fit: cover; }
        .admin-card-body { padding: 20px; }
        .admin-card h4 { margin: 0 0 5px; font-weight: 700; font-size: 1.1rem; }
        .delete-btn {
            position: absolute; top: 10px; right: 10px;
            background: white; color: var(--danger);
            border: none; width: 32px; height: 32px; border-radius: 8px;
            cursor: pointer; box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }

        /* --- Auth Overlay --- */
        #authOverlay {
            position: fixed; inset: 0; background: var(--bg-body);
            z-index: 2000; display: flex; align-items: center; justify-content: center;
        }
        .login-box {
            background: white; padding: 40px; border-radius: 30px;
            width: 100%; max-width: 400px; border: 1px solid var(--border);
            box-shadow: 0 20px 50px rgba(0,0,0,0.05); text-align: center;
        }
        .login-box input {
            width: 100%; padding: 16px; margin-bottom: 15px;
            border: 1px solid var(--border); border-radius: 12px;
            font-family: inherit; font-size: 1rem;
        }

        /* --- Modals --- */
        .modal {
            display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5);
            z-index: 1000; align-items: center; justify-content: center; padding: 20px;
        }
        .modal-content {
            background: white; padding: 30px; border-radius: 24px;
            width: 100%; max-width: 450px; position: relative;
        }
        .close-modal { position: absolute; right: 20px; top: 20px; cursor: pointer; font-size: 1.5rem; }

        /* =======================================================
           RESPONSIVE REWRITE
           ======================================================= */
        @media (max-width: 992px) {
            .sidebar {
                position: fixed; left: -280px; top: 0; bottom: 0;
            }
            .sidebar.active { left: 0; }
            .mobile-toggle { display: block; }
            .top-bar { padding: 0 20px; }
            .content-area { padding: 20px; }
        }

        @media (max-width: 768px) {
            .header-flex { flex-direction: column; align-items: flex-start; gap: 15px; }
            .btn-add { width: 100%; justify-content: center; }

            /* --- Table to Card Conversion --- */
            .table-container { background: transparent; border: none; box-shadow: none; }
            table, thead, tbody, th, td, tr { display: block; }
            thead { display: none; }
            
            tr {
                background: white; margin-bottom: 20px; padding: 20px;
                border-radius: 20px; border: 1px solid var(--border);
                box-shadow: 0 4px 10px rgba(0,0,0,0.02);
            }
            
            td {
                display: flex; justify-content: space-between; align-items: center;
                padding: 12px 0; border-bottom: 1px solid #f1f5f9;
                text-align: right; font-size: 0.9rem;
            }
            td:last-child { border: none; padding-bottom: 0; }
            td:first-child { padding-top: 0; }

            /* Label Injection */
            td::before {
                content: attr(data-label);
                font-weight: 700; text-transform: uppercase;
                font-size: 0.75rem; color: var(--text-muted);
                text-align: left;
            }

            .item-list { max-width: 60%; }
            .status-select { width: 130px; }
        }
    </style>
</head>
<body>

    <!-- AUTH SECTION -->
    <div id="authOverlay">
        <!-- Step 1: Firebase login -->
        <div class="login-box" id="loginBox">
            <div class="logo" style="justify-content:center;color:var(--text-main);margin-bottom:15px;">
                <i class="fas fa-gamepad"></i> GameServices
            </div>
            <h2 style="margin-bottom:25px;letter-spacing:-1px;">Admin Login</h2>
            <input type="email" id="adminEmail" placeholder="Email Address">
            <input type="password" id="adminPassword" placeholder="Password">
            <p id="authError" style="color:var(--danger);font-size:0.85rem;margin-bottom:15px;font-weight:600;"></p>
            <button class="btn-add" id="loginBtn" style="width:100%;justify-content:center;">Sign In</button>
        </div>

        <!-- Step 2: 2FA TOTP -->
        <div class="login-box" id="totpBox" style="display:none;">
            <div class="logo" style="justify-content:center;color:var(--text-main);margin-bottom:15px;">
                <i class="fas fa-shield-alt" style="color:var(--primary)"></i>
            </div>
            <h2 style="margin-bottom:8px;letter-spacing:-1px;">Two-Factor Auth</h2>
            <p style="color:var(--text-muted);font-size:0.9rem;margin-bottom:25px;">Enter the 6-digit code from your authenticator app</p>
            <input type="text" id="totpCode" placeholder="000000" maxlength="6"
                style="letter-spacing:8px;font-size:1.4rem;text-align:center;font-weight:700;">
            <p id="totpError" style="color:var(--danger);font-size:0.85rem;margin-bottom:15px;font-weight:600;"></p>
            <button class="btn-add" id="totpBtn" style="width:100%;justify-content:center;">Verify</button>
            <p style="margin-top:15px;font-size:0.8rem;color:var(--text-muted);">
                Use Google Authenticator or Authy
            </p>
        </div>
    </div>

    <!-- SIDEBAR -->
    <div class="sidebar" id="sidebar">
        <div class="logo"><i class="fas fa-gamepad"></i> GameServices</div>
        <ul class="nav-links">
            <li class="nav-item active" data-target="dashboard">
                <i class="fas fa-chart-line"></i> <span>Dashboard</span>
            </li>
            <li class="nav-item" data-target="waorders">
                <i class="fab fa-whatsapp"></i> <span>WA Orders</span>
            </li>
            <li class="nav-item" data-target="games">
                <i class="fas fa-gamepad"></i> <span>Games</span>
            </li>
            <li class="nav-item" data-target="services">
                <i class="fas fa-tools"></i> <span>Services</span>
            </li>
            <li class="nav-item" data-target="settings">
                <i class="fas fa-cog"></i> <span>Settings</span>
            </li>
            <li class="nav-item logout-btn" id="logoutBtn">
                <i class="fas fa-sign-out-alt"></i> <span>Logout</span>
            </li>
        </ul>
    </div>

    <div class="main-wrapper">
        <div class="top-bar">
            <div class="mobile-toggle" id="menuToggle"><i class="fas fa-bars"></i></div>
            <div style="font-weight: 700; color: var(--text-muted);">Admin Panel v2.0</div>
            <div id="userEmailDisplay" style="font-size: 0.85rem; font-weight: 600;"></div>
        </div>

        <div class="content-area">

            <!-- DASHBOARD -->
            <div id="dashboard" class="section active">
                <div class="header-flex"><h1>Overview</h1></div>
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-indian-rupee-sign"></i></div>
                        <div class="stat-info"><h3 id="stat-revenue">₹0</h3><p>Total Revenue</p></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-bag-shopping"></i></div>
                        <div class="stat-info"><h3 id="stat-orders">0</h3><p>Total Orders</p></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-clock"></i></div>
                        <div class="stat-info"><h3 id="stat-pending">0</h3><p>Pending</p></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
                        <div class="stat-info"><h3 id="stat-completed">0</h3><p>Completed</p></div>
                    </div>
                </div>
            </div>

            <!-- WA ORDERS -->
            <div id="waorders" class="section">
                <div class="header-flex"><h1>WhatsApp Orders</h1></div>
                <div class="table-container">
                    <table>
                        <thead><tr>
                            <th>Time</th><th>Type</th><th>Customer</th>
                            <th>Details</th><th>Amount</th><th>Status</th>
                        </tr></thead>
                        <tbody id="waOrdersBody"></tbody>
                    </table>
                </div>
            </div>

            <!-- GAMES -->
            <div id="games" class="section">
                <div class="header-flex">
                    <h1>Games</h1>
                    <button class="btn-add" id="openGameModal"><i class="fas fa-plus"></i> Add Game</button>
                </div>
                <div id="gamesContainer"></div>
            </div>

            <!-- SERVICES -->
            <div id="services" class="section">
                <div class="header-flex">
                    <h1>Services</h1>
                    <button class="btn-add" id="openServiceModal"><i class="fas fa-plus"></i> Add Service</button>
                </div>
                <div class="menu-grid" id="servicesGrid"></div>
            </div>

            <!-- SETTINGS -->
            <div id="settings" class="section">
                <div class="header-flex"><h1>Bot Settings</h1></div>
                <div style="background:white;border-radius:20px;padding:30px;border:1px solid var(--border);max-width:500px;">
                    <div style="margin-bottom:20px;">
                        <label style="font-weight:700;display:block;margin-bottom:8px;">Owner / Business Name</label>
                        <input type="text" id="settingOwner" placeholder="e.g. Susant" style="width:100%;padding:14px;border:1px solid var(--border);border-radius:12px;font-size:1rem;box-sizing:border-box;">
                    </div>
                    <div style="margin-bottom:20px;">
                        <label style="font-weight:700;display:block;margin-bottom:8px;">UPI ID</label>
                        <input type="text" id="settingUpi" placeholder="yourname@upi" style="width:100%;padding:14px;border:1px solid var(--border);border-radius:12px;font-size:1rem;box-sizing:border-box;">
                    </div>
                    <div style="margin-bottom:20px;">
                        <label style="font-weight:700;display:block;margin-bottom:8px;">Payment QR Image</label>
                        <div id="qrPreviewWrap" style="display:none;margin-bottom:10px;">
                            <img id="qrPreview" src="" style="width:160px;height:160px;object-fit:contain;border-radius:12px;border:2px solid var(--primary);">
                        </div>
                        <input type="file" id="qrFileInput" accept="image/*" style="display:none;">
                        <button type="button" class="btn-add" id="qrUploadBtn" style="background:#3b82f6;margin-bottom:8px;">
                            <i class="fas fa-upload"></i> Upload QR Image
                        </button>
                        <p id="qrUploadStatus" style="font-size:0.85rem;color:var(--text-muted);margin:0;"></p>
                    </div>
                    <button class="btn-add" id="saveSettingsBtn" style="width:100%;justify-content:center;">Save Settings</button>
                    <p id="settingsSaved" style="color:var(--primary);font-weight:700;margin-top:12px;display:none;">✅ Saved!</p>
                </div>
            </div>

        </div>
    </div>

    <!-- MODAL: ADD GAME -->
    <div id="gameModal" class="modal">
        <div class="modal-content">
            <span class="close-modal">&times;</span>
            <h2 style="margin-bottom:25px;">Add Game</h2>
            <input type="text" id="gameName" placeholder="Game Name (e.g. Free Fire)" class="login-box" style="box-shadow:none;padding:12px;margin-bottom:20px;">
            <button class="btn-add" id="saveGameBtn" style="width:100%;justify-content:center;">Save Game</button>
        </div>
    </div>

    <!-- MODAL: ADD PACKAGE (inside a game) -->
    <div id="pkgModal" class="modal">
        <div class="modal-content">
            <span class="close-modal">&times;</span>
            <h2 style="margin-bottom:5px;">Add Package</h2>
            <p id="pkgGameLabel" style="color:var(--text-muted);margin-bottom:20px;font-weight:600;"></p>
            <input type="text" id="pkgLabel" placeholder="Label (e.g. 100 Diamonds / 1 Week)" class="login-box" style="box-shadow:none;padding:12px;margin-bottom:10px;">
            <input type="number" id="pkgPrice" placeholder="Price (₹)" class="login-box" style="box-shadow:none;padding:12px;margin-bottom:20px;">
            <button class="btn-add" id="savePkgBtn" style="width:100%;justify-content:center;">Save Package</button>
        </div>
    </div>

    <!-- MODAL: ADD SERVICE -->
    <div id="serviceModal" class="modal">
        <div class="modal-content">
            <span class="close-modal">&times;</span>
            <h2 style="margin-bottom:25px;">Add Service</h2>
            <input type="text" id="serviceName" placeholder="Service Name" class="login-box" style="box-shadow:none;padding:12px;margin-bottom:10px;">
            <input type="number" id="servicePrice" placeholder="Price (₹)" class="login-box" style="box-shadow:none;padding:12px;margin-bottom:10px;">
            <input type="text" id="serviceDesc" placeholder="Short description" class="login-box" style="box-shadow:none;padding:12px;margin-bottom:20px;">
            <button class="btn-add" id="saveServiceBtn" style="width:100%;justify-content:center;">Save Service</button>
        </div>
    </div>

    <script src="https://www.gstatic.com/firebasejs/8.10.0/firebase-app.js"></script>
    <script src="https://www.gstatic.com/firebasejs/8.10.0/firebase-auth.js"></script>
    <script src="https://www.gstatic.com/firebasejs/8.10.0/firebase-database.js"></script>
    <script src="https://www.gstatic.com/firebasejs/8.10.0/firebase-storage.js"></script>

    <script>
        const firebaseConfig = <?php echo json_encode($firebaseConfig); ?>;
        firebase.initializeApp(firebaseConfig);
        const auth    = firebase.auth();
        const db      = firebase.database();
        const storage = firebase.storage();
        const $       = id => document.getElementById(id);

        // ── Navigation ──────────────────────────────────────────
        document.querySelectorAll('.nav-item:not(.logout-btn)').forEach(item => {
            item.addEventListener('click', () => {
                document.querySelectorAll('.nav-item').forEach(i => i.classList.remove('active'));
                item.classList.add('active');
                document.querySelectorAll('.section').forEach(s => s.classList.remove('active'));
                $(item.dataset.target).classList.add('active');
                if (window.innerWidth < 992) $('sidebar').classList.remove('active');
            });
        });
        $('menuToggle').onclick = () => $('sidebar').classList.toggle('active');

        // ── Auth ────────────────────────────────────────────────
        auth.onAuthStateChanged(user => {
            if (user) {
                $('authOverlay').style.display = 'none';
                $('userEmailDisplay').textContent = user.email;
                initData();
            } else {
                $('authOverlay').style.display = 'flex';
                $('loginBox').style.display = 'block';
                $('totpBox').style.display = 'none';
            }
        });

        $('loginBtn').onclick = async () => {
            const email = $('adminEmail').value.trim();
            const pass  = $('adminPassword').value;

            if (!email || !pass) {
                $('authError').textContent = 'Please enter email and password.';
                return;
            }

            // Server-side rate limit check
            try {
                const rl = await fetch('admin.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'admin_login_check=1'
                });
                const rlData = await rl.json();
                if (rlData.blocked) {
                    $('authError').textContent = 'Too many login attempts. Try again in 15 minutes.';
                    return;
                }
            } catch (e) {}

            try {
                await auth.signInWithEmailAndPassword(email, pass);
                // Firebase login OK — now check 2FA
                $('loginBox').style.display = 'none';
                $('totpBox').style.display  = 'block';
                $('totpCode').focus();
                // Sign out immediately — only let through after 2FA passes
                await auth.signOut();
            } catch (e) {
                $('authError').textContent = 'Invalid credentials.';
                console.warn('[AUTH] Login failed:', e.code);
            }
        };

        // Store credentials temporarily to re-login after 2FA
        let _pendingEmail = '', _pendingPass = '';
        $('loginBtn').addEventListener('click', () => {
            _pendingEmail = $('adminEmail').value.trim();
            _pendingPass  = $('adminPassword').value;
        }, true); // capture phase — runs before onclick

        $('totpBtn').onclick = async () => {
            const code = $('totpCode').value.trim();
            if (code.length !== 6) {
                $('totpError').textContent = 'Enter the 6-digit code.';
                return;
            }

            try {
                const res = await fetch('admin.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `totp_check=1&code=${encodeURIComponent(code)}`
                });
                const data = await res.json();

                if (data.blocked) {
                    $('totpError').textContent = 'Too many attempts. Try again later.';
                    return;
                }

                if (!data.configured) {
                    // 2FA not set up on server — skip and log in directly
                    await auth.signInWithEmailAndPassword(_pendingEmail, _pendingPass);
                    return;
                }

                if (!data.valid) {
                    $('totpError').textContent = 'Invalid code. Try again.';
                    $('totpCode').value = '';
                    return;
                }

                // 2FA passed — complete login
                await auth.signInWithEmailAndPassword(_pendingEmail, _pendingPass);
                _pendingEmail = ''; _pendingPass = '';

            } catch (e) {
                $('totpError').textContent = 'Verification failed. Try again.';
            }
        };

        // Allow pressing Enter on TOTP input
        $('totpCode').addEventListener('keydown', e => {
            if (e.key === 'Enter') $('totpBtn').click();
        });

        $('logoutBtn').onclick = () => auth.signOut();

        // ── Init ────────────────────────────────────────────────
        function initData() {
            loadWAOrders();
            loadGames();
            loadServices();
            loadSettings();
        }

        // ── WA ORDERS ───────────────────────────────────────────
        function loadWAOrders() {
            db.ref('orders').on('value', snap => {
                const body = $('waOrdersBody');
                body.innerHTML = '';
                let revenue = 0, total = 0, pending = 0, completed = 0;

                const all = [];
                snap.forEach(c => all.push({ id: c.key, ...c.val() }));
                all.sort((a, b) => new Date(b.timestamp) - new Date(a.timestamp));

                all.forEach(o => {
                    const price = parseFloat(o.price || 0);
                    revenue += price; total++;
                    if ((o.status || 'Pending') === 'Pending') pending++;
                    if (o.status === 'Completed') completed++;

                    const typeLabel = o.type === 'topup' ? '🎮 Top-Up'
                                    : o.type === 'service' ? '🛠️ Service'
                                    : '📩 Custom';
                    const details = o.type === 'topup'
                        ? `${o.game} — ${o.package}<br><small style="color:var(--text-muted)">UID: ${o.uid || '-'}</small>`
                        : o.type === 'service' ? o.item
                        : o.message || '-';
                    const status = o.status || 'Pending';
                    const sc = status === 'Completed' ? 'status-delivered'
                              : status === 'Processing' ? 'status-preparing' : 'status-placed';

                    const tr = document.createElement('tr');
                    tr.innerHTML = `
                        <td data-label="Time" style="font-size:0.8rem;color:var(--text-muted)">${new Date(o.timestamp).toLocaleString()}</td>
                        <td data-label="Type">${typeLabel}</td>
                        <td data-label="Customer">
                            <span style="font-weight:700;display:block">${o.name || '-'}</span>
                            <span style="font-size:0.82rem;color:var(--text-muted)">${o.phone || o.waNumber || '-'}</span>
                        </td>
                        <td data-label="Details">${details}</td>
                        <td data-label="Amount" style="font-weight:800;color:var(--primary-dark)">₹${price}</td>
                        <td data-label="Status">
                            <select class="status-select ${sc}" onchange="updateOrderStatus('${o.id}',this.value)">
                                <option value="Pending"    ${status==='Pending'    ?'selected':''}>Pending</option>
                                <option value="Processing" ${status==='Processing' ?'selected':''}>Processing</option>
                                <option value="Completed"  ${status==='Completed'  ?'selected':''}>Completed</option>
                            </select>
                        </td>`;
                    body.appendChild(tr);
                });

                $('stat-revenue').textContent   = '₹' + revenue.toLocaleString();
                $('stat-orders').textContent    = total;
                $('stat-pending').textContent   = pending;
                $('stat-completed').textContent = completed;
            });
        }

        // ── Input sanitization helper ────────────────────────────
        const sanitize = str => String(str).replace(/[<>"'`]/g, '').trim().slice(0, 200);
        const sanitizeNum = val => { const n = parseFloat(val); return isNaN(n) || n < 0 ? null : n; };

        window.updateOrderStatus = (id, val) => {
            const allowed = ['Pending', 'Processing', 'Completed'];
            if (!allowed.includes(val)) return; // reject unexpected values
            db.ref('orders/' + id).update({ status: val });
        };
        window.deleteItem = (path, id) => { if (confirm('Delete permanently?')) db.ref(`${path}/${id}`).remove(); };

        // ── GAMES ───────────────────────────────────────────────
        function loadGames() {
            db.ref('games').on('value', snap => {
                const container = $('gamesContainer');
                container.innerHTML = '';
                snap.forEach(gameSnap => {
                    const game = gameSnap.val();
                    const gameId = gameSnap.key;
                    const packages = game.packages
                        ? Object.keys(game.packages).map(k => ({ id: k, ...game.packages[k] }))
                        : [];

                    const pkgHtml = packages.map(p => `
                        <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid var(--border);">
                            <span style="font-weight:600">${p.label}</span>
                            <div style="display:flex;align-items:center;gap:12px;">
                                <span style="font-weight:800;color:var(--primary)">₹${p.price}</span>
                                <button onclick="deleteItem('games/${gameId}/packages','${p.id}')" style="background:none;border:none;color:var(--danger);cursor:pointer;font-size:1rem;"><i class="fas fa-trash"></i></button>
                            </div>
                        </div>`).join('');

                    container.innerHTML += `
                        <div style="background:white;border-radius:20px;border:1px solid var(--border);padding:24px;margin-bottom:20px;">
                            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
                                <h3 style="margin:0;font-size:1.2rem;">🎮 ${game.name}</h3>
                                <div style="display:flex;gap:10px;">
                                    <button class="btn-add" style="padding:8px 16px;font-size:0.85rem;" onclick="openPkgModal('${gameId}','${game.name}')">
                                        <i class="fas fa-plus"></i> Add Package
                                    </button>
                                    <button onclick="deleteItem('games','${gameId}')" style="background:var(--danger);color:white;border:none;padding:8px 14px;border-radius:10px;cursor:pointer;">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </div>
                            ${pkgHtml || '<p style="color:var(--text-muted);font-size:0.9rem;">No packages yet. Add one above.</p>'}
                        </div>`;
                });
            });
        }

        // Game modal
        const gameM = $('gameModal');
        $('openGameModal').onclick = () => gameM.style.display = 'flex';
        $('saveGameBtn').onclick = () => {
            const name = sanitize($('gameName').value);
            if (!name) return;
            db.ref('games').push({ name, packages: {} }).then(() => {
                gameM.style.display = 'none';
                $('gameName').value = '';
            });
        };

        // Package modal
        let _pkgGameId = null;
        const pkgM = $('pkgModal');
        window.openPkgModal = (gameId, gameName) => {
            _pkgGameId = gameId;
            $('pkgGameLabel').textContent = gameName;
            $('pkgLabel').value = ''; $('pkgPrice').value = '';
            pkgM.style.display = 'flex';
        };
        $('savePkgBtn').onclick = () => {
            const label = sanitize($('pkgLabel').value);
            const price = sanitizeNum($('pkgPrice').value);
            if (!label || price === null || !_pkgGameId) return;
            db.ref(`games/${_pkgGameId}/packages`).push({ label, price }).then(() => {
                pkgM.style.display = 'none';
            });
        };

        // ── SERVICES ────────────────────────────────────────────
        function loadServices() {
            db.ref('services').on('value', snap => {
                const grid = $('servicesGrid');
                grid.innerHTML = '';
                snap.forEach(child => {
                    const s = child.val();
                    grid.innerHTML += `
                        <div class="admin-card" style="padding:20px;">
                            <button class="delete-btn" onclick="deleteItem('services','${child.key}')"><i class="fas fa-trash"></i></button>
                            <div style="font-size:2rem;margin-bottom:10px;">🛠️</div>
                            <h4 style="margin:0 0 5px;">${s.name}</h4>
                            <p style="margin:0 0 8px;color:var(--text-muted);font-size:0.85rem;">${s.description || ''}</p>
                            <span style="font-weight:800;color:var(--primary)">₹${s.price}</span>
                        </div>`;
                });
            });
        }

        const serviceM = $('serviceModal');
        $('openServiceModal').onclick = () => serviceM.style.display = 'flex';
        $('saveServiceBtn').onclick = () => {
            const name        = sanitize($('serviceName').value);
            const price       = sanitizeNum($('servicePrice').value);
            const description = sanitize($('serviceDesc').value);
            if (!name || price === null) return;
            db.ref('services').push({ name, price, description }).then(() => {
                serviceM.style.display = 'none';
                $('serviceName').value = ''; $('servicePrice').value = ''; $('serviceDesc').value = '';
            });
        };

        // ── SETTINGS ────────────────────────────────────────────
        function loadSettings() {
            db.ref('settings').once('value', snap => {
                const s = snap.val() || {};
                $('settingOwner').value = s.owner || '';
                $('settingUpi').value   = s.upi   || '';
                if (s.qr_image_url) {
                    $('qrPreview').src = s.qr_image_url;
                    $('qrPreviewWrap').style.display = 'block';
                }
            });
        }

        // ── QR image upload to Firebase Storage ─────────────────
        $('qrUploadBtn').onclick = () => $('qrFileInput').click();
        $('qrFileInput').onchange = async (e) => {
            const file = e.target.files[0];
            if (!file) return;
            if (!file.type.startsWith('image/')) {
                $('qrUploadStatus').textContent = 'Please select an image file.';
                return;
            }
            if (file.size > 2 * 1024 * 1024) {
                $('qrUploadStatus').textContent = 'Image must be under 2MB.';
                return;
            }
            $('qrUploadStatus').textContent = 'Uploading...';
            $('qrUploadBtn').disabled = true;
            try {
                const ref = storage.ref('qr/payment_qr.jpg');
                await ref.put(file);
                const url = await ref.getDownloadURL();
                const s = (await db.ref('settings').once('value')).val() || {};
                await db.ref('settings').update({ ...s, qr_image_url: url });
                $('qrPreview').src = url;
                $('qrPreviewWrap').style.display = 'block';
                $('qrUploadStatus').textContent = '✅ Uploaded and saved!';
            } catch (err) {
                $('qrUploadStatus').textContent = 'Upload failed. Check Firebase Storage rules.';
                console.error(err);
            }
            $('qrUploadBtn').disabled = false;
        };

        $('saveSettingsBtn').onclick = () => {
            const owner = sanitize($('settingOwner').value);
            const upi   = sanitize($('settingUpi').value);
            if (!owner) return;
            db.ref('settings').update({ owner, upi }).then(() => {
                $('settingsSaved').style.display = 'block';
                setTimeout(() => $('settingsSaved').style.display = 'none', 2000);
            });
        };

        // ── Close all modals ─────────────────────────────────────
        document.querySelectorAll('.close-modal').forEach(b => {
            b.onclick = () => document.querySelectorAll('.modal').forEach(m => m.style.display = 'none');
        });

    </script>
</body>
</html>

        <div class="modal-content">
            <span class="close-modal">&times;</span>
            <h2 style="margin-bottom:25px;">New Hack Panel</h2>
            <input type="text" id="panelName" placeholder="Panel Name (e.g. Headshot Panel)" class="login-box" style="box-shadow:none;padding:12px;margin-bottom:10px;">
            <input type="number" id="panelPrice" placeholder="Price (₹)" class="login-box" style="box-shadow:none;padding:12px;margin-bottom:10px;">
            <input type="text" id="panelDesc" placeholder="Short description" class="login-box" style="box-shadow:none;padding:12px;margin-bottom:20px;">
            <button class="btn-add" id="savePanelBtn" style="width:100%;justify-content:center;">Save Panel</button>
        </div>
    </div>

    <!-- MODAL: ADD TOPUP PACKAGE -->
    <div id="topupModal" class="modal">
        <div class="modal-content">
            <span class="close-modal">&times;</span>
            <h2 style="margin-bottom:25px;">New Diamond Package</h2>
            <input type="number" id="topupDiamonds" placeholder="Diamonds (e.g. 100)" class="login-box" style="box-shadow:none;padding:12px;margin-bottom:10px;">
            <input type="number" id="topupPrice" placeholder="Price (₹)" class="login-box" style="box-shadow:none;padding:12px;margin-bottom:20px;">
            <button class="btn-add" id="saveTopupBtn" style="width:100%;justify-content:center;">Save Package</button>
        </div>
    </div>
</body>
</html>
