FROM php:8.2-apache

# dependencies
RUN apt-get update && apt-get install -y \
    sqlite3 \
    libsqlite3-dev \
    ffmpeg \
    && rm -rf /var/lib/apt/lists/*

# Install and configure PHP extensions
RUN docker-php-ext-install pdo_sqlite

# Enable Apache modules (headers = security headers set in .htaccess)
RUN a2enmod rewrite headers

# Don't reveal the Apache/OS version on error pages or in the Server header
RUN sed -i 's/^ServerTokens .*/ServerTokens Prod/; s/^ServerSignature .*/ServerSignature Off/' /etc/apache2/conf-available/security.conf

# Update Apache config for cleaner URLs
RUN sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# Set working directory
WORKDIR /var/www/html

# Copy local code into the container
COPY . .

# Copy ini file to where php expects it
COPY uploads.ini /usr/local/etc/php/php.ini

# Set permissions for the web server user
RUN chown -R www-data:www-data /var/www/html

# Expose port 80
EXPOSE 80

# Copy the entrypoint script
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Use the entrypoint script
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]