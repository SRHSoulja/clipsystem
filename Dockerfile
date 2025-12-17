FROM php:8.2-apache

# Disable conflicting MPM modules and enable prefork (default for php-apache)
RUN a2dismod mpm_event 2>/dev/null || true && \
    a2enmod mpm_prefork 2>/dev/null || true

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Set working directory
WORKDIR /var/www/html

# Copy all files
COPY . .

# Create cache directory with proper permissions
RUN mkdir -p /var/www/html/cache && \
    chown -R www-data:www-data /var/www/html/cache && \
    chmod -R 755 /var/www/html/cache

# Set proper permissions for all files
RUN chown -R www-data:www-data /var/www/html

# Apache config for CORS and caching headers
RUN echo '<Directory /var/www/html>\n\
    Options -Indexes +FollowSymLinks\n\
    AllowOverride All\n\
    Require all granted\n\
    Header set Access-Control-Allow-Origin "*"\n\
    Header set Access-Control-Allow-Methods "GET, POST, OPTIONS"\n\
    Header set Access-Control-Allow-Headers "Content-Type"\n\
</Directory>' > /etc/apache2/conf-available/cors.conf && \
    a2enconf cors && \
    a2enmod headers

# Expose port 80
EXPOSE 80

CMD ["apache2-foreground"]
