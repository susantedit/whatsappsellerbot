# eSewa Integration Guide for WhatsBot

## Step 1: Get Your Merchant Credentials from eSewa

### A. Register as Merchant (if not already)
1. Go to **https://esewa.com.np** (Nepal's payment gateway)
2. Click on **Merchant Services** or **API Docs**
3. Sign up for a merchant account
4. Complete KYC verification

### B. Get Your Merchant Code & Secret
Once registered, you'll receive:
- **Merchant Code**: Usually like `EPAYTEST` (test) or `SKWP1234` (live)
- **Merchant Secret**: A long string key for authentication
- **API URL**: 
  - **Test**: `https://uat.esewa.com.np`
  - **Live**: `https://esewa.com.np`

---

## Step 2: Add eSewa Keys to `.env`

Create or update your `.env` file:

```env
# eSewa Payment Gateway
ESEWA_MERCHANT_CODE=EPAYTEST
ESEWA_MERCHANT_SECRET=your_secret_key_here
ESEWA_API_URL=https://uat.esewa.com.np
ESEWA_ENVIRONMENT=test
```

**Example for Production:**
```env
ESEWA_MERCHANT_CODE=SKWP1234567890
ESEWA_MERCHANT_SECRET=abcd1234efgh5678ijkl9012mnop3456
ESEWA_API_URL=https://esewa.com.np
ESEWA_ENVIRONMENT=production
```

⚠️ **Never commit `.env` to Git!** It's already in `.gitignore`

---

## Step 3: Update `validators.js` to Use eSewa API

Replace the stub function with actual implementation:

```javascript
// Add this import at the top of validators.js
const crypto = require('crypto');

// Enhanced eSewa verification with actual API call
async function verifyESewaPayment(transactionId, amount, phoneNumber) {
    try {
        const merchantCode = process.env.ESEWA_MERCHANT_CODE;
        const merchantSecret = process.env.ESEWA_MERCHANT_SECRET;
        const apiUrl = process.env.ESEWA_API_URL;
        111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111
        if (!merchantCode || !merchantSecret) {
            console.warn('⚠️ eSewa credentials missing - payment verification skipped');
            return {
                status: 'pending',
                verified: false,
                transactionId,
                amount,
                phoneNumber,
                message: 'Credentials not configured - manual verification required'
            };
        }

        // eSewa verification endpoint
        const verifyUrl = `${apiUrl}/api/verify`;
        
        // Create verification request body
        const verificationPayload = {
            transactionId,
            merchantCode,
            merchantSecret,
            amount: Math.round(amount * 100) / 100, // Ensure 2 decimal places
            timestamp: new Date().toISOString()
        };

        // Call eSewa verification API
        const response = await fetch(verifyUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': `Bearer ${merchantCode}`
            },
            body: JSON.stringify(verificationPayload),
            timeout: 10000 // 10 second timeout
        });

        const data = await response.json();

        // eSewa typical response format
        if (data.status === 'COMPLETE' || data.status === '000') {
            return {
                status: 'success',
                verified: true,
                transactionId: data.transactionId || transactionId,
                amount: data.amount || amount,
                phoneNumber,
                productCode: data.productCode,
                verifiedAt: new Date().toISOString(),
                message: '✅ Payment verified successfully'
            };
        } else if (data.status === 'PENDING') {
            return {
                status: 'pending',
                verified: false,
                transactionId,
                amount,
                phoneNumber,
                verifiedAt: new Date().toISOString(),
                message: '⏳ Payment verification in progress'
            };
        } else {
            return {
                status: 'failed',
                verified: false,
                transactionId,
                amount,
                phoneNumber,
                error: data.message || 'Payment verification failed',
                errorCode: data.status
            };
        }
    } catch (error) {
        console.error('eSewa verification error:', error.message);
        return {
            status: 'error',
            verified: false,
            transactionId,
            amount,
            phoneNumber,
            error: error.message,
            message: '❌ Payment verification error - manual review required'
        };
    }
}
```

---

## Step 4: Update `index.js` to Use eSewa Verification

Add this to your bot after a payment is received:

```javascript
// Add at the top of index.js
const { verifyESewaPayment, validatePhoneNumber } = require('./validators');

// In SEND_PAYMENT step, after receiving payment screenshot:
if (st.step === 'SEND_PAYMENT') {
    const hasImage = !!(msg.message?.imageMessage);
    const proof = hasImage ? '[Screenshot received]' : rawText;
    const od = st.orderData;
    
    // ENHANCED: Try to extract and verify transaction ID
    let transactionId = rawText;
    let verificationResult = null;
    
    if (!hasImage) {
        // If text was provided, try to verify with eSewa
        transactionId = rawText.match(/[A-Z0-9]{8,20}/)?.[0] || rawText;
        verificationResult = await verifyESewaPayment(transactionId, od.price, od.phone);
    }
    
    // Save order with verification status
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
        paymentVerification: verificationResult || { status: 'pending', verified: false },
        waNumber: waNum,
        status: verificationResult?.verified ? 'Processing' : 'Pending',
        timestamp: new Date().toISOString()
    };
    
    await fbPost('orders', orderData);
    await sendOrderEmail({ ...od, waNumber: waNum, timestamp: new Date().toISOString() });
    
    // Notify based on verification
    if (verificationResult?.verified) {
        await send(`✅ Payment verified! Your order is being processed now 🚀`);
    } else if (verificationResult?.status === 'pending') {
        await send(`⏳ Payment received! Verification in progress (5-15 minutes) 🔄`);
    } else {
        await send(`Payment screenshot received! Manual verification by Susant within 1 hour 🙏`);
    }
    
    userStates[sender] = { step: 'RETURNING', name: od.name };
    return;
}
```

---

## Step 5: Full Payment Flow with eSewa

Here's the complete flow with validation:

```javascript
// In ASK_PHONE step, add validation:
if (st.step === 'ASK_PHONE') {
    const phoneValidation = validatePhoneNumber(rawText);
    if (!phoneValidation.valid) {
        await send(`❌ ${phoneValidation.message}`);
        return;
    }
    
    // Validate amount before sending payment request
    const { validateAmount } = require('./validators');
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
    
    const paymentMsg = `💳 Payment Details\n\nAmount: ₹${od.price}\n` +
        `${upi ? `UPI: ${upi}\n` : ''}` +
        `\n💬 *eSewa Integration:*\n` +
        `After paying via eSewa, send the transaction ID or screenshot here.\n` +
        `\n✏️ *Important - Payment Remark:*\n${od.name} - ${phoneValidation.phone}`;
    
    // Send QR code...
    if (qrUrl) {
        await sock.sendPresenceUpdate('composing', sender);
        await delay(800);
        await sock.sendMessage(sender, { image: { url: qrUrl }, caption: paymentMsg });
    }
    
    return;
}
```

---

## Step 6: Firebase Rules for Orders (Security)

Update `firebase.rules.json`:

```json
{
  "rules": {
    "orders": {
      "$orderId": {
        ".read": "root.child('users').child(auth.uid).exists() || auth.uid == root.child('orders').child($orderId).child('ownerUid').val()",
        ".write": "root.child('users').child(auth.uid).exists()",
        "paymentVerification": {
          ".read": true,
          ".write": "root.child('admins').child(auth.uid).exists()"
        },
        "transactionId": {
          ".read": "auth.uid != null",
          ".write": "newData.val() != null"
        }
      }
    }
  }
}
```

---

## Step 7: Test the Integration

### A. Test in Development Mode

1. **Set test credentials in `.env`:**
```env
ESEWA_MERCHANT_CODE=EPAYTEST
ESEWA_MERCHANT_SECRET=test_secret_12345
ESEWA_API_URL=https://uat.esewa.com.np
```

2. **Restart the bot:**
```bash
npm start
```

3. **Simulate payment:**
   - Send `6` for Ask Question
   - Ask "How do I pay with eSewa?"
   - AI will respond with payment options
   - Bot should validate phone numbers and amounts

### B. Test Validation Functions

Create a test file `test-esewa.js`:

```javascript
const validators = require('./validators');

async function test() {
    console.log('Testing Phone Validation...');
    console.log(validators.validatePhoneNumber('9779708838261'));
    console.log(validators.validatePhoneNumber('919876543210'));
    console.log(validators.validatePhoneNumber('123')); // Should fail
    
    console.log('\nTesting Amount Validation...');
    console.log(validators.validateAmount(100)); // Valid
    console.log(validators.validateAmount(999999)); // Should fail
    
    console.log('\nTesting eSewa Verification...');
    const result = await validators.verifyESewaPayment('TXN123456', 1000, '9779708838261');
    console.log(result);
}

test();
```

Run it:
```bash
node test-esewa.js
```

### C. Test with Real eSewa Account

1. Log into your eSewa merchant dashboard
2. Use test transaction IDs from eSewa
3. Send them via WhatsApp to test verification
4. Check logs: `npm start` output will show verification results

---

## Troubleshooting

| Error | Solution |
|-------|----------|
| `ESEWA_MERCHANT_CODE is undefined` | Add credentials to `.env`, restart bot |
| `Connection refused` | Check API_URL is correct (test vs live) |
| `Transaction not found` | Use actual transaction ID from eSewa |
| `Invalid merchant` | Verify merchant code matches eSewa account |
| `Timeout` | eSewa API slow - increase timeout to 15000ms |

---

## Production Checklist

- [ ] Switch to live credentials (`esewa.com.np` not `uat.esewa.com.np`)
- [ ] Test with real transactions
- [ ] Add logging for all payment verifications
- [ ] Set up Firebase backup for payment data
- [ ] Add admin alerts for failed verifications
- [ ] Enable HTTPS only for payment pages
- [ ] Document refund process
- [ ] Set up payment reconciliation cron job

---

## API Response Format Reference

### Successful Payment
```json
{
  "status": "COMPLETE",
  "transactionId": "TXN123456789",
  "amount": 1000,
  "productCode": "GAMING",
  "verifiedAt": "2026-04-17T10:30:00Z"
}
```

### Failed Payment
```json
{
  "status": "FAILED",
  "message": "Invalid transaction",
  "errorCode": "002"
}
```

### Pending Payment
```json
{
  "status": "PENDING",
  "transactionId": "TXN123456789",
  "message": "Transaction is still being processed"
}
```

---

## Support & Resources

- eSewa Developer Docs: https://esewa.com.np/developers/
- eSewa Test Account: Available from dashboard
- WhatsBot Issues: Create GitHub issue
- eSewa Support: support@esewa.com.np
