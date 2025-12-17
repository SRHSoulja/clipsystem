FROM php:8.2-apache

# Fix MPM conflict - remove event MPM config, ensure only prefork is enabled
RUN rm -f /etc/apache2/mods-enabled/mpm_event.* && \
    ln -sf /etc/apache2/mods-available/mpm_prefork.conf /etc/apache2/mods-enabled/mpm_prefork.conf && \
    ln -sf /etc/apache2/mods-available/mpm_prefork.load /etc/apache2/mods-enabled/mpm_prefork.load

# Enable required Apache modules
RUN a2enmod rewrite headers

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
