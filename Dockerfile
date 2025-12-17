FROM php:8.2-cli

# Install built-in PHP server dependencies
WORKDIR /var/www/html

# Copy all files
COPY . .

# Create cache directory with proper permissions
RUN mkdir -p /var/www/html/cache && \
    chmod -R 777 /var/www/html/cache

# Expose port 80
EXPOSE 80

# Use PHP's built-in server (simpler, no Apache/nginx needed)
CMD ["php", "-S", "0.0.0.0:80", "-t", "/var/www/html"]
