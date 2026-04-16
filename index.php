<?php
// ── Load .env ────────────────────────────────────────────────
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $h = fopen($envFile, 'r');
    if ($h) {
        while (($line = fgets($h)) !== false) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
            [$k, $v] = explode('=', $line, 2);
            $_ENV[trim($k)] = trim($v);
            putenv(trim($k) . '=' . trim($v));
        }
        fclose($h);
    }
}
function env($k, $d = '') { return $_ENV[$k] ?? getenv($k) ?: $d; }

$firebaseConfig = [
  "apiKey"            => "AIzaSyBMKKuGr9Djv9_9PhmC3GFedLX6PPzV9n4",
  "authDomain"        => "whatsappagent-b7c36.firebaseapp.com",
  "databaseURL"       => "https://whatsappagent-b7c36-default-rtdb.firebaseio.com",
  "projectId"         => "whatsappagent-b7c36",
  "storageBucket"     => "whatsappagent-b7c36.firebasestorage.app",
  "messagingSenderId" => "116260179438",
  "appId"             => "1:116260179438:web:6ffd88d4ee4af3f4b792ff"
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>⚔️ Game Panel</title>
<link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@400;600;700&family=Orbitron:wght@700;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
<style>
*{box-sizing:border-box;margin:0;padding:0;-webkit-tap-highlight-color:transparent}
:root{
  --red:#e63946;--red-dark:#c1121f;--red-glow:rgba(230,57,70,0.4);
  --bg:#0a0a0a;--card:#111;--border:rgba(230,57,70,0.2);
  --text:#f0f0f0;--muted:#777;
}
body{font-family:'Rajdhani',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;display:flex;justify-content:center;align-items:flex-start;}

/* ── App Shell ── */
.app{width:100%;max-width:480px;min-height:100vh;background:var(--bg);position:relative;display:flex;flex-direction:column;overflow:hidden;}
@media(min-width:500px){.app{border-left:1px solid var(--border);border-right:1px solid var(--border);}}

/* ── Pages ── */
.page{position:absolute;inset:0;display:flex;flex-direction:column;opacity:0;pointer-events:none;transition:opacity 0.3s;}
.page.active{opacity:1;pointer-events:all;position:relative;}

/* ── Auth ── */
.auth-wrap{flex:1;display:flex;align-items:center;justify-content:center;padding:20px;background:radial-gradient(ellipse at center,#1a0000 0%,#0a0a0a 70%);}
.auth-card{background:#111;border:1px solid var(--border);border-radius:16px;padding:36px 28px;width:100%;max-width:380px;box-shadow:0 0 40px var(--red-glow);}
.auth-logo{text-align:center;margin-bottom:24px;}
.auth-logo .icon{font-size:2.5rem;color:var(--red);filter:drop-shadow(0 0 12px var(--red));}
.auth-logo h1{font-family:'Orbitron',sans-serif;font-size:1.3rem;letter-spacing:3px;color:#fff;margin-top:8px;}
.auth-logo p{color:var(--muted);font-size:0.85rem;letter-spacing:1px;margin-top:4px;}
.auth-err{color:var(--red);font-size:0.85rem;margin-bottom:12px;padding:10px;background:rgba(230,57,70,0.1);border-radius:8px;border:1px solid rgba(230,57,70,0.3);display:none;}
.inp{width:100%;padding:13px 16px;background:#1a1a1a;border:1px solid var(--border);border-radius:8px;color:#fff;font-family:'Rajdhani',sans-serif;font-size:1rem;margin-bottom:12px;}
.inp:focus{outline:none;border-color:var(--red);box-shadow:0 0 0 2px rgba(230,57,70,0.2);}
.btn{width:100%;padding:14px;border:1px solid var(--red);background:transparent;color:var(--red);font-family:'Orbitron',sans-serif;font-size:0.85rem;letter-spacing:2px;border-radius:8px;cursor:pointer;transition:all 0.2s;margin-bottom:10px;}
.btn:hover,.btn.solid{background:var(--red);color:#fff;box-shadow:0 0 20px var(--red-glow);}
.btn.google{border-color:#444;color:var(--text);display:flex;align-items:center;justify-content:center;gap:10px;}
.btn.google:hover{border-color:var(--red);background:rgba(230,57,70,0.05);}
.auth-switch{text-align:center;font-size:0.9rem;color:var(--muted);margin-top:8px;}
.auth-switch a{color:var(--red);text-decoration:none;font-weight:700;}

/* ── Header ── */
.app-header{background:linear-gradient(135deg,#1a0000,#0d0d0d);padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;}
.header-logo{font-family:'Orbitron',sans-serif;font-size:1rem;font-weight:900;color:var(--red);letter-spacing:2px;display:flex;align-items:center;gap:8px;}
.header-logo i{filter:drop-shadow(0 0 6px var(--red));}
.user-badge{background:rgba(230,57,70,0.1);border:1px solid var(--border);padding:5px 12px;border-radius:20px;font-size:0.8rem;color:var(--muted);}

/* ── Search ── */
.search-wrap{padding:14px 16px;background:#0d0d0d;border-bottom:1px solid var(--border);}
.search-box{display:flex;align-items:center;gap:10px;background:#1a1a1a;border:1px solid var(--border);border-radius:8px;padding:10px 14px;}
.search-box i{color:var(--muted);}
.search-box input{background:none;border:none;outline:none;color:#fff;font-family:'Rajdhani',sans-serif;font-size:0.95rem;flex:1;}
.search-box input::placeholder{color:var(--muted);}

/* ── Main scroll ── */
.app-main{flex:1;overflow-y:auto;padding:0 0 80px 0;}
.app-main::-webkit-scrollbar{width:3px;}
.app-main::-webkit-scrollbar-thumb{background:var(--red);border-radius:3px;}

/* ── Categories ── */
.cats{display:flex;gap:12px;padding:16px;overflow-x:auto;}
.cats::-webkit-scrollbar{display:none;}
.cat{flex-shrink:0;display:flex;flex-direction:column;align-items:center;gap:6px;cursor:pointer;}
.cat-icon{width:56px;height:56px;background:#1a1a1a;border:1px solid var(--border);border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.4rem;transition:all 0.2s;}
.cat:hover .cat-icon,.cat.active .cat-icon{border-color:var(--red);background:rgba(230,57,70,0.1);box-shadow:0 0 12px var(--red-glow);}
.cat p{font-size:0.75rem;font-weight:700;letter-spacing:1px;color:var(--muted);text-transform:uppercase;}

/* ── Section title ── */
.sec-title{font-family:'Orbitron',sans-serif;font-size:0.85rem;letter-spacing:2px;color:var(--red);padding:16px 16px 8px;text-transform:uppercase;border-left:3px solid var(--red);margin-left:16px;}

/* ── Promo banner ── */
.promo{margin:0 16px 16px;background:linear-gradient(135deg,#1a0000,#0d0d0d);border:1px solid var(--border);border-radius:12px;padding:20px;display:flex;justify-content:space-between;align-items:center;position:relative;overflow:hidden;}
.promo::before{content:'';position:absolute;inset:0;background:repeating-linear-gradient(45deg,transparent,transparent 10px,rgba(230,57,70,0.02) 10px,rgba(230,57,70,0.02) 20px);}
.promo h3{font-family:'Orbitron',sans-serif;font-size:1.4rem;color:var(--red);text-shadow:0 0 20px var(--red);}
.promo p{color:var(--muted);font-size:0.85rem;margin-top:4px;}
.promo-icon{font-size:2.5rem;filter:drop-shadow(0 0 10px var(--red));}

/* ── Horizontal scroll cards ── */
.h-scroll{display:flex;gap:14px;padding:8px 16px 16px;overflow-x:auto;}
.h-scroll::-webkit-scrollbar{display:none;}

/* ── Game card ── */
.game-card{flex-shrink:0;width:150px;background:var(--card);border:1px solid var(--border);border-radius:12px;overflow:hidden;cursor:pointer;transition:border-color 0.2s;}
.game-card:hover{border-color:var(--red);}
.game-card img{width:100%;height:100px;object-fit:cover;}
.game-card-body{padding:10px 12px;}
.game-card-body h4{font-size:0.9rem;font-weight:700;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.game-card-body p{font-size:0.78rem;color:var(--red);font-weight:700;margin-top:2px;}

/* ── Service card ── */
.svc-card{flex-shrink:0;width:160px;background:var(--card);border:1px solid var(--border);border-radius:12px;padding:16px;cursor:pointer;transition:all 0.2s;}
.svc-card:hover{border-color:var(--red);box-shadow:0 0 15px var(--red-glow);}
.svc-card .svc-icon{font-size:1.8rem;margin-bottom:8px;}
.svc-card h4{font-size:0.9rem;font-weight:700;color:#fff;}
.svc-card p{font-size:0.8rem;color:var(--muted);margin-top:2px;}
.svc-card .price{font-size:0.95rem;font-weight:800;color:var(--red);margin-top:6px;}

/* ── Bottom Nav ── */
nav{position:fixed;bottom:0;left:50%;transform:translateX(-50%);width:100%;max-width:480px;height:65px;background:#0d0d0d;border-top:1px solid var(--border);display:flex;justify-content:space-around;align-items:center;z-index:50;}
.nav-btn{display:flex;flex-direction:column;align-items:center;gap:3px;color:var(--muted);cursor:pointer;padding:8px 16px;transition:color 0.2s;font-size:0.7rem;letter-spacing:1px;text-transform:uppercase;font-weight:700;}
.nav-btn i{font-size:1.2rem;}
.nav-btn.active{color:var(--red);}
.nav-btn.active i{filter:drop-shadow(0 0 6px var(--red));}

/* ── Admin link ── */
.admin-link{display:flex;align-items:center;gap:8px;padding:12px 16px;margin:16px;background:rgba(230,57,70,0.05);border:1px solid var(--border);border-radius:10px;color:var(--muted);font-size:0.85rem;font-weight:700;letter-spacing:1px;text-decoration:none;transition:all 0.2s;}
.admin-link:hover{border-color:var(--red);color:var(--red);}
.admin-link i{color:var(--red);}

/* ── Orders page ── */
.order-card{margin:12px 16px;background:var(--card);border:1px solid var(--border);border-radius:12px;padding:16px;}
.order-card .oc-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;}
.order-card h4{font-family:'Orbitron',sans-serif;font-size:0.8rem;letter-spacing:1px;color:#fff;}
.badge{padding:4px 10px;border-radius:20px;font-size:0.75rem;font-weight:700;letter-spacing:1px;}
.badge.pending{background:rgba(245,158,11,0.15);color:#f59e0b;border:1px solid rgba(245,158,11,0.3);}
.badge.processing{background:rgba(99,102,241,0.15);color:#818cf8;border:1px solid rgba(99,102,241,0.3);}
.badge.completed{background:rgba(34,197,94,0.15);color:#4ade80;border:1px solid rgba(34,197,94,0.3);}
.oc-row{display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid rgba(255,255,255,0.05);font-size:0.88rem;}
.oc-row:last-child{border:none;}
.oc-row span:first-child{color:var(--muted);}
.oc-row span:last-child{font-weight:700;color:#fff;}

/* ── Empty state ── */
.empty{text-align:center;padding:60px 20px;color:var(--muted);}
.empty i{font-size:3rem;color:rgba(230,57,70,0.3);margin-bottom:16px;}
.empty p{font-size:0.9rem;letter-spacing:1px;}

/* ── Responsive ── */
@media(max-width:360px){
  .auth-card{padding:24px 16px;}
  .game-card{width:130px;}
  .svc-card{width:140px;}
}
</style>
</head>
<body>
<div class="app">

<!-- ══ LOGIN PAGE ══ -->
<div class="page active" id="loginPage">
  <div class="auth-wrap">
    <div class="auth-card">
      <div class="auth-logo">
        <div class="icon">⚔️</div>
        <h1>GAME PANEL</h1>
        <p>SIGN IN TO CONTINUE</p>
      </div>
      <p class="auth-err" id="loginErr"></p>
      <input class="inp" type="email" id="loginEmail" placeholder="Email Address">
      <input class="inp" type="password" id="loginPassword" placeholder="Password">
      <button class="btn solid" id="loginBtn">ENTER</button>
      <button class="btn google" id="googleBtn">
        <img src="https://www.gstatic.com/firebasejs/ui/2.0.0/images/auth/google.svg" width="18"> Sign in with Google
      </button>
      <div class="auth-switch">No account? <a href="#" id="gotoRegister">Register</a></div>
    </div>
  </div>
</div>

<!-- ══ REGISTER PAGE ══ -->
<div class="page" id="registerPage">
  <div class="auth-wrap">
    <div class="auth-card">
      <div class="auth-logo">
        <div class="icon">🎮</div>
        <h1>CREATE ACCOUNT</h1>
        <p>JOIN THE GAME</p>
      </div>
      <p class="auth-err" id="regErr"></p>
      <input class="inp" type="text" id="regName" placeholder="Full Name">
      <input class="inp" type="email" id="regEmail" placeholder="Email Address">
      <input class="inp" type="password" id="regPassword" placeholder="Password (min 6 chars)">
      <button class="btn solid" id="registerBtn">CREATE ACCOUNT</button>
      <div class="auth-switch">Have account? <a href="#" id="gotoLogin">Login</a></div>
    </div>
  </div>
</div>

<!-- ══ MAIN APP ══ -->
<div class="page" id="appPage">

  <div class="app-header">
    <div class="header-logo"><i class="fas fa-gamepad"></i> GAME PANEL</div>
    <div class="user-badge" id="userGreeting">Hello, Guest</div>
  </div>

  <div class="search-wrap">
    <div class="search-box">
      <i class="fas fa-search"></i>
      <input type="text" id="searchInput" placeholder="Search games, panels...">
    </div>
  </div>

  <div class="app-main" id="homeTab">

    <!-- Categories -->
    <div class="cats">
      <div class="cat active" data-cat="all">
        <div class="cat-icon">⚡</div><p>All</p>
      </div>
      <div class="cat" data-cat="topup">
        <div class="cat-icon">💎</div><p>Top-Up</p>
      </div>
      <div class="cat" data-cat="panel">
        <div class="cat-icon">🎯</div><p>Panels</p>
      </div>
      <div class="cat" data-cat="custom">
        <div class="cat-icon">🔧</div><p>Custom</p>
      </div>
    </div>

    <!-- Promo -->
    <div class="promo">
      <div>
        <h3>LEVEL UP</h3>
        <p>Best prices on diamonds & panels</p>
      </div>
      <div class="promo-icon">⚔️</div>
    </div>

    <!-- Games -->
    <div class="sec-title">🎮 Game Top-Up</div>
    <div class="h-scroll" id="gamesContainer"></div>

    <!-- Services -->
    <div class="sec-title">🎯 Panels & Services</div>
    <div class="h-scroll" id="servicesContainer"></div>

    <!-- Admin link -->
    <a href="admin.php" class="admin-link">
      <i class="fas fa-shield-alt"></i> Admin Panel Access
      <i class="fas fa-chevron-right" style="margin-left:auto;"></i>
    </a>

  </div>

  <!-- Orders Tab -->
  <div class="app-main" id="ordersTab" style="display:none;">
    <div style="padding:16px 16px 0;font-family:'Orbitron',sans-serif;font-size:0.85rem;letter-spacing:2px;color:var(--red);">MY ORDERS</div>
    <div id="ordersContainer"><div class="empty"><i class="fas fa-receipt"></i><p>No orders yet</p></div></div>
  </div>

  <!-- Bottom Nav -->
  <nav>
    <div class="nav-btn active" id="navHome" onclick="switchTab('home')">
      <i class="fas fa-home"></i> Home
    </div>
    <div class="nav-btn" id="navOrders" onclick="switchTab('orders')">
      <i class="fas fa-receipt"></i> Orders
    </div>
    <div class="nav-btn" id="navLogout" onclick="doLogout()">
      <i class="fas fa-sign-out-alt"></i> Logout
    </div>
  </nav>

</div><!-- end appPage -->

<script src="https://www.gstatic.com/firebasejs/8.10.0/firebase-app.js"></script>
<script src="https://www.gstatic.com/firebasejs/8.10.0/firebase-auth.js"></script>
<script src="https://www.gstatic.com/firebasejs/8.10.0/firebase-database.js"></script>
<script>
const firebaseConfig = <?php echo json_encode($firebaseConfig); ?>;
firebase.initializeApp(firebaseConfig);
const auth = firebase.auth();
const db   = firebase.database();

// ── Page switching ────────────────────────────────────────────
function showPage(id) {
    document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
    document.getElementById(id).classList.add('active');
}

// ── Tab switching ─────────────────────────────────────────────
function switchTab(tab) {
    document.getElementById('homeTab').style.display   = tab === 'home'   ? 'block' : 'none';
    document.getElementById('ordersTab').style.display = tab === 'orders' ? 'block' : 'none';
    document.querySelectorAll('.nav-btn').forEach(b => b.classList.remove('active'));
    document.getElementById(tab === 'home' ? 'navHome' : 'navOrders').classList.add('active');
    if (tab === 'orders') loadOrders();
}

// ── Auth state ────────────────────────────────────────────────
auth.onAuthStateChanged(user => {
    if (user) {
        const name = user.displayName || user.email.split('@')[0];
        document.getElementById('userGreeting').textContent = 'Hey, ' + name;
        showPage('appPage');
        loadGames();
        loadServices();
    } else {
        showPage('loginPage');
    }
});

// ── Login ─────────────────────────────────────────────────────
document.getElementById('loginBtn').onclick = () => {
    const email = document.getElementById('loginEmail').value.trim();
    const pass  = document.getElementById('loginPassword').value;
    const err   = document.getElementById('loginErr');
    err.style.display = 'none';
    if (!email || !pass) { err.textContent = 'Fill in all fields.'; err.style.display = 'block'; return; }
    auth.signInWithEmailAndPassword(email, pass)
        .catch(() => { err.textContent = 'Invalid email or password.'; err.style.display = 'block'; });
};

document.getElementById('googleBtn').onclick = () => {
    const provider = new firebase.auth.GoogleAuthProvider();
    auth.signInWithPopup(provider).catch(e => {
        const err = document.getElementById('loginErr');
        err.textContent = 'Google sign-in failed.'; err.style.display = 'block';
    });
};

// ── Register ──────────────────────────────────────────────────
document.getElementById('registerBtn').onclick = () => {
    const name  = document.getElementById('regName').value.trim();
    const email = document.getElementById('regEmail').value.trim();
    const pass  = document.getElementById('regPassword').value;
    const err   = document.getElementById('regErr');
    err.style.display = 'none';
    if (!name || !email || !pass) { err.textContent = 'Fill in all fields.'; err.style.display = 'block'; return; }
    if (pass.length < 6) { err.textContent = 'Password must be at least 6 characters.'; err.style.display = 'block'; return; }
    auth.createUserWithEmailAndPassword(email, pass)
        .then(cred => cred.user.updateProfile({ displayName: name }))
        .catch(e => { err.textContent = e.message; err.style.display = 'block'; });
};

document.getElementById('gotoRegister').onclick = e => { e.preventDefault(); showPage('registerPage'); };
document.getElementById('gotoLogin').onclick    = e => { e.preventDefault(); showPage('loginPage'); };

// ── Logout ────────────────────────────────────────────────────
function doLogout() { auth.signOut(); }

// ── Load Games ────────────────────────────────────────────────
function loadGames() {
    db.ref('games').once('value', snap => {
        const c = document.getElementById('gamesContainer');
        c.innerHTML = '';
        if (!snap.exists()) { c.innerHTML = '<p style="color:var(--muted);padding:8px 0;font-size:0.85rem;">No games yet</p>'; return; }
        snap.forEach(child => {
            const g = child.val();
            const pkgs = g.packages ? Object.values(g.packages) : [];
            const minPrice = pkgs.length ? Math.min(...pkgs.map(p => parseFloat(p.price))) : null;
            c.innerHTML += `
                <div class="game-card">
                    <div style="height:100px;background:linear-gradient(135deg,#1a0000,#0d0d0d);display:flex;align-items:center;justify-content:center;font-size:2.5rem;">🎮</div>
                    <div class="game-card-body">
                        <h4>${g.name}</h4>
                        <p>${minPrice ? 'From ₹' + minPrice : 'View packages'}</p>
                    </div>
                </div>`;
        });
    });
}

// ── Load Services ─────────────────────────────────────────────
function loadServices() {
    db.ref('services').once('value', snap => {
        const c = document.getElementById('servicesContainer');
        c.innerHTML = '';
        if (!snap.exists()) { c.innerHTML = '<p style="color:var(--muted);padding:8px 0;font-size:0.85rem;">No services yet</p>'; return; }
        snap.forEach(child => {
            const s = child.val();
            c.innerHTML += `
                <div class="svc-card">
                    <div class="svc-icon">🎯</div>
                    <h4>${s.name}</h4>
                    <p>${s.description || ''}</p>
                    <div class="price">₹${s.price}</div>
                </div>`;
        });
    });
}

// ── Load Orders ───────────────────────────────────────────────
function loadOrders() {
    const user = auth.currentUser;
    if (!user) return;
    db.ref('orders').orderByChild('waNumber').once('value', snap => {
        const c = document.getElementById('ordersContainer');
        c.innerHTML = '';
        const orders = [];
        snap.forEach(child => {
            const o = child.val();
            if (o.name && o.timestamp) orders.push({ id: child.key, ...o });
        });
        orders.sort((a, b) => new Date(b.timestamp) - new Date(a.timestamp));
        if (!orders.length) {
            c.innerHTML = '<div class="empty"><i class="fas fa-receipt"></i><p>No orders yet</p></div>';
            return;
        }
        orders.slice(0, 20).forEach(o => {
            const statusClass = o.status === 'Completed' ? 'completed' : o.status === 'Processing' ? 'processing' : 'pending';
            const item = o.game ? `${o.game} — ${o.package}` : o.item || 'Custom';
            c.innerHTML += `
                <div class="order-card">
                    <div class="oc-head">
                        <h4>#${o.id.substring(1,7).toUpperCase()}</h4>
                        <span class="badge ${statusClass}">${o.status || 'Pending'}</span>
                    </div>
                    <div class="oc-row"><span>Item</span><span>${item}</span></div>
                    ${o.uid ? `<div class="oc-row"><span>UID</span><span>${o.uid}</span></div>` : ''}
                    <div class="oc-row"><span>Amount</span><span style="color:var(--red)">₹${o.price}</span></div>
                    <div class="oc-row"><span>Date</span><span>${new Date(o.timestamp).toLocaleDateString()}</span></div>
                </div>`;
        });
    });
}

// ── Search ────────────────────────────────────────────────────
document.getElementById('searchInput').addEventListener('input', function() {
    const q = this.value.toLowerCase();
    document.querySelectorAll('.game-card, .svc-card').forEach(card => {
        const text = card.textContent.toLowerCase();
        card.style.display = text.includes(q) ? '' : 'none';
    });
});

// ── Category filter ───────────────────────────────────────────
document.querySelectorAll('.cat').forEach(cat => {
    cat.onclick = () => {
        document.querySelectorAll('.cat').forEach(c => c.classList.remove('active'));
        cat.classList.add('active');
    };
});
</script>
</body>
</html>
