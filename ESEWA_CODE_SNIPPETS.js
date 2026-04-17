/**
 * Code Snippets for eSewa Integration in index.js
 * Copy these sections to integrate eSewa payment verification
 */

// ============================================================================
// 1. ADD THIS AT TOP OF index.js (after other requires)
// ============================================================================

const { verifyESewaPayment, validatePhoneNumber, validateAmount } = require('./validators');

// ============================================================================
// 2. REPLACE ASK_PHONE STEP (around line 760)
// ============================================================================

// OLD CODE (around line 760-800):
/*
if (st.step === 'ASK_PHONE') {
    userStates[sender] = { ...st, step: 'SEND_PAYMENT', orderData: { ...st.orderData, phone: rawText } };
    const settings = await fbGet('settings');
    ...
}
*/

// NEW CODE - Replace it with this:
if (st.step === 'ASK_PHONE') {
    // ⭐ NEW: Validate phone number
    const phoneValidation = validatePhoneNumber(rawText);
    if (!phoneValidation.valid) {
        await send(`❌ ${phoneValidation.message}`);
        return;
    }
    
    // ⭐ NEW: Validate amount
    const amountValidation = validateAmount(st.orderData.price);
    if (!amountValidation.valid) {
        await send(`❌ ${amountValidation.message}`);
        return;
    }
    
    userStates[sender] = { 
        ...st, 
        step: 'SEND_PAYMENT', 
        orderData: { ...st.orderData, phone: phoneValidation.phone } 
    };
    
    const settings = await fbGet('settings');
    const upi = settings?.upi || null;
    const qrUrl = settings?.qr_image_url || null;
    const od = st.orderData;
    
    const paymentMsg = 
        `💳 Payment Details\n\n` +
        `Amount: ₹${od.price}\n` +
        (upi ? `UPI: ${upi}\n` : '') +
        `\n💬 *Payment Methods:*\n` +
        `✅ eSewa - *Recommended* (Auto-verified)\n` +
        `✅ UPI/Bank Transfer\n` +
        `\n✏️ *Important - Payment Remark:*\n` +
        `${od.name} - ${phoneValidation.phone}\n` +
        `\nAfter paying, send the transaction ID or screenshot 📸`;

    // send QR
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

    await delay(600);
    await send(`⚠️ *Important:* Write your name & phone exactly as shown above in the payment remark!\n\nExample: ${od.name} - ${phoneValidation.phone}`);
    return;
}

// ============================================================================
// 3. REPLACE SEND_PAYMENT STEP (around line 800-850)
// ============================================================================

// OLD CODE:
/*
if (st.step === 'SEND_PAYMENT') {
    const hasImage = !!(msg.message?.imageMessage);
    const proof = hasImage ? '[Screenshot received]' : rawText;
    const od = st.orderData;
    await fbPost('orders', {
        type: od.type, game: od.game || null, package: od.package || null,
        ...
    });
    ...
}
*/

// NEW CODE - Replace it with this:
if (st.step === 'SEND_PAYMENT') {
    const hasImage = !!(msg.message?.imageMessage);
    const proof = hasImage ? '[Screenshot received]' : rawText;
    const od = st.orderData;
    const waNum = sender.split('@')[0];
    
    // ⭐ NEW: Extract transaction ID and verify with eSewa
    let transactionId = rawText;
    let verificationResult = null;
    let paymentStatus = 'Pending';
    
    if (!hasImage) {
        // Try to extract transaction ID (usually 8-20 alphanumeric characters)
        const txMatch = rawText.match(/[A-Z0-9]{8,20}/i);
        if (txMatch) {
            transactionId = txMatch[0];
            console.log(`[eSewa] Attempting verification for: ${transactionId}`);
            
            try {
                // Call eSewa verification
                verificationResult = await verifyESewaPayment(
                    transactionId, 
                    od.price, 
                    od.phone
                );
                
                console.log(`[eSewa] Verification result:`, verificationResult);
                
                // Set order status based on verification
                if (verificationResult.verified) {
                    paymentStatus = 'Processing';  // Auto-process
                    await send(`✅ *Payment Verified!*\n\n🎉 Your order is now being processed!\n\nWe'll message you when it's ready 🚀`);
                } else if (verificationResult.status === 'pending') {
                    paymentStatus = 'Pending';
                    await send(`⏳ *Payment Verification In Progress*\n\nYour payment is being verified (5-15 minutes).\nWe'll confirm once it's done ✅`);
                } else if (verificationResult.status === 'failed') {
                    paymentStatus = 'Failed';
                    await send(`❌ *Payment Verification Failed*\n\n${verificationResult.message}\n\nPlease try again or contact support 🆘`);
                } else {
                    paymentStatus = 'Pending';
                    await send(`⚠️ *Manual Verification*\n\nSusant will verify your payment within 1 hour 🙏`);
                }
            } catch (error) {
                console.error('[eSewa] Verification error:', error.message);
                paymentStatus = 'Pending';
                await send(`⚠️ *Verification In Progress*\n\nWe'll verify your payment and confirm soon 🔄`);
            }
        } else {
            // No transaction ID found
            await send(`⚠️ *Send Transaction ID or Screenshot*\n\nSend either:\n• eSewa transaction ID (e.g., TXN123456789)\n• Payment screenshot\n\nThen Susant will verify it manually`);
            return;
        }
    } else {
        // Screenshot received
        await send(`📸 *Payment Screenshot Received*\n\nSusant will verify it within 15-30 minutes ⏳`);
    }
    
    // Save order with verification data
    const orderData = {
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
        transactionId: transactionId,
        paymentVerification: verificationResult ? {
            status: verificationResult.status,
            verified: verificationResult.verified || false,
            message: verificationResult.message,
            verifiedAt: verificationResult.verifiedAt
        } : null,
        waNumber: waNum,
        status: paymentStatus,
        timestamp: new Date().toISOString()
    };
    
    await fbPost('orders', orderData);
    await sendOrderEmail({ ...od, waNumber: waNum, transactionId, timestamp: new Date().toISOString() });
    await saveUser(waNum, { lastOrderWa: sender });
    
    userStates[sender] = { step: 'RETURNING', name: od.name };
    
    await delay(600);
    await send(`${od.name}, your order details:\n\n` +
        (od.game ? `🎮 Game: ${od.game}\n📦 Package: ${od.package}\n🆔 UID: ${od.uid}\n` : `🎯 Service: ${od.item}\n`) +
        `💰 Amount: ₹${od.price}\n\n` +
        `📋 Status: ${paymentStatus}\n\n` +
        `Type *restart* for a new order or *stop* to end chat 😊`);
    
    return;
}

// ============================================================================
// 4. OPTIONAL: Add Payment Retry Handler
// ============================================================================

// Add this as a new command in the bot (around line 300-350)
if (t === 'verify' || t.includes('retry')) {
    if (userStates[sender]?.orderData?.transactionId) {
        const od = userStates[sender].orderData;
        const txId = userStates[sender].orderData.transactionId;
        
        await send(`🔄 *Retrying Payment Verification*\n\nChecking transaction: ${txId}`);
        
        const retryResult = await verifyESewaPayment(txId, od.price, od.phone);
        console.log('[Retry] Result:', retryResult);
        
        if (retryResult.verified) {
            await send(`✅ *Payment Verified!*\n\n${retryResult.message}\n\nYour order is now Processing!`);
            // Update order status in Firebase
            await fbPatch(`orders`, { status: 'Processing' });
        } else if (retryResult.status === 'pending') {
            await send(`⏳ Still pending. Will auto-verify soon. Check back in 5 minutes.`);
        } else {
            await send(`❌ ${retryResult.message}\n\nPlease try again or contact Susant`);
        }
        return;
    } else {
        await send(`No pending payment to verify. Start a new order?`);
        return;
    }
}

// ============================================================================
// 5. ADD TO FIREBASE EMAIL NOTIFICATION
// ============================================================================

// In sendOrderEmail() function, update the email to include eSewa status:

const esewaInfo = order.paymentVerification ? 
    `<tr>
      <td style="padding:10px 0;color:#888;">Payment Status</td>
      <td style="padding:10px 0;color:#fff;"><b>${
        order.paymentVerification.verified ? '✅ Verified' : '⏳ Pending Verification'
      }</b></td>
    </tr>
    <tr>
      <td style="padding:10px 0;color:#888;">Transaction ID</td>
      <td style="padding:10px 0;color:#fff;"><code>${order.transactionId || '-'}</code></td>
    </tr>`
    : '';

// Add to HTML body of email:
// ${esewaInfo}

// ============================================================================
// TESTING CODE - Save as test-payment.js and run: node test-payment.js
// ============================================================================

/*
const { verifyESewaPayment, validatePhoneNumber, validateAmount } = require('./validators');

async function testPaymentFlow() {
    console.log('🧪 Testing Payment Flow\n');
    
    // Test 1: Phone validation
    console.log('1️⃣  Testing Phone Validation:');
    console.log('   Nepal 📱:', validatePhoneNumber('9779708838261'));
    console.log('   India 📱:', validatePhoneNumber('919876543210'));
    console.log('   Invalid:', validatePhoneNumber('123')); // Should fail
    
    // Test 2: Amount validation
    console.log('\n2️⃣  Testing Amount Validation:');
    console.log('   ₹100:', validateAmount(100));
    console.log('   ₹1000:', validateAmount(1000));
    console.log('   ₹999999:', validateAmount(999999)); // Should fail
    
    // Test 3: eSewa verification (without real API)
    console.log('\n3️⃣  Testing eSewa Verification:');
    const result = await verifyESewaPayment('TXN123456789', 1000, '9779708838261');
    console.log('   Result:', result);
}

testPaymentFlow();
*/
