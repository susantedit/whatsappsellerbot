/**
 * WhatsBot Validators
 * Phone number and payment validation functions
 */

// Validate Nepali/Indian phone numbers
function validatePhoneNumber(phone) {
    const cleanPhone = String(phone).replace(/[^0-9]/g, '');
    
    // Nepali format: 10 digits, starts with 98 or 97
    const isNepaliPhone = /^(977)?98\d{8}$/.test(cleanPhone) || /^(977)?97\d{8}$/.test(cleanPhone);
    
    // Indian format: 10 digits, starts with 6-9
    const isIndianPhone = /^91[6-9]\d{9}$/.test(cleanPhone) || /^[6-9]\d{9}$/.test(cleanPhone);
    
    if (!isNepaliPhone && !isIndianPhone) {
        return { 
            valid: false, 
            message: '❌ Invalid phone number. Use format:\n• Nepal: 9779708838261 or 9849123456\n• India: 919876543210 or 9876543210' 
        };
    }
    
    // Normalize to with country code
    let normalized = cleanPhone;
    if (normalized.length === 10) {
        normalized = isNepaliPhone ? '977' + normalized : '91' + normalized;
    }
    
    return { 
        valid: true, 
        phone: normalized,
        countryCode: normalized.substring(0, normalized.length - 10)
    };
}

// Validate UID (Game User ID)
function validateUID(uid) {
    // Most game UIDs are 8-12 digit numbers
    const cleanUID = String(uid).replace(/[^0-9]/g, '');
    if (cleanUID.length < 5 || cleanUID.length > 15) {
        return { 
            valid: false, 
            message: '❌ UID should be 5-15 digits. Example: 12345678' 
        };
    }
    return { valid: true, uid: cleanUID };
}

// Validate transaction ID or payment proof
function validateTransactionID(txId) {
    if (!txId || txId.trim().length < 3) {
        return { 
            valid: false, 
            message: '❌ Please provide a valid transaction ID or payment screenshot' 
        };
    }
    
    const cleaned = txId.trim().toUpperCase();
    // eSewa transaction format: typically 12-20 alphanumeric
    // Khalti, IME Pay etc also have similar formats
    const isValidFormat = /^[A-Z0-9]{6,20}$/.test(cleaned);
    
    if (!isValidFormat && cleaned.length < 8) {
        return { 
            valid: false, 
            message: '❌ Transaction ID format invalid. Should be 6+ characters' 
        };
    }
    
    return { valid: true, transactionId: cleaned };
}

// Check if payment amount is valid (in Rupees)
function validateAmount(amount, minAmount = 50, maxAmount = 50000) {
    const numAmount = parseFloat(amount);
    
    if (isNaN(numAmount) || numAmount <= 0) {
        return { valid: false, message: '❌ Invalid amount' };
    }
    
    if (numAmount < minAmount) {
        return { valid: false, message: `❌ Minimum amount is ₹${minAmount}` };
    }
    
    if (numAmount > maxAmount) {
        return { valid: false, message: `❌ Maximum amount is ₹${maxAmount}. Contact support for bulk orders.` };
    }
    
    return { valid: true, amount: numAmount };
}

// eSewa payment verification removed - using manual verification only

// Validate complete payment info
function validatePaymentInfo(phone, uid, amount) {
    const phoneValidation = validatePhoneNumber(phone);
    if (!phoneValidation.valid) return phoneValidation;
    
    if (uid) {
        const uidValidation = validateUID(uid);
        if (!uidValidation.valid) return uidValidation;
    }
    
    const amountValidation = validateAmount(amount);
    if (!amountValidation.valid) return amountValidation;
    
    return {
        valid: true,
        phone: phoneValidation.phone,
        uid: uid ? uid : null,
        amount: amountValidation.amount
    };
}

module.exports = {
    validatePhoneNumber,
    validateUID,
    validateTransactionID,
    validateAmount,
    validatePaymentInfo,
};
