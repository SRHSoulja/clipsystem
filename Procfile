web: node /assets/scripts/prestart.mjs /assets/nginx.template.conf /nginx.conf && (node bot.js & php-fpm -y /assets/php-fpm.conf & nginx -c /nginx.conf)
