# WhatsApp Seller Bot

A WhatsApp bot for selling game top-ups and panels, with a real-time admin dashboard. Everything — prices, games, packages — is managed from Firebase with no code changes needed.

---

## How It Works

```
Customer texts WhatsApp
        ↓
Bot asks: 1. General  2. Panels  3. Diamond Top-Up
        ↓
Picks game → package → enters UID → name → phone
        ↓
Bot sends QR image for payment
        ↓
Customer sends payment screenshot
        ↓
Order saved to Firebase → Admin marks Completed
```

---

## Project Files

| File | Purpose |
|---|---|
| `index.js` | WhatsApp bot (runs on GitHub Actions) |
| `admin.php` | Admin dashboard (hosted on PHP server) |
| `index.php` | Customer web app with OTP email verification |
| `main.yml` | GitHub Actions — runs bot every 5 hours |
| `package.json` | Node.js dependencies |
| `.env.example` | Template for all environment variables |
| `firebase.rules.json` | Firebase Realtime Database security rules |
| `security.md` | Full security implementation details |

---

## Firebase Structure

```
/games/{id}
    name: "Free Fire"
    packages/{id}
        label: "100 Diamonds"
        price: 80

/services/{id}
    name: "Headshot Panel"
    price: 199
    description: "..."

/orders/{id}
    type: "topup" | "service" | "custom_request"
    game, package, uid, name, phone, price
    paymentProof, waNumber
    status: "Pending" | "Processing" | "Completed"
    timestamp

/settings
    owner: "Your Name"
    upi: "yourname@upi"       ← optional
    qr_image_url: "https://..." ← QR photo sent to customers
```

---

## Setup

### 1. Firebase

1. Go to [console.firebase.google.com](https://console.firebase.google.com)
2. Enable **Realtime Database**
3. Go to **Realtime Database → Rules** tab → paste `firebase.rules.json` → **Publish**
4. Go to **Project Settings → Your apps → Web** → register app → copy config values

### 2. Environment Variables

Copy `.env.example` to `.env` and fill in:

```
FIREBASE_API_KEY=
FIREBASE_AUTH_DOMAIN=
FIREBASE_DATABASE_URL=
FIREBASE_PROJECT_ID=
FIREBASE_STORAGE_BUCKET=
FIREBASE_MESSAGING_SENDER_ID=
FIREBASE_APP_ID=

APP_ENV=production

ADMIN_GATE_USER=yourname
ADMIN_GATE_PASS=strongpassword

TOTP_SECRET=           ← generate below
```

Upload `.env` to your PHP hosting server root.

### 3. Generate TOTP Secret (2FA)

```bash
python3 -c "import base64,os; print(base64.b32encode(os.urandom(20)).decode())"
```

Copy the output → paste as `TOTP_SECRET` in `.env` → open **Google Authenticator** → **+** → **Enter a setup key** → paste the same secret → **Time based** → Add.

### 4. GitHub Actions (Bot)

1. Repo → **Settings → Secrets → Actions** → add secret:
   - Name: `FIREBASE_URL`
   - Value: `https://your-project-default-rtdb.firebaseio.com`
2. Push code → **Actions → Run workflow** → scan QR in logs

Bot runs automatically every 5 hours.

### 5. Admin Panel

Upload `admin.php` to PHP hosting server.

1. Visit `yoursite.com/admin.php` → enter gate password (HTTP Basic Auth)
2. Login with Firebase email + password
3. Enter 6-digit TOTP code from Google Authenticator
4. Go to **Settings** → set owner name + QR image URL
5. Go to **Games** → add games + packages
6. Go to **Services** → add panels

---

## Bot Flow

```
Any message
    ↓
1 — General 💬
    → "Owner not available, message noted"
    → Can still switch to 2 or 3

2 — Panels 🎯
    → Pick panel → name → phone → QR payment → screenshot → saved

3 — Diamond Top-Up 💎
    → Pick game → pick package → UID → name → phone → QR payment → screenshot → saved

Other game → custom request saved, owner contacts manually
```

Global commands (any step): `stop`, `restart`, `help`
Rate limit: 50 messages per user per 30 minutes

---

## Admin Dashboard

| Section | What you can do |
|---|---|
| Dashboard | Revenue, total orders, pending, completed |
| WA Orders | All orders + status control |
| Games | Add/delete games and packages |
| Services | Add/delete panels |
| Settings | Owner name, UPI ID, QR image URL |

Changes reflect in bot immediately — no restart needed.

---

## Security Layers

```
Visit admin.php
      ↓
1. HTTP Basic Auth (ADMIN_GATE_USER + ADMIN_GATE_PASS)
      ↓
2. Firebase email + password (rate limited: 10 attempts / 15 min)
      ↓
3. TOTP 2FA — 6-digit code from Google Authenticator
   (rate limited: 5 attempts / 5 min, expires every 30 sec)
      ↓
Dashboard
```

- All secrets in env vars — nothing hardcoded
- `.env` and `session_data/` in `.gitignore` — never committed
- All user input sanitized before saving
- Firebase Rules lock down database access
- Bot rate limited: 50 msg / 30 min per user

See `security.md` for full details.
