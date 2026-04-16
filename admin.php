<?php
// ── Load .env file (shared hosting compatible) ───────────────
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $handle = fopen($envFile, 'r');
    if ($handle) {
        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
            [$key, $val] = explode('=', $line, 2);
            $_ENV[trim($key)] = trim($val);
            putenv(trim($key) . '=' . trim($val));
        }
        fclose($handle);
    }
}
function env($key, $default = '') {
    return $_ENV[$key] ?? getenv($key) ?: $default;
}

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
  "apiKey"            => env('FIREBASE_API_KEY'),
  "authDomain"        => env('FIREBASE_AUTH_DOMAIN'),
  "databaseURL"       => env('FIREBASE_DATABASE_URL'),
  "projectId"         => env('FIREBASE_PROJECT_ID'),
  "storageBucket"     => env('FIREBASE_STORAGE_BUCKET'),
  "messagingSenderId" => env('FIREBASE_MESSAGING_SENDER_ID'),
  "appId"             => env('FIREBASE_APP_ID'),
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
    <title>⚔️ Game Panel | Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Rajdhani:wght@400;500;600;700&family=Orbitron:wght@400;700;900&display=swap');

        :root {
            --primary: #e63946;
            --primary-dark: #c1121f;
            --primary-soft: rgba(230,57,70,0.15);
            --bg-body: #0a0a0a;
            --bg-card: #111111;
            --bg-sidebar: #0d0d0d;
            --text-main: #f0f0f0;
            --text-muted: #888;
            --border: rgba(230,57,70,0.2);
            --sidebar-width: 260px;
            --danger: #e63946;
            --warning: #f59e0b;
            --info: #3b82f6;
            --glow: 0 0 20px rgba(230,57,70,0.4);
        }

        * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }

        body {
            margin: 0;
            font-family: 'Rajdhani', sans-serif;
            background-color: var(--bg-body);
            background-image:
                radial-gradient(ellipse at 20% 50%, rgba(230,57,70,0.05) 0%, transparent 60%),
                radial-gradient(ellipse at 80% 20%, rgba(180,0,0,0.05) 0%, transparent 50%);
            color: var(--text-main);
            display: flex;
            height: 100vh;
            overflow: hidden;
        }

        /* --- Scrollbar --- */
        ::-webkit-scrollbar { width: 4px; }
        ::-webkit-scrollbar-track { background: #0a0a0a; }
        ::-webkit-scrollbar-thumb { background: var(--primary); border-radius: 4px; }

        /* --- Sidebar --- */
        .sidebar {
            width: var(--sidebar-width);
            background: linear-gradient(180deg, #0d0d0d 0%, #0a0a0a 100%);
            border-right: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            padding: 24px;
            transition: all 0.3s ease;
            z-index: 100;
            position: relative;
        }
        .sidebar::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 2px;
            background: linear-gradient(90deg, transparent, var(--primary), transparent);
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
            font-family: 'Orbitron', sans-serif;
            font-size: 1.1rem;
            font-weight: 900;
            color: white;
            margin-bottom: 40px;
            letter-spacing: 2px;
            text-transform: uppercase;
        }
        .logo i { color: var(--primary); filter: drop-shadow(0 0 8px var(--primary)); }

        .nav-links { list-style: none; padding: 0; margin: 0; flex: 1; }
        .nav-item {
            display: flex;
            align-items: center;
            padding: 12px 16px;
            color: #666;
            text-decoration: none;
            border-radius: 8px;
            margin-bottom: 6px;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.95rem;
            letter-spacing: 1px;
            text-transform: uppercase;
            transition: all 0.2s;
            border: 1px solid transparent;
        }
        .nav-item i { width: 24px; font-size: 1rem; }
        .nav-item:hover { color: var(--primary); border-color: var(--border); background: var(--primary-soft); }
        .nav-item.active {
            background: var(--primary-soft);
            color: var(--primary);
            border-color: var(--primary);
            box-shadow: var(--glow);
        }
        .logout-btn { color: #555; margin-top: auto; }
        .logout-btn:hover { color: var(--primary) !important; }

        /* --- Main Layout --- */
        .main-wrapper { flex: 1; display: flex; flex-direction: column; overflow: hidden; position: relative; }

        .top-bar {
            height: 65px;
            background: #0d0d0d;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            padding: 0 40px;
            justify-content: space-between;
        }

        .mobile-toggle { display: none; font-size: 1.5rem; cursor: pointer; color: var(--text-main); }

        .content-area { flex: 1; overflow-y: auto; padding: 30px 40px; }

        /* --- Sections --- */
        .section { display: none; }
        .section.active { display: block; animation: fadeIn 0.3s ease; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }

        .header-flex { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; }
        .header-flex h1 {
            font-family: 'Orbitron', sans-serif;
            font-size: 1.4rem;
            font-weight: 700;
            margin: 0;
            letter-spacing: 3px;
            text-transform: uppercase;
            color: var(--text-main);
        }

        /* --- Stats --- */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 30px; }
        .stat-card {
            background: var(--bg-card);
            padding: 20px 24px;
            border-radius: 12px;
            border: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 16px;
            position: relative;
            overflow: hidden;
            transition: border-color 0.2s;
        }
        .stat-card::before {
            content: '';
            position: absolute;
            left: 0; top: 0; bottom: 0;
            width: 3px;
            background: var(--primary);
        }
        .stat-card:hover { border-color: var(--primary); }
        .stat-icon {
            width: 48px; height: 48px;
            background: var(--primary-soft);
            color: var(--primary);
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.3rem;
            border: 1px solid rgba(230,57,70,0.3);
        }
        .stat-info h3 { margin: 0; font-size: 1.5rem; font-weight: 800; font-family: 'Orbitron', sans-serif; color: #fff; }
        .stat-info p { margin: 3px 0 0; color: var(--text-muted); font-size: 0.8rem; font-weight: 600; letter-spacing: 1px; text-transform: uppercase; }

        /* --- Orders Table --- */
        .table-container {
            background: var(--bg-card);
            border-radius: 12px;
            border: 1px solid var(--border);
            overflow: hidden;
        }
        table { width: 100%; border-collapse: collapse; }
        th {
            background: #0d0d0d;
            padding: 14px 20px;
            text-align: left;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 2px;
            color: var(--primary);
            border-bottom: 1px solid var(--border);
            font-family: 'Orbitron', sans-serif;
        }
        td {
            padding: 16px 20px;
            border-bottom: 1px solid rgba(255,255,255,0.04);
            font-size: 0.9rem;
            vertical-align: middle;
            color: var(--text-main);
        }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background: rgba(230,57,70,0.03); }

        .customer-info .name { font-weight: 700; display: block; color: #fff; }
        .customer-info .phone { font-size: 0.82rem; color: var(--text-muted); }
        .item-list { font-size: 0.85rem; color: var(--text-muted); max-width: 250px; line-height: 1.5; }

        /* Status */
        .status-select {
            padding: 6px 10px;
            border-radius: 6px;
            font-weight: 700;
            font-size: 0.78rem;
            border: 1px solid transparent;
            cursor: pointer;
            outline: none;
            width: 140px;
            font-family: 'Rajdhani', sans-serif;
            letter-spacing: 1px;
        }
        .status-placed    { background: rgba(245,158,11,0.15); color: #f59e0b; border-color: rgba(245,158,11,0.3); }
        .status-preparing { background: rgba(99,102,241,0.15); color: #818cf8; border-color: rgba(99,102,241,0.3); }
        .status-delivery  { background: rgba(249,115,22,0.15); color: #fb923c; border-color: rgba(249,115,22,0.3); }
        .status-delivered { background: rgba(34,197,94,0.15);  color: #4ade80; border-color: rgba(34,197,94,0.3); }

        /* --- Buttons --- */
        .btn-add {
            background: transparent;
            color: var(--primary);
            padding: 10px 20px;
            border-radius: 8px;
            border: 1px solid var(--primary);
            font-weight: 700;
            font-family: 'Rajdhani', sans-serif;
            font-size: 0.9rem;
            letter-spacing: 1px;
            text-transform: uppercase;
            cursor: pointer;
            display: flex; align-items: center; gap: 8px;
            transition: all 0.2s;
        }
        .btn-add:hover {
            background: var(--primary);
            color: white;
            box-shadow: var(--glow);
        }

        /* --- Grid Cards --- */
        .menu-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 16px; }
        .admin-card {
            background: var(--bg-card);
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid var(--border);
            position: relative;
            transition: border-color 0.2s;
        }
        .admin-card:hover { border-color: var(--primary); }
        .admin-card img { width: 100%; height: 140px; object-fit: cover; }
        .admin-card-body { padding: 16px; }
        .admin-card h4 { margin: 0 0 5px; font-weight: 700; font-size: 1rem; color: #fff; }
        .delete-btn {
            position: absolute; top: 8px; right: 8px;
            background: rgba(0,0,0,0.7); color: var(--danger);
            border: 1px solid var(--danger); width: 30px; height: 30px;
            border-radius: 6px; cursor: pointer;
        }

        /* --- Auth Overlay --- */
        #authOverlay {
            position: fixed; inset: 0;
            background: radial-gradient(ellipse at center, #1a0000 0%, #0a0a0a 70%);
            z-index: 2000; display: flex; align-items: center; justify-content: center;
        }
        .login-box {
            background: #111;
            padding: 40px;
            border-radius: 16px;
            width: 100%; max-width: 400px;
            border: 1px solid var(--border);
            box-shadow: var(--glow);
            text-align: center;
        }
        .login-box input {
            width: 100%; padding: 14px 16px; margin-bottom: 12px;
            border: 1px solid var(--border); border-radius: 8px;
            font-family: 'Rajdhani', sans-serif; font-size: 1rem;
            background: #1a1a1a; color: #fff;
        }
        .login-box input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 2px rgba(230,57,70,0.2); }

        /* --- Modals --- */
        .modal {
            display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.8);
            z-index: 1000; align-items: center; justify-content: center; padding: 20px;
            backdrop-filter: blur(4px);
        }
        .modal-content {
            background: #111; padding: 30px; border-radius: 14px;
            width: 100%; max-width: 440px; position: relative;
            border: 1px solid var(--border); box-shadow: var(--glow);
        }
        .modal-content h2 { color: #fff; font-family: 'Orbitron', sans-serif; font-size: 1rem; letter-spacing: 2px; }
        .close-modal { position: absolute; right: 20px; top: 20px; cursor: pointer; font-size: 1.4rem; color: var(--text-muted); }
        .close-modal:hover { color: var(--primary); }

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
                background: #111; margin-bottom: 16px; padding: 16px;
                border-radius: 12px; border: 1px solid var(--border);
            }
            
            td {
                display: flex; justify-content: space-between; align-items: center;
                padding: 10px 0; border-bottom: 1px solid rgba(255,255,255,0.05);
                text-align: right; font-size: 0.9rem; color: var(--text-main);
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
            <div style="font-family:'Orbitron',sans-serif;font-size:1.3rem;font-weight:900;color:var(--primary);letter-spacing:3px;margin-bottom:5px;text-shadow:0 0 20px rgba(230,57,70,0.5);">
                ⚔️ GAME PANEL
            </div>
            <p style="color:#555;font-size:0.85rem;letter-spacing:2px;text-transform:uppercase;margin-bottom:25px;">Admin Access</p>
            <input type="email" id="adminEmail" placeholder="Email Address">
            <input type="password" id="adminPassword" placeholder="Password">
            <p id="authError" style="color:var(--primary);font-size:0.85rem;margin-bottom:12px;font-weight:600;"></p>
            <button class="btn-add" id="loginBtn" style="width:100%;justify-content:center;font-size:1rem;">
                <i class="fas fa-sign-in-alt"></i> ENTER
            </button>
        </div>

        <!-- Step 2: 2FA TOTP -->
        <div class="login-box" id="totpBox" style="display:none;">
            <div style="font-family:'Orbitron',sans-serif;font-size:1.1rem;font-weight:900;color:var(--primary);letter-spacing:3px;margin-bottom:5px;">
                🔐 2FA VERIFY
            </div>
            <p style="color:#555;font-size:0.82rem;letter-spacing:1px;margin-bottom:20px;">Enter code from authenticator app</p>
            <input type="text" id="totpCode" placeholder="000000" maxlength="6"
                style="letter-spacing:10px;font-size:1.6rem;text-align:center;font-weight:700;font-family:'Orbitron',sans-serif;">
            <p id="totpError" style="color:var(--primary);font-size:0.85rem;margin-bottom:12px;font-weight:600;"></p>
            <button class="btn-add" id="totpBtn" style="width:100%;justify-content:center;">
                <i class="fas fa-shield-alt"></i> VERIFY
            </button>
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
            <li class="nav-item" data-target="broadcast">
                <i class="fas fa-bullhorn"></i> <span>Broadcast</span>
            </li>
            <li class="nav-item logout-btn" id="logoutBtn">
                <i class="fas fa-sign-out-alt"></i> <span>Logout</span>
            </li>
        </ul>
    </div>

    <div class="main-wrapper">
        <div class="top-bar">
            <div class="mobile-toggle" id="menuToggle"><i class="fas fa-bars"></i></div>
            <div style="font-family:'Orbitron',sans-serif;font-weight:700;color:var(--primary);letter-spacing:3px;font-size:0.85rem;">⚔️ GAME PANEL</div>
            <div id="userEmailDisplay" style="font-size:0.82rem;font-weight:600;color:var(--text-muted);"></div>
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
                    <h1>Services / Panels</h1>
                    <button class="btn-add" id="openServiceModal"><i class="fas fa-plus"></i> Add Panel</button>
                </div>
                <div id="servicesContainer"></div>
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
                    <div style="margin-top:20px;padding-top:20px;border-top:1px solid var(--border);">
                        <p style="color:var(--text-muted);font-size:0.8rem;letter-spacing:1px;margin-bottom:12px;">SEED DEFAULT DATA — loads example panels and packages you can edit</p>
                        <button class="btn-add" id="seedDataBtn" style="width:100%;justify-content:center;border-color:#f59e0b;color:#f59e0b;">
                            <i class="fas fa-database"></i> Load Default Panels & Packages
                        </button>
                        <p id="seedStatus" style="color:#f59e0b;font-weight:700;margin-top:10px;display:none;"></p>
                    </div>
                </div>
            </div>

            <!-- BROADCAST -->
            <div id="broadcast" class="section">
                <div class="header-flex"><h1>Broadcast</h1></div>
                <div style="background:var(--bg-card);border-radius:12px;padding:24px;border:1px solid var(--border);max-width:500px;">
                    <p style="color:var(--text-muted);font-size:0.85rem;margin-bottom:20px;letter-spacing:1px;">Send a message to all customers who have ordered before. The bot will deliver it on WhatsApp.</p>
                    <div style="margin-bottom:16px;">
                        <label style="font-weight:700;display:block;margin-bottom:8px;font-size:0.8rem;letter-spacing:1px;text-transform:uppercase;color:var(--text-muted);">Message</label>
                        <textarea id="broadcastMsg" rows="5" placeholder="Type your message here..." style="width:100%;padding:14px;background:#1a1a1a;border:1px solid var(--border);border-radius:8px;color:#fff;font-family:'Rajdhani',sans-serif;font-size:1rem;resize:vertical;box-sizing:border-box;"></textarea>
                    </div>
                    <button class="btn-add" id="sendBroadcastBtn" style="width:100%;justify-content:center;">
                        <i class="fab fa-whatsapp"></i> Send to All Customers
                    </button>
                    <p id="broadcastStatus" style="color:var(--primary);font-weight:700;margin-top:12px;display:none;"></p>
                </div>
                <div style="margin-top:20px;">
                    <div style="font-family:'Orbitron',sans-serif;font-size:0.75rem;letter-spacing:2px;color:var(--red);margin-bottom:12px;">CUSTOMER LIST</div>
                    <div id="customerList" style="color:var(--text-muted);font-size:0.85rem;"></div>
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
            <h2 style="margin-bottom:25px;">Add Panel / Service</h2>
            <input type="text" id="serviceName" placeholder="Panel Name (e.g. DRIP CLIENT)" class="login-box" style="box-shadow:none;padding:12px;margin-bottom:10px;">
            <input type="text" id="serviceDesc" placeholder="Short description (e.g. Auto headshot, antiban)" class="login-box" style="box-shadow:none;padding:12px;margin-bottom:20px;">
            <button class="btn-add" id="saveServiceBtn" style="width:100%;justify-content:center;">Save Panel</button>
        </div>
    </div>

    <!-- MODAL: ADD SERVICE PACKAGE -->
    <div id="svcPkgModal" class="modal">
        <div class="modal-content">
            <span class="close-modal">&times;</span>
            <h2 style="margin-bottom:5px;">Add Package</h2>
            <p id="svcPkgLabel" style="color:var(--text-muted);margin-bottom:20px;font-weight:600;"></p>
            <input type="text" id="svcPkgDuration" placeholder="Duration (e.g. 1 Day / 3 Days / 7 Days)" class="login-box" style="box-shadow:none;padding:12px;margin-bottom:10px;">
            <input type="number" id="svcPkgPrice" placeholder="Price (₹)" class="login-box" style="box-shadow:none;padding:12px;margin-bottom:20px;">
            <button class="btn-add" id="saveSvcPkgBtn" style="width:100%;justify-content:center;">Save Package</button>
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
                        <div style="background:#111;border-radius:12px;border:1px solid var(--border);padding:20px;margin-bottom:16px;">
                            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
                                <h3 style="margin:0;font-size:1.1rem;color:#fff;font-family:'Orbitron',sans-serif;letter-spacing:1px;">🎮 ${game.name}</h3>
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
                const container = $('servicesContainer');
                container.innerHTML = '';
                snap.forEach(svcSnap => {
                    const svc = svcSnap.val();
                    const svcId = svcSnap.key;
                    const packages = svc.packages
                        ? Object.keys(svc.packages).map(k => ({ id: k, ...svc.packages[k] }))
                        : [];

                    const pkgHtml = packages.map(p => `
                        <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid var(--border);">
                            <span style="font-weight:600">${p.label}</span>
                            <div style="display:flex;align-items:center;gap:12px;">
                                <span style="font-weight:800;color:var(--primary)">₹${p.price}</span>
                                <button onclick="deleteItem('services/${svcId}/packages','${p.id}')" style="background:none;border:none;color:var(--danger);cursor:pointer;font-size:1rem;"><i class="fas fa-trash"></i></button>
                            </div>
                        </div>`).join('');

                    container.innerHTML += `
                        <div style="background:#111;border-radius:12px;border:1px solid var(--border);padding:20px;margin-bottom:16px;">
                            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
                                <div>
                                    <h3 style="margin:0 0 4px;font-size:1.1rem;color:#fff;font-family:'Orbitron',sans-serif;letter-spacing:1px;">🛠️ ${svc.name}</h3>
                                    ${svc.description ? `<p style="margin:0;color:var(--text-muted);font-size:0.85rem;">${svc.description}</p>` : ''}
                                </div>
                                <div style="display:flex;gap:10px;">
                                    <button class="btn-add" style="padding:8px 16px;font-size:0.85rem;" onclick="openSvcPkgModal('${svcId}','${svc.name}')">
                                        <i class="fas fa-plus"></i> Add Package
                                    </button>
                                    <button onclick="deleteItem('services','${svcId}')" style="background:var(--danger);color:white;border:none;padding:8px 14px;border-radius:10px;cursor:pointer;">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </div>
                            ${pkgHtml || '<p style="color:var(--text-muted);font-size:0.9rem;">No packages yet. Add one above.</p>'}
                        </div>`;
                });
            });
        }

        const serviceM = $('serviceModal');
        $('openServiceModal').onclick = () => serviceM.style.display = 'flex';
        $('saveServiceBtn').onclick = () => {
            const name        = sanitize($('serviceName').value);
            const description = sanitize($('serviceDesc').value);
            if (!name) return;
            db.ref('services').push({ name, description }).then(() => {
                serviceM.style.display = 'none';
                $('serviceName').value = ''; $('serviceDesc').value = '';
            });
        };

        // Service package modal
        let _svcPkgId = null;
        const svcPkgM = $('svcPkgModal');
        window.openSvcPkgModal = (svcId, svcName) => {
            _svcPkgId = svcId;
            $('svcPkgLabel').textContent = svcName;
            $('svcPkgDuration').value = ''; $('svcPkgPrice').value = '';
            svcPkgM.style.display = 'flex';
        };
        $('saveSvcPkgBtn').onclick = () => {
            const label = sanitize($('svcPkgDuration').value);
            const price = sanitizeNum($('svcPkgPrice').value);
            if (!label || price === null || !_svcPkgId) return;
            db.ref(`services/${_svcPkgId}/packages`).push({ label, price }).then(() => {
                svcPkgM.style.display = 'none';
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

        // ── SEED DEFAULT DATA ─────────────────────────────────────
        $('seedDataBtn').onclick = async () => {
            if (!confirm('This will add default panels and packages. Existing data will NOT be deleted. Continue?')) return;
            const status = $('seedStatus');
            status.textContent = 'Loading...'; status.style.display = 'block';

            const defaultServices = [
                { name: 'DRIP CLIENT NON ROOT', description: 'Free Fire panel — Auto headshot, aimbot, antiban', price: '299',
                  packages: [
                    { label: '1 Day',   price: '299'  },
                    { label: '3 Days',  price: '499'  },
                    { label: '7 Days',  price: '799'  },
                    { label: '15 Days', price: '889'  },
                    { label: '30 Days', price: '1399' }
                  ]
                },
                { name: 'HG CHEATS', description: 'Free Fire panel — Headshot, wallhack, speed', price: '399',
                  packages: [
                    { label: '1 Day',   price: '399'  },
                    { label: '3 Days',  price: '599'  },
                    { label: '7 Days',  price: '1199' },
                    { label: '15 Days', price: '1599' },
                    { label: '30 Days', price: '1999' }
                  ]
                },
                { name: 'GUILD GLORYBOT', description: 'Free Fire guild bot panel', price: '799',
                  packages: [
                    { label: '1 Squad',  price: '799'  },
                    { label: '2 Squads', price: '999' },
                    { label: '3 Squads', price: '1299' },
                    { label: '4 Squads', price: '1499' }
                  ]
                },
                { name: 'PATO TEAMS', description: 'Free Fire panel', price: '1000',
                  packages: [
                    { label: '3 Days',  price: '999' },
                    { label: '7 Days',  price: '1199' },
                    { label: '15 Days', price: '1599' },
                    { label: '30 Days', price: '1899' }
                  ]
                },
                { name: 'IOS FLUORITE', description: 'iOS Free Fire panel — Certificate included', price: '600',
                  packages: [
                    { label: '1 Days',  price: '699'  },
                    { label: '7 Days',  price: '1499' },
                    { label: '30 Days', price: '3999' }
                  ]
                }
            ];

            const defaultGames = [
                { name: 'Free Fire',
                  packages: [
                    { label: '100 Diamonds', price: '80' }, { label: '310 Diamonds', price: '220' },
                    { label: '520 Diamonds', price: '350' }, { label: '1060 Diamonds', price: '680' },
                    { label: '2180 Diamonds', price: '1350' }
                  ]
                },
                { name: 'PUBG Mobile',
                  packages: [
                    { label: '60 UC', price: '80' }, { label: '325 UC', price: '380' },
                    { label: '660 UC', price: '750' }, { label: '1800 UC', price: '1950' }
                  ]
                }
            ];

            // Add services (panels) with packages
            for (const svc of defaultServices) {
                const ref = db.ref('services').push();
                await ref.set({ name: svc.name, description: svc.description, price: svc.packages[0].price });
                for (const pkg of svc.packages) {
                    await ref.child('packages').push(pkg);
                }
            }

            // Add games with packages
            for (const game of defaultGames) {
                const gameRef = db.ref('games').push();
                await gameRef.set({ name: game.name });
                for (const pkg of game.packages) {
                    await gameRef.child('packages').push(pkg);
                }
            }

            status.textContent = '✅ Default data loaded! Go to Games and Services to edit prices.';
            setTimeout(() => status.style.display = 'none', 5000);
        };
        function loadCustomers() {
            db.ref('users').once('value', snap => {
                const list = $('customerList');
                if (!snap.exists()) { list.textContent = 'No customers yet.'; return; }
                let html = '';
                snap.forEach(child => {
                    const u = child.val();
                    html += `<div style="padding:8px 0;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;">
                        <span style="color:#fff;font-weight:600;">${u.name || 'Unknown'}</span>
                        <span style="color:var(--text-muted);">${child.key}</span>
                    </div>`;
                });
                list.innerHTML = html;
            });
        }

        // Load customers when broadcast tab is opened
        document.querySelectorAll('.nav-item').forEach(item => {
            item.addEventListener('click', () => {
                if (item.dataset.target === 'broadcast') loadCustomers();
            });
        });

        $('sendBroadcastBtn').onclick = async () => {
            const msg = $('broadcastMsg').value.trim();
            if (!msg) return;
            const status = $('broadcastStatus');
            status.textContent = 'Sending...';
            status.style.display = 'block';

            // Save broadcast to Firebase — bot picks it up
            await db.ref('broadcasts').push({
                message: msg,
                timestamp: new Date().toISOString(),
                status: 'pending'
            });

            status.textContent = '✅ Broadcast queued! Bot will deliver it on next run.';
            $('broadcastMsg').value = '';
            setTimeout(() => status.style.display = 'none', 4000);
        };

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
