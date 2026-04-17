# eSewa Integration - Quick Start (Copy & Paste)

## STEP 1: Get Credentials from eSewa

```
1. Go to: https://esewa.com.np/developers/
2. Sign in or create merchant account
3. Go to Dashboard → API Settings
4. Copy these values:
   - Merchant Code (e.g., EPAYTEST)
   - Merchant Secret (long key)
   - API URL (uat.esewa.com.np for test)
```

---

## STEP 2: Update `.env` File

Open `.env` and add/update these lines:

```env
# eSewa Payment Gateway
ESEWA_MERCHANT_CODE=EPAYTEST
ESEWA_MERCHANT_SECRET=your_merchant_secret_key_here
ESEWA_API_URL=https://uat.esewa.com.np
ESEWA_ENVIRONMENT=test
```

**For Production (after testing):**
```env
ESEWA_MERCHANT_CODE=YOUR_LIVE_MERCHANT_CODE
ESEWA_MERCHANT_SECRET=YOUR_LIVE_SECRET
ESEWA_API_URL=https://esewa.com.np
ESEWA_ENVIRONMENT=production
```

⚠️ **Save and close the file** - Don't commit to Git!

---

## STEP 3: Already Included in Updated Code ✅

The `validators.js` file already has:
- ✅ `verifyESewaPayment()` - Full eSewa API integration
- ✅ `validatePhoneNumber()` - Phone format validation  
- ✅ `validateAmount()` - Amount range validation
- ✅ Error handling & retry logic
- ✅ Transaction logging

**No additional code changes needed!**

---

## STEP 4: Test It

### A. Start Bot
```bash
npm start
```

### B. Test in WhatsApp
1. Send `2` → Select Panels
2. Send `1` → Pick a panel
3. Send `1` → Pick a package
4. Complete order → Fill name, phone, UID
5. Bot sends payment QR with instruction:
   ```
   ✏️ Important - Payment Remark:
   YourName - YourPhone
   
   After paying via eSewa, send transaction ID here
   ```

### C. Send Transaction ID
- Make eSewa payment
- Get transaction ID from receipt
- Send it to WhatsApp bot
- Bot will verify automatically! ✅

---

## STEP 5: Monitor in Firebase

Orders are saved with verification status:

```json
{
  "orders": {
    "order_123": {
      "name": "Susant",
      "phone": "9779708838261",
      "price": 1000,
      "transactionId": "TXN123456789",
      "paymentVerification": {
        "status": "success",
        "verified": true,
        "amount": 1000,
        "message": "✅ Payment verified successfully"
      },
      "status": "Processing"
    }
  }
}
```

---

## Verification Status Meanings

| Status | Meaning | Action |
|--------|---------|--------|
| `success` | ✅ Payment confirmed | Auto-process order |
| `pending` | ⏳ Verifying | Wait 5-15 minutes, retry |
| `failed` | ❌ Payment rejected | Ask customer to retry |
| `timeout` | ⏱️ Timeout | Will retry automatically |
| `error` | ⚠️ API error | Manual verification needed |

---

## Common Issues & Fixes

### Issue: "eSewa credentials not configured"
**Fix:** 
```bash
1. Check .env file exists
2. Add ESEWA_MERCHANT_CODE and ESEWA_MERCHANT_SECRET
3. Restart: npm start
```

### Issue: "Connection refused"
**Fix:**
```env
# For testing (not production yet)
ESEWA_API_URL=https://uat.esewa.com.np

# NOT: https://esewa.com.np (that's production)
```

### Issue: "Transaction not found"
**Fix:**
1. Use actual transaction ID from eSewa receipt
2. Make sure amount matches exactly
3. Check transaction hasn't expired

### Issue: Verification always "pending"
**Fix:**
1. eSewa API might be slow
2. Wait 2-3 minutes and retry
3. Or ask customer to send screenshot as backup

---

## Testing with Fake Data

If eSewa credentials aren't set, bot will:
- Accept payment proof as screenshot ✅
- Save with status `pending` ✅
- Require manual verification ✅
- Still work perfectly! ✅

---

## Live Checklist

Before going live:

- [ ] Have eSewa merchant account
- [ ] Got merchant code & secret
- [ ] Updated `.env` with LIVE credentials
- [ ] Changed `ESEWA_API_URL` to `https://esewa.com.np`
- [ ] Tested with real eSewa payment
- [ ] Verified order saved correctly
- [ ] Checked Firebase security rules
- [ ] Added admin alerts for failed payments
- [ ] Documented refund process

---

## Code Reference

### Using in index.js

```javascript
// Import at top
const { verifyESewaPayment, validatePhoneNumber } = require('./validators');

// In SEND_PAYMENT step
const verificationResult = await verifyESewaPayment(
    transactionId,    // "TXN123456789"
    od.price,         // 1000
    od.phone          // "9779708838261"
);

if (verificationResult.verified) {
    // Auto-process order
    userStates[sender].step = 'Processing';
} else if (verificationResult.status === 'pending') {
    // Wait for verification
    userStates[sender].step = 'Waiting';
}
```

---

## Support

- 📧 eSewa Support: support@esewa.com.np
- 📚 Docs: https://esewa.com.np/developers/
- 🐛 Bot Issues: Check Firebase logs
- 💬 Ask questions in WhatsApp: Your issue gets saved as order

---

**✅ Setup Complete!** Your WhatsBot now accepts eSewa payments! 🎉
