const {
    default: makeWASocket,
    useMultiFileAuthState,
    DisconnectReason,
    fetchLatestBaileysVersion
} = require('@whiskeysockets/baileys');
const qrcode = require('qrcode-terminal');
const pino = require('pino');

const FIREBASE_URL = process.env.FIREBASE_URL;

// Per-user conversation state
const userStates = {};

// ── Rate limiting: max 30 messages per user per minute ──────────
const rateLimits = {};
function isRateLimited(sender) {
    const now = Date.now();
    const window = 30 * 60_000; // 30 minutes
    const max = 50;
    if (!rateLimits[sender]) rateLimits[sender] = { count: 0, start: now };
    if (now - rateLimits[sender].start > window) {
        rateLimits[sender] = { count: 0, start: now };
    }
    rateLimits[sender].count++;
    return rateLimits[sender].count > max;
}

// ── Sanitize user text input ─────────────────────────────────────
function sanitizeInput(text) {
    return String(text)
        .replace(/[<>"'`]/g, '')   // strip HTML/script chars
        .trim()
        .slice(0, 500);            // hard cap length
}

const delay = ms => new Promise(res => setTimeout(res, ms));

// ─── Firebase helpers ───────────────────────────────────────────
async function fbGet(path) {
    try {
        const res = await fetch(`${FIREBASE_URL}/${path}.json`);
        const data = await res.json();
        return data;
    } catch { return null; }
}

async function fbPost(path, data) {
    try {
        await fetch(`${FIREBASE_URL}/${path}.json`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });
    } catch (e) { console.error('fbPost error:', e); }
}

// ─── Convert Firebase object → sorted array ─────────────────────
function toArray(obj) {
    if (!obj) return [];
    return Object.keys(obj).map(k => ({ id: k, ...obj[k] }));
}

// ─── Build numbered list string ──────────────────────────────────
function numberedList(items, labelFn) {
    return items.map((item, i) => `*${i + 1}.* ${labelFn(item)}`).join('\n');
}

// ─── Pick item by number or text match ──────────────────────────
function pickItem(items, input, matchFn) {
    const num = parseInt(input);
    if (!isNaN(num) && num >= 1 && num <= items.length) return items[num - 1];
    return items.find(item => matchFn(item, input.toLowerCase())) || null;
}

// ────────────────────────────────────────────────────────────────
async function startBot() {
    if (!FIREBASE_URL) {
        console.log('❌ FIREBASE_URL missing in environment!');
        process.exit(1);
    }

    const { state, saveCreds } = await useMultiFileAuthState('session_data');
    const { version } = await fetchLatestBaileysVersion();

    const sock = makeWASocket({
        version,
        auth: state,
        printQRInTerminal: false,
        logger: pino({ level: 'silent' }),
        browser: ['GameServices-Bot', 'Chrome', '1.0']
    });

    sock.ev.on('connection.update', ({ connection, lastDisconnect, qr }) => {
        if (qr) {
            console.clear();
            qrcode.generate(qr, { small: true });
            console.log('\n⚠️  Scan QR above. If too big, click "View raw logs"');
        }
        if (connection === 'open')  console.log('✅ Bot is online!');
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
        const rawText = sanitizeInput(
            msg.message.conversation ||
            msg.message.extendedTextMessage?.text || ''
        );
        const t = rawText.toLowerCase();

        // ── Rate limit check ─────────────────────────────────────
        if (isRateLimited(sender)) {
            console.warn(`[RATE LIMIT] ${sender.split('@')[0]} exceeded message limit`);
            return; // silently drop — don't reply to bots/spammers
        }

        console.log(`📩 [${sender.split('@')[0]}] ${rawText}`);

        // Helper: send text to this sender
        const send = txt => sock.sendMessage(sender, { text: txt });

        await delay(900);

        // ── Global escape commands (work at any step) ────────────
        if (t === 'stop' || t === 'exit' || t === 'cancel') {
            delete userStates[sender];
            await send('Okay, chat ended 👋\nType anything to start again.');
            return;
        }

        if (t === 'restart' || t === 'start' || t === 'menu' ||
            t === 'hi' || t === 'hello' || t === 'hey') {
            delete userStates[sender];
            // fall through to new-user welcome below
        }

        if (t === 'help') {
            await send(
                `Here's what you can do:\n\n` +
                `*1* — General 💬\n` +
                `*2* — Panels 🎯\n` +
                `*3* — Diamond Top-Up 💎\n\n` +
                `*restart* — Start over\n` +
                `*stop* — End chat`
            );
            return;
        }

        // ── New user or after restart ────────────────────────────
        if (!userStates[sender]) {
            userStates[sender] = { step: 'PICK_CATEGORY' };
            await send(
                `👋 *Welcome!*\n\n` +
                `How can we help you today?\n\n` +
                `*1* — General 💬\n` +
                `*2* — Panels 🎯\n` +
                `*3* — Diamond Top-Up 💎\n\n` +
                `_Reply with 1, 2, or 3_`
            );
            return;
        }

        const st = userStates[sender];

        // ════════════════════════════════════════════════════════
        // PICK_CATEGORY
        // ════════════════════════════════════════════════════════
        if (st.step === 'PICK_CATEGORY') {

            // General
            if (t === '1' || t.includes('general')) {
                userStates[sender] = { step: 'GENERAL' };
                const settings = await fbGet('settings');
                const owner = settings?.owner || 'the owner';
                await send(
                    `Hey 👋 *${owner}* isn't available right now.\n\n` +
                    `Your message has been noted — they'll get back to you soon ⏳\n\n` +
                    `Want to buy something? Type *2* for Panels or *3* for Top-Up.`
                );
                return;
            }

            // Panels (Services)
            if (t === '2' || t.includes('panel') || t.includes('service')) {
                const data = await fbGet('services');
                const services = toArray(data);
                if (!services.length) {
                    await send('No panels available right now. Check back soon!');
                    return;
                }
                userStates[sender] = { step: 'PICK_SERVICE', services };
                await send(
                    `🎯 *Available Panels*\n\n` +
                    numberedList(services, s => `*${s.name}* — ₹${s.price}\n   _${s.description || ''}_`) +
                    `\n\n_Reply with the number to select_`
                );
                return;
            }

            // Diamond Top-Up
            if (t === '3' || t.includes('top') || t.includes('diamond') || t.includes('game')) {
                const data = await fbGet('games');
                const games = toArray(data);
                if (!games.length) {
                    await send('No top-up packages available right now. Check back soon!');
                    return;
                }
                userStates[sender] = { step: 'PICK_GAME', games };
                await send(
                    `💎 *Select a Game*\n\n` +
                    numberedList(games, g => g.name) +
                    `\n*${games.length + 1}.* Other / Custom Game\n\n` +
                    `_Reply with the number_`
                );
                return;
            }

            await send(`Please reply with *1*, *2*, or *3* 😊`);
            return;
        }

        // ════════════════════════════════════════════════════════
        // GENERAL follow-up
        // ════════════════════════════════════════════════════════
        if (st.step === 'GENERAL') {
            if (t === '3' || t.includes('topup') || t.includes('diamond') || t.includes('game')) {
                delete userStates[sender];
                const data = await fbGet('games');
                const games = toArray(data);
                userStates[sender] = { step: 'PICK_GAME', games };
                await send(
                    `💎 *Select a Game*\n\n` +
                    numberedList(games, g => g.name) +
                    `\n*${games.length + 1}.* Other / Custom Game\n\n` +
                    `_Reply with the number_`
                );
                return;
            }
            if (t === '2' || t.includes('panel') || t.includes('service')) {
                delete userStates[sender];
                const data = await fbGet('services');
                const services = toArray(data);
                userStates[sender] = { step: 'PICK_SERVICE', services };
                await send(
                    `🎯 *Available Panels*\n\n` +
                    numberedList(services, s => `*${s.name}* — ₹${s.price}\n   _${s.description || ''}_`) +
                    `\n\n_Reply with the number_`
                );
                return;
            }
            await send(
                `Got it 🙏 Your message has been noted.\n\n` +
                `Type *2* for Panels, *3* for Top-Up, or *stop* to end.`
            );
            return;
        }

        // ════════════════════════════════════════════════════════
        // PICK_GAME
        // ════════════════════════════════════════════════════════
        if (st.step === 'PICK_GAME') {
            const games = st.games;
            const otherNum = games.length + 1;

            // "Other" selected
            if (t === String(otherNum) || t.includes('other') || t.includes('custom')) {
                userStates[sender] = { step: 'OTHER_GAME' };
                await send(
                    `📩 *Custom Game Request*\n\n` +
                    `Please tell me:\n` +
                    `- Which game?\n` +
                    `- What you need?\n\n` +
                    `The owner will contact you shortly with a custom deal 🤝`
                );
                return;
            }

            const picked = pickItem(games, t, (g, input) => g.name.toLowerCase().includes(input));
            if (!picked) {
                await send(
                    `Couldn't find that game 😅\n\n` +
                    numberedList(games, g => g.name) +
                    `\n*${otherNum}.* Other / Custom Game\n\n_Reply with a number_`
                );
                return;
            }

            const packages = toArray(picked.packages);
            if (!packages.length) {
                await send(`No packages available for *${picked.name}* right now. Try again later!`);
                return;
            }

            userStates[sender] = { step: 'PICK_PACKAGE', game: picked, packages };
            await send(
                `🔥 *${picked.name} Top-Up*\n\n` +
                numberedList(packages, p => `*${p.label}* — ₹${p.price}`) +
                `\n\n_Reply with the number to select_`
            );
            return;
        }

        // ════════════════════════════════════════════════════════
        // OTHER_GAME — save custom request
        // ════════════════════════════════════════════════════════
        if (st.step === 'OTHER_GAME') {
            await fbPost('orders', {
                type: 'custom_request',
                message: rawText,
                waNumber: sender.split('@')[0],
                status: 'Pending',
                timestamp: new Date().toISOString()
            });
            userStates[sender] = { step: 'DONE' };
            await send(
                `✅ *Request saved!*\n\n` +
                `The owner will reach out to you soon with a custom deal 🤝\n\n` +
                `Type *restart* to start a new order or *stop* to end.`
            );
            return;
        }

        // ════════════════════════════════════════════════════════
        // PICK_SERVICE
        // ════════════════════════════════════════════════════════
        if (st.step === 'PICK_SERVICE') {
            const services = st.services;
            const picked = pickItem(services, t, (s, input) => s.name.toLowerCase().includes(input));
            if (!picked) {
                await send(
                    `Couldn't find that 😅\n\n` +
                    numberedList(services, s => `${s.name} — ₹${s.price}`) +
                    `\n\n_Reply with a number_`
                );
                return;
            }
            userStates[sender] = { step: 'ASK_NAME', orderData: { type: 'service', item: picked.name, price: picked.price } };
            await send(`🛠️ *${picked.name}* — ₹${picked.price}\n\nWhat's your *name*? 😊`);
            return;
        }

        // ════════════════════════════════════════════════════════
        // PICK_PACKAGE
        // ════════════════════════════════════════════════════════
        if (st.step === 'PICK_PACKAGE') {
            const packages = st.packages;
            const picked = pickItem(packages, t, (p, input) => p.label.toLowerCase().includes(input));
            if (!picked) {
                await send(
                    `Couldn't find that 😅\n\n` +
                    numberedList(packages, p => `${p.label} — ₹${p.price}`) +
                    `\n\n_Reply with a number_`
                );
                return;
            }
            userStates[sender] = {
                step: 'ASK_UID',
                orderData: {
                    type: 'topup',
                    game: st.game.name,
                    gameId: st.game.id,
                    package: picked.label,
                    packageId: picked.id,
                    price: picked.price
                }
            };
            await send(`✅ *${st.game.name} — ${picked.label}* (₹${picked.price})\n\nPlease enter your *Game UID* 🎮`);
            return;
        }

        // ════════════════════════════════════════════════════════
        // ASK_UID (top-up only)
        // ════════════════════════════════════════════════════════
        if (st.step === 'ASK_UID') {
            userStates[sender] = { ...st, step: 'ASK_NAME', orderData: { ...st.orderData, uid: rawText } };
            await send(`Got UID: *${rawText}* ✅\n\nWhat's your *name*? 😊`);
            return;
        }

        // ════════════════════════════════════════════════════════
        // ASK_NAME
        // ════════════════════════════════════════════════════════
        if (st.step === 'ASK_NAME') {
            userStates[sender] = { ...st, step: 'ASK_PHONE', orderData: { ...st.orderData, name: rawText } };
            await send(`Nice to meet you, *${rawText}*! 👋\n\nYour *phone number*? 📱`);
            return;
        }

        // ════════════════════════════════════════════════════════
        // ASK_PHONE
        // ════════════════════════════════════════════════════════
        if (st.step === 'ASK_PHONE') {
            userStates[sender] = { ...st, step: 'SEND_PAYMENT', orderData: { ...st.orderData, phone: rawText } };

            const settings = await fbGet('settings');
            const upi = settings?.upi || null;
            const qrUrl = settings?.qr_image_url || null;
            const od = st.orderData;

            const paymentMsg =
                `💳 *Payment Details*\n\n` +
                `Amount: *₹${od.price}*\n` +
                (upi ? `UPI ID: \`${upi}\`\n` : '') +
                `\n⚠️ *IMPORTANT:* Write your name and phone in the payment remark.\n` +
                `Example: _${od.name} - ${rawText}_\n\n` +
                `After paying, send the *payment screenshot* here 📸`;

            if (qrUrl) {
                await sock.sendMessage(sender, { image: { url: qrUrl }, caption: paymentMsg });
            } else {
                await send(paymentMsg);
            }

            await delay(600);
            await send(
                `⚠️ *Disclaimer:*\n` +
                `If your payment does NOT include your name and phone number in the remark, ` +
                `your order may not be verified and money may be lost.\n\n` +
                `Please ensure correct details before paying.`
            );
            return;
        }

        // ════════════════════════════════════════════════════════
        // SEND_PAYMENT — accept screenshot or text confirmation
        // ════════════════════════════════════════════════════════
        if (st.step === 'SEND_PAYMENT') {
            const hasImage = !!(msg.message?.imageMessage);
            const proof = hasImage ? '[Screenshot received]' : rawText;
            const od = st.orderData;

            // Save order to Firebase
            await fbPost('orders', {
                type: od.type,
                game: od.game || null,
                package: od.package || null,
                uid: od.uid || null,
                item: od.item || null,
                name: od.name,
                phone: od.phone,
                price: od.price,
                paymentProof: proof,
                waNumber: sender.split('@')[0],
                status: 'Pending',
                timestamp: new Date().toISOString()
            });

            userStates[sender] = { step: 'DONE' };

            await send(
                `✅ *Order received!*\n\n` +
                (od.game ? `🎮 Game: *${od.game}*\n📦 Package: *${od.package}*\n🆔 UID: *${od.uid}*\n` : `🛠️ Service: *${od.item}*\n`) +
                `👤 Name: *${od.name}*\n📱 Phone: *${od.phone}*\n💰 Amount: ₹${od.price}\n\n` +
                `Your order is being processed ⏳\nYou will be contacted shortly 🙌`
            );

            await delay(700);
            await send(`Need anything else?\n\n*restart* — New order\n*stop* — End chat`);
            return;
        }

        // ════════════════════════════════════════════════════════
        // DONE state
        // ════════════════════════════════════════════════════════
        if (st.step === 'DONE') {
            await send(`Type *restart* to place a new order or *stop* to end 😊`);
            return;
        }

        // ── Fallback ─────────────────────────────────────────────
        await send(`I didn't get that 😅\n\nType *help* to see options or *restart* to start over.`);
    });
}

startBot().catch(err => console.error('Fatal error:', err));
