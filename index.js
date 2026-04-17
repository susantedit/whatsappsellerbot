const { default: makeWASocket, useMultiFileAuthState, DisconnectReason, fetchLatestBaileysVersion } = require('@whiskeysockets/baileys');
const qrcode = require('qrcode-terminal');
const pino   = require('pino');
const fs     = require('fs');
const nodemailer = require('nodemailer');

const FIREBASE_URL  = process.env.FIREBASE_URL;
const GEMINI_KEY    = process.env.GEMINI_API_KEY;
const SMTP_USER     = process.env.SMTP_USER;
const SMTP_PASS     = process.env.SMTP_PASS;
const NOTIFY_EMAIL  = process.env.NOTIFY_EMAIL || SMTP_USER;

// ⭐ Validate critical environment variables at startup
if (!FIREBASE_URL) {
    console.error('❌ FIREBASE_URL is required in .env');
    process.exit(1);
}
if (!SMTP_USER || !SMTP_PASS) {
    console.warn('⚠️ SMTP credentials missing - email notifications disabled');
}

const userStates = {};

// ── Memory Cleanup ──────────────────────────────────────────────
// Clear old user states every 24 hours to prevent memory leaks
setInterval(() => {
    const now = Date.now();
    let cleared = 0;
    for (const [sender, state] of Object.entries(userStates)) {
        const inactiveFor = now - (state.lastActivity || now);
        // Clear if inactive for 24 hours
        if (inactiveFor > 24 * 60 * 60 * 1000) {
            delete userStates[sender];
            cleared++;
        }
    }
    if (cleared > 0) {
        console.log(`[CLEANUP] Removed ${cleared} inactive user sessions`);
    }
}, 60 * 60 * 1000); // Every hour

// ── Rate limiting ────────────────────────────────────────────────
const rateLimits = {};
function isRateLimited(sender) {
    const now = Date.now(), window = 30 * 60_000, max = 50;
    if (!rateLimits[sender]) rateLimits[sender] = { count: 0, start: now };
    if (now - rateLimits[sender].start > window) rateLimits[sender] = { count: 0, start: now };
    rateLimits[sender].count++;
    return rateLimits[sender].count > max;
}

// Auto-cleanup rate limits every 2 hours
setInterval(() => {
    const now = Date.now();
    let cleared = 0;
    for (const [sender, limit] of Object.entries(rateLimits)) {
        if (now - limit.start > 2 * 60 * 60 * 1000) {
            delete rateLimits[sender];
            cleared++;
        }
    }
    if (cleared > 0) {
        console.log(`[CLEANUP] Removed ${cleared} expired rate limits`);
    }
}, 2 * 60 * 60 * 1000);

function sanitizeInput(text) {
    // Remove dangerous characters and limit length
    return String(text)
        .replace(/[<>"'`]/g, '')  // Remove HTML/SQL chars
        .replace(/[{}[\]]/g, '')   // Remove JSON/code chars
        .trim()
        .slice(0, 500);
}

// ⭐ Enhanced validators for data integrity
function validateName(name) {
    const cleaned = sanitizeInput(name);
    if (cleaned.length < 2 || cleaned.length > 50) return null;
    if (!/^[a-zA-Z0-9\s\-_.]*$/.test(cleaned)) return null; // Only alphanumeric + common chars
    return cleaned;
}

function validateUID(uid) {
    const cleaned = String(uid).replace(/[^0-9a-zA-Z]/g, '');
    if (cleaned.length < 5 || cleaned.length > 15) return null;
    return cleaned;
}

const delay = ms => new Promise(res => setTimeout(res, ms));

// ── Firebase helpers ─────────────────────────────────────────────
async function fbGet(path) {
    try { return await (await fetch(`${FIREBASE_URL}/${path}.json`)).json(); }
    catch { return null; }
}
async function fbPost(path, data) {
    try {
        await fetch(`${FIREBASE_URL}/${path}.json`, {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });
    } catch (e) { console.error('fbPost:', e); }
}
async function fbSet(path, data) {
    try {
        await fetch(`${FIREBASE_URL}/${path}.json`, {
            method: 'PUT', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });
    } catch (e) { console.error('fbSet:', e); }
}
async function fbPatch(path, data) {
    try {
        await fetch(`${FIREBASE_URL}/${path}.json`, {
            method: 'PATCH', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });
    } catch (e) { console.error('fbPatch:', e); }
}

// ── Email notification ───────────────────────────────────────────
async function sendOrderEmail(order) {
    if (!SMTP_USER || !SMTP_PASS || !NOTIFY_EMAIL) return;
    try {
        const transporter = nodemailer.createTransport({
            service: 'gmail',
            auth: { user: SMTP_USER, pass: SMTP_PASS }
        });

        const typeLabel = order.type === 'topup'        ? '🎮 Game Top-Up'
                        : order.type === 'service'      ? '🎯 Panel / Service'
                        : order.type === 'bot_setup'    ? '🤖 Bot Setup'
                        : '📩 Custom Request';

        const detailRows = order.type === 'topup'
            ? `<tr><td>Game</td><td><b>${order.game}</b></td></tr>
               <tr><td>Package</td><td><b>${order.package}</b></td></tr>
               <tr><td>UID</td><td><b>${order.uid || '-'}</b></td></tr>`
            : `<tr><td>Item</td><td><b>${order.item || order.message || '-'}</b></td></tr>`;

        const subject = `🛒 New Order — ${order.name || order.waNumber} — ₹${order.price}`;

        const html = `
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#0a0a0a;font-family:Arial,sans-serif;">
  <div style="max-width:560px;margin:30px auto;background:#111;border-radius:12px;overflow:hidden;border:1px solid #2a2a2a;">

    <!-- Header -->
    <div style="background:linear-gradient(135deg,#1a0000,#0d0d0d);padding:28px 32px;border-bottom:2px solid #e63946;">
      <div style="font-size:1.4rem;font-weight:900;color:#e63946;letter-spacing:3px;">⚔️ GAME PANEL</div>
      <div style="color:#888;font-size:0.85rem;margin-top:4px;letter-spacing:1px;">NEW ORDER RECEIVED</div>
    </div>

    <!-- Type badge -->
    <div style="padding:20px 32px 0;">
      <span style="background:rgba(230,57,70,0.15);color:#e63946;border:1px solid rgba(230,57,70,0.4);padding:6px 14px;border-radius:20px;font-size:0.85rem;font-weight:700;letter-spacing:1px;">${typeLabel}</span>
    </div>

    <!-- Order details table -->
    <div style="padding:20px 32px;">
      <table style="width:100%;border-collapse:collapse;font-size:0.95rem;">
        <tr style="border-bottom:1px solid #222;">
          <td style="padding:10px 0;color:#888;width:40%;">Customer</td>
          <td style="padding:10px 0;color:#fff;font-weight:700;">${order.name || '-'}</td>
        </tr>
        <tr style="border-bottom:1px solid #222;">
          <td style="padding:10px 0;color:#888;">Phone</td>
          <td style="padding:10px 0;color:#fff;font-weight:700;">${order.phone || '-'}</td>
        </tr>
        <tr style="border-bottom:1px solid #222;">
          <td style="padding:10px 0;color:#888;">WhatsApp</td>
          <td style="padding:10px 0;color:#fff;font-weight:700;">${order.waNumber || '-'}</td>
        </tr>
        ${detailRows}
        <tr style="border-bottom:1px solid #222;">
          <td style="padding:10px 0;color:#888;">Amount</td>
          <td style="padding:10px 0;font-size:1.2rem;font-weight:900;color:#e63946;">₹${order.price}</td>
        </tr>
        <tr>
          <td style="padding:10px 0;color:#888;">Time</td>
          <td style="padding:10px 0;color:#fff;">${new Date(order.timestamp).toLocaleString('en-IN', { dateStyle: 'medium', timeStyle: 'short' })}</td>
        </tr>
      </table>
    </div>

    <!-- CTA -->
    <div style="padding:0 32px 28px;">
      <a href="https://wa.me/${String(order.waNumber).replace(/[^0-9]/g, '')}" style="display:inline-block;background:#25d366;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:700;font-size:0.9rem;letter-spacing:1px;">💬 Reply on WhatsApp</a>
    </div>

    <!-- Footer -->
    <div style="background:#0d0d0d;padding:16px 32px;border-top:1px solid #1a1a1a;text-align:center;color:#444;font-size:0.78rem;letter-spacing:1px;">
      Game Panel Bot · Auto-notification · Do not reply to this email
    </div>

  </div>
</body>
</html>`;

        await transporter.sendMail({
            from: `"Game Panel Bot" <${SMTP_USER}>`,
            to: NOTIFY_EMAIL,
            subject,
            html
        });
        console.log('[EMAIL] Order notification sent');
    } catch (e) { console.error('[EMAIL] Failed:', e.message); }
}

// ── eSewa Payment & Phone Validation ─────────────────────────
function validatePhoneNumber(phone) {
    // validate Nepali phone format: 10 digits starting with 98 or 97
    const cleanPhone = String(phone).replace(/[^0-9]/g, '');
    if (!/^977?\d{8,10}$/.test(cleanPhone) && !/^98\d{8}$/.test(cleanPhone) && !/^97\d{8}$/.test(cleanPhone)) {
        return { valid: false, message: 'Invalid phone. Use format: 9779708838261 or 9849123456' };
    }
    return { valid: true, phone: cleanPhone };
}

async function verifyESewaPayment(phone, amount, transactionId) {
    // This checks if payment was made in eSewa
    // In production, connect to eSewa API: https://eSewa.com.np/developers
    // For now, return verification pending
    try {
        const payload = { 
            transactionId, 
            phone, 
            amount, 
            verifiedAt: new Date().toISOString(),
            status: 'pending' // 'pending', 'verified', 'failed'
        };
        // TODO: Add eSewa API integration here
        // await fetch('https://eSewa.com.np/api/check-payment', { method: 'POST', body: JSON.stringify(payload) })
        return payload;
    } catch (e) {
        console.error('eSewa verification error:', e.message);
        return { status: 'error', message: e.message };
    }
}

function toArray(obj) {
    if (!obj) return [];
    return Object.keys(obj).map(k => ({ id: k, ...obj[k] }));
}
function pickItem(items, input, matchFn) {
    if (!input || !items) return null;
    input = input.toLowerCase().trim();
    const num = parseInt(input);
    if (!isNaN(num) && num >= 1 && num <= items.length) return items[num - 1];
    return items.find(item => matchFn(item, input)) || null;
}

// ── Persistent user memory ───────────────────────────────────────
async function getUser(waNumber) {
    return await fbGet(`users/${waNumber}`) || {};
}
async function saveUser(waNumber, data) {
    await fbPatch(`users/${waNumber}`, { ...data, lastSeen: new Date().toISOString() });
}

// ── Gemini AI ────────────────────────────────────────────────────
async function buildBusinessContext() {
    const [games, services, settings] = await Promise.all([
        fbGet('games'), fbGet('services'), fbGet('settings')
    ]);
    const owner = settings?.owner || 'the owner';
    let ctx = `Business owner: ${owner}\n\n`;
    if (games) {
        ctx += `GAME TOP-UPS:\n`;
        Object.values(games).forEach(g => {
            ctx += `${g.name}:\n`;
            if (g.packages) Object.values(g.packages).forEach(p => { ctx += `  ${p.label} = Rs.${p.price}\n`; });
        });
    }
    if (services) {
        ctx += `\nPANELS AND SERVICES:\n`;
        Object.values(services).forEach(s => {
            ctx += `${s.name}${s.description ? ' (' + s.description + ')' : ''}:\n`;
            if (s.packages) {
                Object.values(s.packages).forEach(p => { ctx += `  ${p.label} = Rs.${p.price}\n`; });
            } else if (s.price) {
                ctx += `  Price = Rs.${s.price}\n`;
            }
        });
    }
    return ctx;
}

async function askGemini(userMessage, ctx, userName) {
    if (!GEMINI_KEY) return null;
    try {
        const prompt =
            `You are a real person running a gaming panel and diamond top-up business on WhatsApp. ` +
            `Your name is the business owner. You talk like a normal person texting, not a bot. ` +
            `Use casual language, gaming words, be friendly and warm. ` +
            `VERY IMPORTANT: Reply in the EXACT same language the user writes. ` +
            `If they write in Nepali, reply in Nepali. If Hindi, reply in Hindi. If Hinglish, reply in Hinglish. If English, reply in English. ` +
            `Match their tone and language perfectly like a local friend would. ` +
            `PRICE RULE — VERY IMPORTANT: If user asks about price or packages of any panel or game, ` +
            `you MUST list ALL available durations and their prices from the catalog below. ` +
            `Do not pick just one price. Show every option like: 1 Day - Rs.X, 3 Days - Rs.Y, 7 Days - Rs.Z etc. ` +
            `Never make up prices, only use what is in the catalog below. ` +
            `If the user wants to buy or order something, end your reply with exactly: [ORDER_INTENT] ` +
            `If the user asks about something not in your catalog, say you don't have it right now. ` +
            `\n\nIMPORTANT — If user asks what a panel is or how it works, explain it naturally like this: ` +
            `A panel is basically a mod or hack tool for Free Fire or other games. ` +
            `It gives you features like auto headshot, aimbot, wallhack, speed, antiban and more depending on which panel you buy. ` +
            `You install it on your phone and it runs alongside the game. ` +
            `It comes with a time limit like 1 day, 7 days or 30 days and you renew it when it expires. ` +
            `Always explain this in the user's language naturally without sounding like a robot.\n\n` +
            `The user's name is ${userName || 'bro'}.\n\n` +
            `YOUR CATALOG:\n${ctx}\n\n` +
            `User says: ${userMessage}`;

        const res = await fetch(
            `https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=${GEMINI_KEY}`,
            {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    contents: [{ parts: [{ text: prompt }] }],
                    generationConfig: { maxOutputTokens: 400, temperature: 0.75 }
                })
            }
        );
        const data = await res.json();
        return data?.candidates?.[0]?.content?.parts?.[0]?.text?.trim() || null;
    } catch (e) { console.error('Gemini error:', e.message); return null; }
}

// ── Bot ──────────────────────────────────────────────────────────
let sock; // global so order status listener can use it

async function startBot() {
    if (!FIREBASE_URL) { console.log('FIREBASE_URL missing!'); process.exit(1); }

    const { state, saveCreds } = await useMultiFileAuthState('session_data');
    const { version } = await fetchLatestBaileysVersion();

    sock = makeWASocket({
        version, auth: state,
        printQRInTerminal: false,
        logger: pino({ level: 'silent' }),
        browser: ['Bot', 'Chrome', '1.0']
    });

    sock.ev.on('connection.update', ({ connection, lastDisconnect, qr }) => {
        if (qr) { console.clear(); qrcode.generate(qr, { small: true }); console.log('\nScan QR above'); }
        if (connection === 'open') {
            console.log('Bot is online!');
            listenOrderStatusChanges();
            deliverBroadcasts(); // deliver any pending broadcasts
        }
        if (connection === 'close') {
            const code = lastDisconnect?.error?.output?.statusCode;
            if (code !== DisconnectReason.loggedOut) startBot();
        }
    });

    sock.ev.on('creds.update', saveCreds);

    sock.ev.on('messages.upsert', async (m) => {
        const msg = m.messages[0];
        if (!msg.message || msg.key.remoteJid === 'status@broadcast') return;
        if (msg.key.fromMe) return;

        const sender  = msg.key.remoteJid;
        // ── Ignore group messages — only respond to private chats ──
        if (sender.endsWith('@g.us')) return;
        const waNum   = sender.split('@')[0];
        const rawText = sanitizeInput(msg.message.conversation || msg.message.extendedTextMessage?.text || '');
        const t       = rawText.toLowerCase();

        if (isRateLimited(sender)) { console.warn(`[RATE LIMIT] ${waNum}`); return; }
        console.log(`[${waNum}] ${rawText}`);

        // typing indicator
        const send = async (text) => {
            await sock.sendPresenceUpdate('composing', sender);
            await delay(1200 + Math.random() * 1500); // feels like real typing
            await sock.sendPresenceUpdate('paused', sender);
            return sock.sendMessage(sender, { text });
        };

        // ── Silent (General) — blocked, but allow menu/restart to escape ──
        if (userStates[sender]?.step === 'SILENT') {
            if (Date.now() > (userStates[sender].blockedUntil || 0)) {
                delete userStates[sender]; // expired — fall through normally
            } else if (t === 'menu' || t === 'start' || t === 'restart' || t === 'hi' || t === 'hello' || t === 'hey') {
                delete userStates[sender]; // user wants out — let them back in
            } else {
                return; // still blocked, ignore
            }
        }

        // ── Global commands ───────────────────────────────────────
        if (t === 'stop' || t === 'exit' || t === 'cancel') {
            delete userStates[sender];
            await send('Okay see you! Type anything to start again 👋');
            return;
        }
        if (t === 'restart' || t === 'start' || t === 'hi' || t === 'hello' || t === 'hey' || t === 'menu') {
            const savedName = userStates[sender]?.name || null;
            delete userStates[sender];
            if (savedName) userStates[sender] = { step: 'RETURNING', name: savedName };
        }
        if (t === 'help') {
            await send(`You can reply with 1 for General, 2 for Panels, 3 for Diamond Top-Up, 4 to Buy This Bot or 5 to Ask a Question. Type restart anytime to start over or stop to end the chat.`);
            return;
        }

        // ── Load persistent user data ─────────────────────────────
        const userData = await getUser(waNum);
        const userName = userStates[sender]?.name || userData.name || null;

        // ── Global AI for questions (anytime, any step) ───────────
        const orderSteps = ['ASK_NAME','ASK_PHONE','ASK_UID','SEND_PAYMENT','ASK_GAME_PHONE'];
        const isMenuInput = /^[1-6]$/.test(t) || ['stop','exit','cancel','restart','start','hi','hello','hey','menu','help'].includes(t);
        const curStep = userStates[sender]?.step;
        // trigger AI for anything that's not a plain menu number and not in a data-entry step
        if (GEMINI_KEY && !isMenuInput && !orderSteps.includes(curStep) && rawText.length > 1) {
            const ctx = await buildBusinessContext();
            const aiReply = await askGemini(rawText, ctx, userName);
            if (aiReply) {
                const clean = aiReply.replace('[ORDER_INTENT]', '').trim();
                await send(clean);
                if (aiReply.includes('[ORDER_INTENT]')) {
                    await delay(500);
                    userStates[sender] = { step: 'PICK_CATEGORY', name: userName };
                    await send(`What do you want to order?\n\n*1* 💬 General\n*2* 🎯 Panels\n*3* 💎 Diamond Top-Up\n*4* 🤖 Buy This Bot\n*5* ❓ Ask a Question`);
                } else {
                    // if mid-order, remind them where they were
                    const midSteps = ['PICK_SERVICE','PICK_GAME','PICK_PACKAGE','PICK_SERVICE_PKG','ASK_DURATION'];
                    if (midSteps.includes(curStep)) {
                        await delay(500);
                        if (curStep === 'PICK_SERVICE') {
                            const list = userStates[sender].services?.map((s,i) => `*${i+1}.* ${s.name}`).join('\n') || '';
                            if (list) await send(`Anyway, you were picking a panel 👆\n\n${list}\n\nReply with the number to continue`);
                        } else if (curStep === 'PICK_GAME') {
                            const list = userStates[sender].games?.map((g,i) => `*${i+1}.* ${g.name}`).join('\n') || '';
                            if (list) await send(`Anyway, you were picking a game 👆\n\n${list}\n\nReply with the number to continue`);
                        } else if (curStep === 'PICK_PACKAGE') {
                            const list = userStates[sender].packages?.map((p,i) => `*${i+1}.* ${p.label} — ₹${p.price}`).join('\n') || '';
                            if (list) await send(`Anyway, you were picking a package 👆\n\n${list}\n\nReply with the number to continue`);
                        } else if (curStep === 'ASK_DURATION' || curStep === 'PICK_SERVICE_PKG') {
                            const pkgs = userStates[sender].packages || userStates[sender].durationPkgs || [];
                            const list = pkgs.map((p,i) => `*D${i+1}* — ${p.label} · ₹${p.price}`).join('\n');
                            if (list) await send(`Anyway, choose your package 👆\n\n${list}`);
                        }
                    } else if (curStep === 'PICK_CATEGORY') {
                        await delay(400);
                        await send(`Type *menu* to see all options or reply with *1-5* 😊`);
                    }
                }
                return;
            }
        }

        // ── New user ──────────────────────────────────────────────
        if (!userStates[sender]) {
            userStates[sender] = { step: 'PICK_CATEGORY' };
            const menu = `*1* 💬 General — For regular messages\n*2* 🎯 Panels — Free Fire hack panels (auto headshot, aimbot etc.)\n*3* 💎 Diamond Top-Up — Buy diamonds for Free Fire & other games\n*4* 🏆 Rank Pushing — Fast rank boost Rs.40 per 25 stars\n*5* 🤖 Buy This Bot — Get your own WhatsApp bot like this\n*6* ❓ Ask a Question — Prices, how panels work, anything`;
            const greeting = userData.name
                ? `Hey ${userData.name}! Good to see you again 👋\n\nWhat can I help you with?\n\n${menu}`
                : `👋 Welcome!\n\nPlease choose an option:\n\n${menu}\n\nReply with *1, 2, 3 or 4* 😊`;
            await send(greeting);
            return;
        }

        // ── Returning user ────────────────────────────────────────
        if (userStates[sender]?.step === 'RETURNING') {
            const name = userStates[sender].name || userData.name;
            userStates[sender] = { step: 'PICK_CATEGORY', name };
            await send(`Hey ${name}! 👋 What do you need today?\n\n*1* 💬 General\n*2* 🎯 Panels\n*3* 💎 Diamond Top-Up\n*4* 🏆 Rank Pushing\n*5* 🤖 Buy This Bot\n*6* ❓ Ask a Question`);
            return;
        }

        const st = userStates[sender];

        // ── PICK_CATEGORY ─────────────────────────────────────────
        if (st.step === 'PICK_CATEGORY') {
            if (t === '1' || t === 'general') {
                const settings = await fbGet('settings');
                const owner = settings?.owner || 'Susant';
                await send(`Hey 👋 the owner isn't available right now.\nYour message has been noted — they'll get back to you soon 🙏`);
                userStates[sender] = { step: 'SILENT', blockedUntil: Date.now() + 24 * 60 * 60 * 1000 };
                return;
            }
            if (t === '2' || t.includes('panel') || t.includes('service')) {
                const data = await fbGet('services');
                const services = toArray(data);
                if (!services.length) { await send('No panels available right now, check back soon!'); return; }
                userStates[sender] = { ...st, step: 'PICK_SERVICE', services };
                const list = services.map((s, i) => `*${i+1}.* ${s.name}${s.description ? ' — _' + s.description + '_' : ''}`).join('\n');
                await send(`Here are all the panels 🎯\n\n${list}\n\nReply with the number you want`);
                return;
            }
            if (t === '4' || t.includes('rank') || t.includes('push') || t.includes('star')) {
                const data = await fbGet('services');
                const services = toArray(data);
                const rankSvc = services.find(s => s.name.toLowerCase().includes('rank'));
                if (rankSvc) {
                    const packages = rankSvc.packages ? Object.keys(rankSvc.packages).map(k => ({ id: k, ...rankSvc.packages[k] })) : [];
                    userStates[sender] = { ...st, step: 'PICK_SERVICE_PKG', service: rankSvc, packages };
                    const list = packages.map((p, i) => `*${i+1}.* ${p.label} — Rs.${p.price}`).join('\n');
                    await send(`🏆 Rank Pushing\n\n${list}\n\nHow many stars do you want?`);
                } else {
                    await send(`🏆 Rank Pushing — Rs.40 per 25 stars\n\nHow many stars do you want?\nExample: type *25* for Rs.40, *50* for Rs.80\n\nSusant will contact you with details 🤝`);
                    userStates[sender] = { ...st, step: 'OTHER_GAME' };
                }
                return;
            }
            if (t === '5' || t.includes('bot') || t.includes('buy bot') || t.includes('setup')) {
                userStates[sender] = { ...st, step: 'BOT_INQUIRY' };
                await send(
                    `🤖 *WhatsApp Bot Setup*\n\n` +
                    `Get your own WhatsApp business bot just like this one!\n\n` +
                    `✅ Full setup & configuration\n` +
                    `✅ Admin panel included\n` +
                    `✅ Firebase database\n` +
                    `✅ AI-powered replies\n` +
                    `✅ 4 months support\n\n` +
                    `💰 *Setup Fee: Rs. 1,500*\n\n` +
                    `To proceed, please make the payment of *Rs. 1,500* and send the screenshot here 📸\n\n` +
                    `After payment Susant will contact you to set everything up 🙌`
                );
                await delay(600);
                // send QR for bot payment too
                const settings = await fbGet('settings');
                const upi = settings?.upi || null;
                const qrUrl = settings?.qr_image_url || null;
                const payMsg = `💳 Payment Details\n\nAmount: Rs. 1,500` + (upi ? `\nUPI: ${upi}` : '') + `\n\n⚠️ Write your Name + Phone in the remark!\nExample: Susant - 98XXXXXXXX`;
                if (qrUrl) {
                    await sock.sendPresenceUpdate('composing', sender);
                    await delay(800);
                    await sock.sendMessage(sender, { image: { url: qrUrl }, caption: payMsg });
                } else if (fs.existsSync('./payment.jpeg')) {
                    await sock.sendPresenceUpdate('composing', sender);
                    await delay(800);
                    await sock.sendMessage(sender, { image: fs.readFileSync('./payment.jpeg'), caption: payMsg });
                } else {
                    await send(payMsg);
                }
                await delay(500);
                await send(`⚠️ *Disclaimer:* Money will not be refunded if your payment remark does not include your Name and Phone Number. Please double-check before paying 🙏`);
                return;
            }

            if (t === '6' || t.includes('ask') || t.includes('question') || t.includes('faq')) {
                userStates[sender] = { ...st, step: 'ASK_QUESTION' };
                await send(`Sure! Ask me anything 😊\n\nYou can ask about:\n• Panel prices & features\n• How top-up works\n• Which panel is best\n• Anything else about our services`);
                return;
            }

            if (t === '3' || t.includes('top') || t.includes('diamond') || t.includes('game')) {                const data = await fbGet('games');
                const games = toArray(data);
                if (!games.length) { await send('No top-up packages right now, check back soon!'); return; }
                userStates[sender] = { ...st, step: 'PICK_GAME', games };
                const list = games.map((g, i) => `*${i+1}.* ${g.name}`).join('\n');
                await send(`Which game do you want to top-up? 💎\n\n${list}\n*${games.length+1}.* Other game\n\nReply with the number`);
                return;
            }
            // unknown input — try AI first, always
            if (GEMINI_KEY) {
                const ctx = await buildBusinessContext();
                const aiReply = await askGemini(rawText, ctx, userName);
                if (aiReply) {
                    if (aiReply.includes('[ORDER_INTENT]')) {
                        const clean = aiReply.replace('[ORDER_INTENT]', '').trim();
                        if (clean) await send(clean);
                        await delay(500);
                        await send(`What do you want to order?\n\n*1* 💬 General\n*2* 🎯 Panels\n*3* 💎 Diamond Top-Up\n*4* 🤖 Buy This Bot\n*5* ❓ Ask a Question`);
                    } else {
                        await send(aiReply);
                        await delay(400);
                        await send(`Type *menu* anytime to see all options 😊`);
                    }
                    return;
                }
            }
            await send(`Reply with *1, 2, 3, 4 or 5* 😊\n\nOr type *menu* to see options`);
            return;
        }

        // ── ASK_QUESTION ──────────────────────────────────────────
        if (st.step === 'ASK_QUESTION') {
            if (GEMINI_KEY) {
                try {
                    const ctx = await buildBusinessContext();
                    const aiReply = await askGemini(rawText, ctx, userName);
                    if (aiReply && aiReply.trim()) {
                        const clean = aiReply.replace('[ORDER_INTENT]', '').trim();
                        await send(clean);
                        await delay(600);
                        if (aiReply.includes('[ORDER_INTENT]')) {
                            userStates[sender] = { step: 'PICK_CATEGORY', name: userName };
                            await send(`Want to place an order?\n\n*1* 💬 General\n*2* 🎯 Panels\n*3* 💎 Diamond Top-Up\n*4* 🤖 Buy This Bot`);
                        } else {
                            await send(`Got more questions? Just ask 😊\nOr type *menu* to go back to the main menu`);
                        }
                        return;
                    }
                } catch (e) {
                    console.error('AI question error:', e.message);
                }
            }
            await send(`For pricing and details:\n\n🎯 *Panels* — type *2* to see all panels with prices\n💎 *Top-Up* — type *3* to see diamond packages\n\nOr ask me anything and I'll try my best! 😊`);
            return;
        }

        // ── BOT_INQUIRY ───────────────────────────────────────────
        if (st.step === 'BOT_INQUIRY') {
            const hasImage = !!(msg.message?.imageMessage);
            await fbPost('orders', {
                type: 'bot_setup', item: 'WhatsApp Bot Setup 4 months', price: 1500,
                paymentProof: hasImage ? '[Screenshot received]' : rawText,
                waNumber: waNum, status: 'Pending', timestamp: new Date().toISOString()
            });
            await sendOrderEmail({ type: 'bot_setup', name: waNum, phone: '-', item: 'Bot Setup 4 months', price: 1500, waNumber: waNum, timestamp: new Date().toISOString() });
            userStates[sender] = { step: 'DONE' };
            await send(`Payment received! ✅ Susant will contact you shortly to set up your bot. Thank you 🙏`);
            return;
        }

        // ── PICK_GAME ─────────────────────────────────────────────
        if (st.step === 'PICK_GAME') {
            // try AI first for questions/general chat
            if (GEMINI_KEY && isNaN(parseInt(t)) && t.length > 3 && !t.includes('other') && !t.includes('custom')) {
                const ctx = await buildBusinessContext();
                const aiReply = await askGemini(rawText, ctx, userName);
                if (aiReply && !aiReply.includes('[ORDER_INTENT]')) {
                    await send(aiReply);
                    return;
                }
            }
            const games = st.games, otherNum = games.length + 1;
            if (t === String(otherNum) || t.includes('other') || t.includes('custom')) {
                userStates[sender] = { ...st, step: 'OTHER_GAME' };
                await send(`Sure! Tell me which game and what you need. Susant will reach out to you with the deal 🤝`);
                return;
            }
            const picked = pickItem(games, t, (g, input) => g.name.toLowerCase().includes(input));
            if (!picked) {
                const list = games.map((g, i) => `*${i+1}.* ${g.name}`).join('\n');
                await send(`Hmm I couldn't find that one. Here are the options:\n\n${list}\n*${otherNum}.* Other\n\nReply with a number`);
                return;
            }
            const packages = toArray(picked.packages);
            if (!packages.length) { await send(`No packages for ${picked.name} right now, check back soon!`); return; }
            userStates[sender] = { ...st, step: 'PICK_PACKAGE', game: picked, packages };
            const list = packages.map((p, i) => `*${i+1}.* ${p.label} — ₹${p.price}`).join('\n');
            await send(`${picked.name} packages 🔥\n\n${list}\n\nWhich one do you want?`);
            return;
        }

        // ── OTHER_GAME ────────────────────────────────────────────
        if (st.step === 'OTHER_GAME') {
            await fbPost('orders', { type: 'custom_request', message: rawText, waNumber: waNum, status: 'Pending', timestamp: new Date().toISOString() });
            await sendOrderEmail({ type: 'custom_request', name: waNum, phone: '-', item: rawText, price: 0, waNumber: waNum, timestamp: new Date().toISOString() });
            userStates[sender] = { ...st, step: 'DONE' };
            await send(`Got it! Your request has been saved. Susant will message you soon with the deal 🤝`);
            return;
        }

        // ── PICK_SERVICE ──────────────────────────────────────────
        if (st.step === 'PICK_SERVICE') {
            // try AI first for non-number inputs (questions, general chat)
            if (GEMINI_KEY && isNaN(parseInt(t)) && t.length > 3) {
                const ctx = await buildBusinessContext();
                const aiReply = await askGemini(rawText, ctx, userName);
                if (aiReply && !aiReply.includes('[ORDER_INTENT]')) {
                    await send(aiReply);
                    return;
                }
            }
            const picked = pickItem(st.services, t, (s, input) => s.name.toLowerCase().includes(input));
            if (!picked) {
                const list = st.services.map((s, i) => `*${i+1}.* ${s.name}`).join('\n');
                await send(`Couldn't find that one. Here are the options:\n\n${list}\n\nReply with a number`);
                return;
            }
            const packages = picked.packages ? Object.keys(picked.packages).map(k => ({ id: k, ...picked.packages[k] })) : [];
            if (packages.length) {
                userStates[sender] = { ...st, step: 'PICK_SERVICE_PKG', service: picked, packages };
                const list = packages.map((p, i) => `*${i+1}.* ${p.label} — Rs.${p.price}`).join('\n');
                // add certificate note for Fluorite
                const isFluorite = picked.name.toLowerCase().includes('fluorite');
                const note = isFluorite
                    ? `\n\n⚠️ *Certificate Note:*\nCertificate is *mandatory* for iOS Fluorite.\nCost: *Rs.999 one-time per device (lifetime)*\nIf you already have a certificate, you only pay for the panel package.`
                    : '';
                await send(`${picked.name} 🎯\n\n${list}${note}\n\nWhich package do you want?`);
            } else {
                userStates[sender] = { ...st, step: 'ASK_DURATION', service: picked };
                // build duration list with prices if available
                const pkgList = picked.packages ? Object.values(picked.packages) : [];
                let durationMsg;
                if (pkgList.length) {
                    const opts = pkgList.map((p, i) => `*D${i+1}* — ${p.label} · ₹${p.price}`).join('\n');
                    // store packages in state so we can look up price by D-index
                    userStates[sender] = { ...st, step: 'ASK_DURATION', service: picked, durationPkgs: pkgList };
                    durationMsg = `${picked.name} 🎯\n\nChoose duration:\n\n${opts}\n\nReply with D1, D2, D3...`;
                } else {
                    durationMsg = `${picked.name} 🎯\n\nHow many days?\n\n*D1* — 1 Day\n*D2* — 3 Days\n*D3* — 7 Days\n*D4* — 15 Days\n*D5* — 30 Days\n\nReply with D1, D2, D3, D4 or D5`;
                }
                await send(durationMsg);
            }
            return;
        }

        // ── PICK_SERVICE_PKG ──────────────────────────────────────
        if (st.step === 'PICK_SERVICE_PKG') {
            const picked = pickItem(st.packages, t, (p, input) => p.label.toLowerCase().includes(input));
            if (!picked) {
                const list = st.packages.map((p, i) => `*${i+1}.* ${p.label} — ₹${p.price}`).join('\n');
                await send(`Couldn't find that. Here are the options:\n\n${list}\n\nReply with a number`);
                return;
            }
            userStates[sender] = { ...st, step: 'ASK_NAME', orderData: { type: 'service', item: `${st.service.name} ${picked.label}`, price: picked.price } };
            await send(`${st.service.name} ${picked.label} for ₹${picked.price} ✅\n\nWhat's your name?`);
            return;
        }

        // ── ASK_DURATION ──────────────────────────────────────────
        if (st.step === 'ASK_DURATION') {
            // if we have actual packages with prices, match by D-index
            if (st.durationPkgs && st.durationPkgs.length) {
                const match = t.match(/^d(\d+)$/);
                const idx = match ? parseInt(match[1]) - 1 : null;
                if (idx === null || idx < 0 || idx >= st.durationPkgs.length) {
                    const opts = st.durationPkgs.map((p, i) => `*D${i+1}* — ${p.label} · ₹${p.price}`).join('\n');
                    await send(`Please reply with:\n\n${opts}`);
                    return;
                }
                const pkg = st.durationPkgs[idx];
                userStates[sender] = { ...st, step: 'ASK_NAME', orderData: { type: 'service', item: `${st.service.name} — ${pkg.label}`, price: pkg.price } };
                await send(`${st.service.name} — ${pkg.label} for ₹${pkg.price} ✅\n\nWhat's your name?`);
                return;
            }
            // fallback — no packages, use generic durations
            const durationMap = {
                'd1': '1 Day',  '1 day': '1 Day',  '1': '1 Day',
                'd2': '3 Days', '3 days': '3 Days', '3': '3 Days',
                'd3': '7 Days', '7 days': '7 Days', '7': '7 Days',
                'd4': '15 Days','15 days': '15 Days','15': '15 Days',
                'd5': '30 Days','30 days': '30 Days','30': '30 Days',
            };
            const duration = durationMap[t] || null;
            if (!duration) {
                await send(`Please reply with:\n*D1* — 1 Day\n*D2* — 3 Days\n*D3* — 7 Days\n*D4* — 15 Days\n*D5* — 30 Days`);
                return;
            }
            userStates[sender] = { ...st, step: 'ASK_NAME', orderData: { type: 'service', item: `${st.service.name} — ${duration}`, price: 0 } };
            await send(`${st.service.name} for ${duration} noted ✅\n\nPrice will be confirmed by Susant after payment verification.\n\nWhat's your name?`);
            return;
        }

        // ── PICK_PACKAGE ──────────────────────────────────────────
        if (st.step === 'PICK_PACKAGE') {
            const picked = pickItem(st.packages, t, (p, input) => p.label.toLowerCase().includes(input));
            if (!picked) {
                const list = st.packages.map((p, i) => `*${i+1}.* ${p.label} — ₹${p.price}`).join('\n');
                await send(`Couldn't find that. Here are the options:\n\n${list}\n\nReply with a number`);
                return;
            }
            userStates[sender] = { ...st, step: 'ASK_UID', orderData: { type: 'topup', game: st.game.name, gameId: st.game.id, package: picked.label, packageId: picked.id, price: picked.price } };
            await send(`${st.game.name} ${picked.label} for ₹${picked.price} ✅\n\nSend me your game UID 🎮`);
            return;
        }

        // ── ASK_UID ───────────────────────────────────────────────
        if (st.step === 'ASK_UID') {
            userStates[sender] = { ...st, step: 'ASK_GAME_PHONE', orderData: { ...st.orderData, uid: rawText } };
            await send(`Got the UID ✅\n\nNow send the phone number linked to your game account (with country code) 📱\nExample: *9779708838261* (977 = Nepal)`);
            return;
        }

        // ── ASK_GAME_PHONE ────────────────────────────────────────
        if (st.step === 'ASK_GAME_PHONE') {
            userStates[sender] = { ...st, step: 'ASK_NAME', orderData: { ...st.orderData, gamePhone: rawText } };
            await send(`Perfect ✅ What's your name?`);
            return;
        }

        // ── ASK_NAME ──────────────────────────────────────────────
        if (st.step === 'ASK_NAME') {
            userStates[sender] = { ...st, step: 'ASK_PHONE', name: rawText, orderData: { ...st.orderData, name: rawText } };
            await saveUser(waNum, { name: rawText }); // save name persistently
            await send(`Nice to meet you ${rawText}! 👋 What's your phone number?`);
            return;
        }

        // ── ASK_PHONE ─────────────────────────────────────────────
        if (st.step === 'ASK_PHONE') {
            const phoneValidation = validatePhoneNumber(rawText);
            if (!phoneValidation.valid) {
                await send(`❌ ${phoneValidation.message}\n\nPlease try again`);
                return;
            }
            
            userStates[sender] = { ...st, step: 'SEND_PAYMENT', orderData: { ...st.orderData, phone: phoneValidation.phone } };
            const settings = await fbGet('settings');
            const upi = settings?.upi || null;
            const qrUrl = settings?.qr_image_url || null;
            const od = st.orderData;
            
            const paymentMsg = `💳 *PAYMENT*\n\nAmount: ₹${od.price}\nName: ${od.name}\nPhone: ${phoneValidation.phone}\n\n*Methods:* UPI / Bank / eSewa\n\n*Remark:* ${od.name} - ${phoneValidation.phone}\n\nAfter paying, send screenshot 📸`;

            if (qrUrl) {
                await sock.sendPresenceUpdate('composing', sender);
                await delay(1000);
                await sock.sendMessage(sender, { image: { url: qrUrl }, caption: paymentMsg }).catch(() => {
                    if (fs.existsSync('./payment.jpeg')) {
                        sock.sendMessage(sender, { image: fs.readFileSync('./payment.jpeg'), caption: paymentMsg });
                    } else {
                        send(paymentMsg);
                    }
                });
            } else if (fs.existsSync('./payment.jpeg')) {
                await sock.sendPresenceUpdate('composing', sender);
                await delay(1000);
                await sock.sendMessage(sender, { image: fs.readFileSync('./payment.jpeg'), caption: paymentMsg });
            } else {
                await send(paymentMsg);
            }

            await delay(800);
            await send(`✅ Payment details sent!\n\n⏱️ Verification: 10-20 minutes`);
            return;
        }

        // ── SEND_PAYMENT ──────────────────────────────────────────
        if (st.step === 'SEND_PAYMENT') {
            const hasImage = !!(msg.message?.imageMessage);
            const proof = hasImage ? '[Screenshot received]' : rawText;
            const od = st.orderData;
            const waNum = sender.split('@')[0];
            
            const orderRecord = {
                type: od.type,
                game: od.game || null,
                package: od.package || null,
                uid: od.uid || null,
                gamePhone: od.gamePhone || null,
                item: od.item || null,
                name: od.name,
                phone: od.phone,
                price: od.price,
                paymentProof: proof,
                waNumber: waNum,
                status: 'Pending',
                timestamp: new Date().toISOString(),
                paymentMethod: hasImage ? 'screenshot' : 'text'
            };
            
            await fbPost('orders', orderRecord);
            await sendOrderEmail({ ...od, waNumber: waNum, timestamp: new Date().toISOString() });
            await saveUser(waNum, { lastOrderWa: sender, lastOrderTime: new Date().toISOString() });
            
            userStates[sender] = { step: 'RETURNING', name: od.name };
            
            await send(
                `✅ *ORDER CONFIRMED*\n\n` +
                (od.game ? `🎮 Game: ${od.game}\n📦 Package: ${od.package}\n` : `🎯 Service: ${od.item}\n`) +
                `💰 Amount: ₹${od.price}\n\n` +
                `📋 Status: ⏳ *Verification In Progress*\n` +
                `We're checking your payment...\n\n` +
                `⏱️ Time: 10-20 minutes`
            );
            
            await delay(800);
            await send(`📌 *What Happens Next:*\n1️⃣ Admin verifies payment\n2️⃣ Order gets processed\n3️⃣ We notify you\n\n💬 Any issues? Reply *help*`);
            
            await delay(400);
            await send(`Type *restart* for new order or *stop* to end`);
            return;
        }

        // ── DONE ──────────────────────────────────────────────────
        if (st.step === 'DONE') {
            await send(`Type restart to place a new order or stop to end 😊`);
            return;
        }

        // ── AI fallback ───────────────────────────────────────────
        if (GEMINI_KEY) {
            const ctx = await buildBusinessContext();
            const aiReply = await askGemini(rawText, ctx, userName);
            if (aiReply) {
                if (aiReply.includes('[ORDER_INTENT]')) {
                    const clean = aiReply.replace('[ORDER_INTENT]', '').trim();
                    if (clean) await send(clean);
                    await delay(500);
                    userStates[sender] = { step: 'PICK_CATEGORY' };
                    await send(`What do you want to order?\n\n*1* General\n*2* Panels\n*3* Diamond Top-Up\n*4* Buy This Bot`);
                } else {
                    await send(aiReply);
                }
                return;
            }
        }

        await send(`Type *menu* to see all options, or ask me anything about panels and top-up 😊`);
    });
}

// ── Broadcast delivery ───────────────────────────────────────────
async function deliverBroadcasts() {
    if (!sock) return;
    try {
        const broadcasts = await fbGet('broadcasts');
        if (!broadcasts) return;
        const users = await fbGet('users');
        if (!users) return;

        for (const [bid, broadcast] of Object.entries(broadcasts)) {
            if (broadcast.status !== 'pending') continue;

            // mark as sending
            await fbPatch(`broadcasts/${bid}`, { status: 'sending' });

            let sent = 0;
            for (const [waNum, user] of Object.entries(users)) {
                try {
                    const sender = waNum + '@s.whatsapp.net';
                    await sock.sendMessage(sender, { text: broadcast.message });
                    sent++;
                    await delay(1500); // avoid spam detection
                } catch (e) { console.error(`Broadcast failed for ${waNum}:`, e.message); }
            }

            await fbPatch(`broadcasts/${bid}`, { status: 'done', sentTo: sent });
            console.log(`[BROADCAST] Sent to ${sent} users`);
        }
    } catch (e) { console.error('Broadcast error:', e.message); }
}

// ── Order status change listener ─────────────────────────────────
// Watches Firebase orders and messages customer when status changes
function listenOrderStatusChanges() {
    if (!sock) return;
    const statusCache = {};

    setInterval(async () => {
        try {
            const orders = await fbGet('orders');
            if (!orders) return;
            for (const [id, order] of Object.entries(orders)) {
                if (!order.waNumber || !order.status) continue;
                const cacheKey = `${id}_${order.status}`;
                if (statusCache[cacheKey]) continue; // already notified
                statusCache[cacheKey] = true;
                // only notify on status changes to Processing or Completed
                if (order.status === 'Processing' || order.status === 'Completed') {
                    const prevKey = Object.keys(statusCache).find(k => k.startsWith(id + '_') && k !== cacheKey);
                    if (!prevKey) continue; // first time seeing this order, skip
                    const sender = order.waNumber + '@s.whatsapp.net';
                    const name = order.name || 'bro';
                    let msg = '';
                    if (order.status === 'Processing') {
                        msg = `Hey ${name}! Your order is being processed right now 🔄 We are working on it, won't take long!`;
                    } else if (order.status === 'Completed') {
                        msg = `${name} your order is done! ✅\n\n` +
                            (order.game ? `Game ${order.game}\nPackage ${order.package}\nUID ${order.uid}\n` : `Service ${order.item}\n`) +
                            `\nEnjoy! If you have any issues just message us 🙏`;
                    }
                    if (msg) {
                        await sock.sendMessage(sender, { text: msg });
                        console.log(`[STATUS NOTIFY] ${order.waNumber} → ${order.status}`);
                    }
                }
            }
        } catch (e) { console.error('Status listener error:', e.message); }
    }, 30_000); // check every 30 seconds
}

// ── 24/7 Error Handling & Auto-Recovery ─────────────────────────
let errorCount = 0;

process.on('uncaughtException', (err) => {
    errorCount++;
    console.error(`[ERROR ${errorCount}]`, err.message);
    if (errorCount > 3) process.exit(1);
});

process.on('unhandledRejection', (reason) => {
    console.error('[REJECTION]', reason);
});

process.on('SIGTERM', () => {
    console.log('[SIGNAL] SIGTERM received - graceful exit');
    process.exit(0);
});

// Exit after 27 min (leaves buffer before GitHub 28 min timeout)
setTimeout(() => {
    console.log('[TIMEOUT] Graceful exit for workflow restart');
    process.exit(0);
}, 27 * 60 * 1000);

startBot().catch(err => {
    console.error('[STARTUP] Bot error:', err.message);
    process.exit(1);
});

// Health check every 5 minutes
setInterval(() => {
    console.log(`[OK] ${Object.keys(userStates).length} users | ${new Date().toISOString()}`);
}, 5 * 60 * 1000);
