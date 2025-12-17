FROM php:8.2-apache

# Fix MPM conflict - forcefully remove mpm_event from everywhere
RUN find /etc/apache2 -name '*mpm_event*' -delete && \
    find /etc/apache2 -name '*mpm_worker*' -delete && \
    sed -i '/mpm_event/d' /etc/apache2/apache2.conf || true && \
    sed -i '/mpm_worker/d' /etc/apache2/apache2.conf || true && \
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
