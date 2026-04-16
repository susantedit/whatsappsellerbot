const { default: makeWASocket, useMultiFileAuthState, DisconnectReason, fetchLatestBaileysVersion } = require('@whiskeysockets/baileys');
const qrcode = require('qrcode-terminal');
const pino   = require('pino');
const fs     = require('fs');
const nodemailer = require('nodemailer');

const FIREBASE_URL  = process.env.FIREBASE_URL;
const GEMINI_KEY    = process.env.GEMINI_API_KEY;
const SMTP_USER     = process.env.SMTP_USER;
const SMTP_PASS     = process.env.SMTP_PASS;
const NOTIFY_EMAIL  = process.env.NOTIFY_EMAIL || SMTP_USER; // where to send order alerts

const userStates = {};

// ── Rate limiting ────────────────────────────────────────────────
const rateLimits = {};
function isRateLimited(sender) {
    const now = Date.now(), window = 30 * 60_000, max = 50;
    if (!rateLimits[sender]) rateLimits[sender] = { count: 0, start: now };
    if (now - rateLimits[sender].start > window) rateLimits[sender] = { count: 0, start: now };
    rateLimits[sender].count++;
    return rateLimits[sender].count > max;
}

function sanitizeInput(text) {
    return String(text).replace(/[<>"'`]/g, '').trim().slice(0, 500);
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
      <a href="https://whatsapp.com/send?phone=${order.waNumber}" style="display:inline-block;background:#25d366;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:700;font-size:0.9rem;letter-spacing:1px;">💬 Reply on WhatsApp</a>
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
        Object.values(services).forEach(s => { ctx += `${s.name}: Rs.${s.price}${s.description ? ' (' + s.description + ')' : ''}\n`; });
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
            `Keep replies conversational, 2 to 4 sentences max. ` +
            `Never use bullet points, dashes, or lists in your reply. Just talk naturally like texting. ` +
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
                    generationConfig: { maxOutputTokens: 200, temperature: 0.85 }
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
        const waNum   = sender.split('@')[0];
        const rawText = sanitizeInput(msg.message.conversation || msg.message.extendedTextMessage?.text || '');
        const t       = rawText.toLowerCase();

        if (isRateLimited(sender)) { console.warn(`[RATE LIMIT] ${waNum}`); return; }
        console.log(`[${waNum}] ${rawText}`);

        // typing indicator
        const send = async (text) => {
            await sock.sendPresenceUpdate('composing', sender);
            await delay(800 + Math.random() * 600);
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
            await send(`You can reply with 1 for General, 2 for Panels, 3 for Diamond Top-Up or 4 to Buy This Bot. Type restart anytime to start over or stop to end the chat.`);
            return;
        }

        // ── Load persistent user data ─────────────────────────────
        const userData = await getUser(waNum);
        const userName = userStates[sender]?.name || userData.name || null;

        // ── New user ──────────────────────────────────────────────
        if (!userStates[sender]) {
            userStates[sender] = { step: 'PICK_CATEGORY' };
            const menu = `*1* 💬 General — Talk to Susant directly\n*2* 🎯 Panels — Free Fire hack panels (auto headshot, aimbot etc.)\n*3* 💎 Diamond Top-Up — Buy diamonds for Free Fire & other games\n*4* 🤖 Buy This Bot — Get your own WhatsApp bot like this`;
            const greeting = userData.name
                ? `Hey ${userData.name}! Good to see you again 👋\n\nWhat can I help you with?\n\n${menu}`
                : `Hey! Welcome to Game Panel 👋\n\nHere's what I can help you with:\n\n${menu}\n\nJust reply with a number 😊`;
            await send(greeting);
            return;
        }

        // ── Returning user ────────────────────────────────────────
        if (userStates[sender]?.step === 'RETURNING') {
            const name = userStates[sender].name || userData.name;
            userStates[sender] = { step: 'PICK_CATEGORY', name };
            await send(`Hey ${name}! 👋 What do you need today?\n\n*1* 💬 General\n*2* 🎯 Panels\n*3* 💎 Diamond Top-Up\n*4* 🤖 Buy This Bot`);
            return;
        }

        const st = userStates[sender];

        // ── PICK_CATEGORY ─────────────────────────────────────────
        if (st.step === 'PICK_CATEGORY') {
            if (t === '1' || t.includes('general')) {
                const settings = await fbGet('settings');
                const owner = settings?.owner || 'the owner';
                await send(`Hey so ${owner} is not available right now but your message has been noted. They will get back to you soon 🙏\n\n_Type *menu* anytime if you want to check panels or top-up while you wait_ 😊`);
                userStates[sender] = { step: 'SILENT', blockedUntil: Date.now() + 24 * 60 * 60 * 1000 };
                return;
            }
            if (t === '2' || t.includes('panel') || t.includes('service')) {
                const data = await fbGet('services');
                const services = toArray(data);
                if (!services.length) { await send('No panels available right now, check back soon!'); return; }
                userStates[sender] = { ...st, step: 'PICK_SERVICE', services };
                const list = services.map((s, i) => {
                    const pkgs = s.packages ? Object.values(s.packages) : [];
                    const prices = pkgs.map(p => `${p.label} ₹${p.price}`).join(' | ');
                    return `*${i+1}.* ${s.name}\n    ${prices || `₹${s.price}`}${s.description ? '\n    _' + s.description + '_' : ''}`;
                }).join('\n\n');
                await send(`Here are all the panels 🎯\n\n${list}\n\nReply with the number you want`);
                return;
            }
            if (t === '4' || t.includes('bot') || t.includes('buy bot') || t.includes('setup')) {
                userStates[sender] = { ...st, step: 'BOT_INQUIRY' };
                await send(
                    `🤖 *WhatsApp Bot Setup*\n\n` +
                    `Get your own WhatsApp business bot just like this one!\n\n` +
                    `✅ Full setup & configuration\n` +
                    `✅ Admin panel included\n` +
                    `✅ Firebase database\n` +
                    `✅ AI-powered replies\n` +
                    `✅ 12 months support\n\n` +
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

            if (t === '3' || t.includes('top') || t.includes('diamond') || t.includes('game')) {                const data = await fbGet('games');
                const games = toArray(data);
                if (!games.length) { await send('No top-up packages right now, check back soon!'); return; }
                userStates[sender] = { ...st, step: 'PICK_GAME', games };
                const list = games.map((g, i) => `*${i+1}.* ${g.name}`).join('\n');
                await send(`Which game do you want to top-up? 💎\n\n${list}\n*${games.length+1}.* Other game\n\nReply with the number`);
                return;
            }
            // unknown — try AI
            if (GEMINI_KEY) {
                const ctx = await buildBusinessContext();
                const aiReply = await askGemini(rawText, ctx, userName);
                if (aiReply) {
                    if (aiReply.includes('[ORDER_INTENT]')) {
                        const clean = aiReply.replace('[ORDER_INTENT]', '').trim();
                        if (clean) await send(clean);
                        await delay(500);
                        await send(`So what do you want to order?\n\n*1* General\n*2* Panels\n*3* Diamond Top-Up\n*4* Buy This Bot`);
                    } else {
                        await send(aiReply);
                    }
                    return;
                }
            }
            await send(`Just reply with 1, 2 or 3 😊`);
            return;
        }

        // ── BOT_INQUIRY ───────────────────────────────────────────
        if (st.step === 'BOT_INQUIRY') {
            const hasImage = !!(msg.message?.imageMessage);
            await fbPost('orders', {
                type: 'bot_setup', item: 'WhatsApp Bot Setup 12 months', price: 1500,
                paymentProof: hasImage ? '[Screenshot received]' : rawText,
                waNumber: waNum, status: 'Pending', timestamp: new Date().toISOString()
            });
            await sendOrderEmail({ type: 'bot_setup', name: waNum, phone: '-', item: 'Bot Setup 12 months', price: 1500, waNumber: waNum, timestamp: new Date().toISOString() });
            userStates[sender] = { step: 'DONE' };
            await send(`Payment received! ✅ Susant will contact you shortly to set up your bot. Thank you 🙏`);
            return;
        }

        // ── PICK_GAME ─────────────────────────────────────────────
        if (st.step === 'PICK_GAME') {
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
            const picked = pickItem(st.services, t, (s, input) => s.name.toLowerCase().includes(input));
            if (!picked) {
                const list = st.services.map((s, i) => `*${i+1}.* ${s.name}`).join('\n');
                await send(`Couldn't find that one. Here are the options:\n\n${list}\n\nReply with a number`);
                return;
            }
            // if service has packages, show them like games
            const packages = picked.packages ? Object.keys(picked.packages).map(k => ({ id: k, ...picked.packages[k] })) : [];
            if (packages.length) {
                userStates[sender] = { ...st, step: 'PICK_SERVICE_PKG', service: picked, packages };
                const list = packages.map((p, i) => `*${i+1}.* ${p.label} — ₹${p.price}`).join('\n');
                await send(`${picked.name} 🎯\n\n${list}\n\nWhich one do you want?`);
            } else {
                userStates[sender] = { ...st, step: 'ASK_NAME', orderData: { type: 'service', item: picked.name, price: picked.price } };
                await send(`Nice choice! ${picked.name} for ₹${picked.price} 🎯\n\nWhat's your name?`);
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
            userStates[sender] = { ...st, step: 'ASK_NAME', orderData: { ...st.orderData, uid: rawText } };
            await send(`Got the UID ✅ What's your name?`);
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
            userStates[sender] = { ...st, step: 'SEND_PAYMENT', orderData: { ...st.orderData, phone: rawText } };
            const settings = await fbGet('settings');
            const upi = settings?.upi || null;
            const qrUrl = settings?.qr_image_url || null;
            const od = st.orderData;
            const paymentMsg =
                `Payment details 💳\n\nAmount ₹${od.price}` +
                (upi ? `\nUPI ${upi}` : '') +
                `\n\nWhen paying please write your name and phone number in the remarks like this: ${od.name} ${rawText}\n\nAfter paying send the screenshot here 📸`;
            if (qrUrl) {
                await sock.sendPresenceUpdate('composing', sender);
                await delay(800);
                await sock.sendMessage(sender, { image: { url: qrUrl }, caption: paymentMsg });
            } else if (fs.existsSync('./payment.jpeg')) {
                await sock.sendPresenceUpdate('composing', sender);
                await delay(800);
                await sock.sendMessage(sender, { image: fs.readFileSync('./payment.jpeg'), caption: paymentMsg });
            } else {
                await send(paymentMsg);
            }
            await delay(700);
            await send(`One important thing — if you don't write your name and phone in the payment remarks your order might not get verified. Please make sure you do that before paying 🙏`);
            return;
        }

        // ── SEND_PAYMENT ──────────────────────────────────────────
        if (st.step === 'SEND_PAYMENT') {
            const hasImage = !!(msg.message?.imageMessage);
            const proof = hasImage ? '[Screenshot received]' : rawText;
            const od = st.orderData;
            await fbPost('orders', {
                type: od.type, game: od.game || null, package: od.package || null,
                uid: od.uid || null, item: od.item || null,
                name: od.name, phone: od.phone, price: od.price,
                paymentProof: proof, waNumber: waNum,
                status: 'Pending', timestamp: new Date().toISOString()
            });
            // send email notification to owner
            await sendOrderEmail({ ...od, waNumber: waNum, timestamp: new Date().toISOString() });
            // save order ref to user profile for status notifications
            await saveUser(waNum, { lastOrderWa: sender });
            userStates[sender] = { step: 'RETURNING', name: od.name };
            await send(
                `Order received ${od.name}! 🎉\n\n` +
                (od.game ? `Game: ${od.game}\nPackage: ${od.package}\nUID: ${od.uid}\n` : `Service: ${od.item}\n`) +
                `Amount: ₹${od.price}\n\nWe will process it after verifying your payment. Usually takes 15–30 minutes. We will message you when it's done 🙌`
            );
            await delay(600);
            await send(
                `⚠️ *Disclaimer:*\nIf your payment does NOT include your Name and Phone Number in the remark, your order cannot be verified and the amount may not be recoverable.\n\nPlease make sure you added: *${od.name} - ${od.phone}* in the remark before paying 🙏`
            );
            await delay(400);
            await send(`Need anything else? Type *restart* for a new order or *stop* to end the chat`);
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

        await send(`Didn't quite get that 😅 Type help to see options or restart to start over`);
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

startBot().catch(err => console.error('Fatal error:', err));
