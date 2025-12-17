FROM php:8.2-apache

# Fix MPM conflict - disable all MPMs first, then enable only prefork
RUN a2dismod mpm_event mpm_worker mpm_prefork || true && \
    a2enmod mpm_prefork rewrite headers

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

# Expose port 80
EXPOSE 80

CMD ["apache2-foreground"]
