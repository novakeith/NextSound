#!/bin/bash
# docker entrypoint - will create /data/ and /uploads/ directories and set the owner to the web user

# Ensure the directories exist
mkdir -p /var/www/html/data /var/www/html/uploads

# Set permissions for the web server user
chown -R www-data:www-data /var/www/html/data /var/www/html/uploads

# Execute the main command (Apache)
apache2-foreground