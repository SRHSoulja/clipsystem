FROM php:8.2-cli

WORKDIR /var/www/html

# Copy all files
COPY . .

# Create cache directory with proper permissions
RUN mkdir -p /var/www/html/cache && \
    chmod -R 777 /var/www/html/cache

# Railway provides PORT dynamically - use shell form for variable expansion
CMD php -S 0.0.0.0:${PORT:-8080} -t /var/www/html
