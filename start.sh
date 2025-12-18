#!/bin/bash
# Start the Node.js bot in background
npm install --silent 2>/dev/null
node bot.js &

# Start PHP/nginx (Railway's default)
node /assets/scripts/prestart.mjs /assets/nginx.template.conf /nginx.conf
php-fpm -y /assets/php-fpm.conf &
nginx -c /nginx.conf
