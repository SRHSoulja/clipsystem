FROM php:8.2-apache

# Fix MPM conflict - remove ALL MPM configs then enable only prefork
RUN rm -f /etc/apache2/mods-enabled/mpm_*.conf /etc/apache2/mods-enabled/mpm_*.load && \
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
