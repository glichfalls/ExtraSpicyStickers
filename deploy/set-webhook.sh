#!/bin/bash

# Set Telegram Webhook using Symfony command
# Usage: ./set-webhook.sh [url-or-hostname]

cd /opt/sticker-bot

if [ -n "$1" ]; then
    docker compose -f docker-compose.prod.yml exec -T app php bin/console telegram:webhook:set "$1"
else
    docker compose -f docker-compose.prod.yml exec -T app php bin/console telegram:webhook:set
fi