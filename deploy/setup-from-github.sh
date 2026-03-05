#!/bin/bash
set -e

# One-command setup for Oracle Cloud Free Tier
# Usage: curl -sSL https://raw.githubusercontent.com/YOUR_USER/sticker-bot/main/deploy/setup-from-github.sh | bash -s YOUR_USER/sticker-bot

if [ -z "$1" ]; then
    echo "Usage: $0 <github-username/repo-name>"
    echo "Example: $0 myuser/sticker-bot"
    exit 1
fi

GITHUB_REPO=$1

echo "=== Setting up Sticker Bot from GitHub ==="

# Install Docker if needed
if ! command -v docker &> /dev/null; then
    echo "Installing Docker..."
    sudo apt-get update
    sudo apt-get install -y ca-certificates curl
    sudo install -m 0755 -d /etc/apt/keyrings
    sudo curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
    sudo chmod a+r /etc/apt/keyrings/docker.asc
    echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo "$VERSION_CODENAME") stable" | sudo tee /etc/apt/sources.list.d/docker.list > /dev/null
    sudo apt-get update
    sudo apt-get install -y docker-ce docker-ce-cli containerd.io docker-compose-plugin
    sudo usermod -aG docker $USER
    newgrp docker
fi

# Configure firewall
echo "Configuring firewall..."
sudo iptables -I INPUT 6 -m state --state NEW -p tcp --dport 80 -j ACCEPT 2>/dev/null || true
sudo iptables -I INPUT 6 -m state --state NEW -p tcp --dport 443 -j ACCEPT 2>/dev/null || true
sudo apt-get install -y iptables-persistent 2>/dev/null || true
sudo netfilter-persistent save 2>/dev/null || true

# Setup app directory
sudo mkdir -p /opt/sticker-bot
sudo chown $USER:$USER /opt/sticker-bot
cd /opt/sticker-bot

# Clone repository
if [ -d ".git" ]; then
    echo "Updating existing repository..."
    git pull
else
    echo "Cloning repository..."
    git clone --depth 1 https://github.com/${GITHUB_REPO}.git .
fi

# Generate random secret
APP_SECRET=$(openssl rand -hex 32)

# Create .env.local (secrets file - not in git)
cat > .env.local << EOF
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=${APP_SECRET}

TELEGRAM_BOT_TOKEN=REPLACE_WITH_YOUR_BOT_TOKEN
TELEGRAM_BOT_USERNAME=REPLACE_WITH_BOT_USERNAME

OPENAI_API_KEY=REPLACE_WITH_YOUR_OPENAI_KEY

# Database (MySQL)
DATABASE_URL="mysql://sticker_bot:stickerpass@mysql:3306/sticker_bot?serverVersion=8.0&charset=utf8mb4"

# MySQL Configuration
MYSQL_ROOT_PASSWORD=rootpassword
MYSQL_DATABASE=sticker_bot
MYSQL_USER=sticker_bot
MYSQL_PASSWORD=stickerpass
EOF

# Create repo config
echo "GITHUB_REPO=${GITHUB_REPO}" > .env.repo

PUBLIC_IP=$(curl -s ifconfig.me)

echo ""
echo "=== Setup complete! ==="
echo ""
echo "1. Edit your secrets:"
echo "   nano /opt/sticker-bot/.env.local"
echo ""
echo "2. Start the application:"
echo "   cd /opt/sticker-bot"
echo "   docker compose -f docker-compose.prod.yml up -d"
echo ""
echo "3. Set up SSL (optional, requires domain):"
echo "   ./deploy/setup-ssl.sh your-domain.com"
echo ""
echo "4. Set Telegram webhook:"
echo "   ./deploy/set-webhook.sh your-domain.com YOUR_BOT_TOKEN"
echo "   # Or using IP (HTTP only): ./deploy/set-webhook.sh ${PUBLIC_IP}"
echo ""
echo "For GitHub Actions auto-deploy, add these repository secrets:"
echo "  ORACLE_HOST: ${PUBLIC_IP}"
echo "  ORACLE_SSH_KEY: <your private SSH key>"
