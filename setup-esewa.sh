#!/bin/bash
# Quick Setup Script for eSewa Integration

echo "🚀 eSewa Integration Setup"
echo "=========================="

# Check if .env exists
if [ ! -f .env ]; then
    echo "❌ .env file not found!"
    echo "📋 Creating .env from .env.example..."
    cp .env.example .env
    echo "✅ Created .env - Now edit it with your credentials"
else
    echo "✅ .env file exists"
fi

# Check for required packages
echo ""
echo "📦 Checking dependencies..."
npm list crypto > /dev/null 2>&1 || echo "ℹ️  crypto is built-in (no install needed)"

# Display credentials template
echo ""
echo "📝 Add these to your .env file:"
echo "================================"
cat << 'EOF'

# eSewa Payment Gateway (Get from https://esewa.com.np/developers)
ESEWA_MERCHANT_CODE=EPAYTEST
ESEWA_MERCHANT_SECRET=your_secret_key_here
ESEWA_API_URL=https://uat.esewa.com.np
ESEWA_ENVIRONMENT=test

EOF

echo ""
echo "✅ Setup guide saved to: ESEWA_INTEGRATION.md"
echo "📖 Read it for complete integration instructions"
echo ""
echo "⚙️  Next Steps:"
echo "1. Edit .env with your eSewa credentials"
echo "2. Run: npm start"
echo "3. Test payment flow in WhatsApp"
echo ""
