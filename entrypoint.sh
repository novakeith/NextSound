#!/bin/bash
# docker entrypoint - will create /data/ and /uploads/ directories and set the owner to the web user

# Ensure the directories exist
mkdir -p /var/www/html/data /var/www/html/uploads

# Ensure .htaccess lives in /data and /uploads to stop improper access
cp /var/www/html/assets/.htaccess-data /var/www/html/data/.htaccess
cp /var/www/html/assets/.htaccess-uploads /var/www/html/uploads/.htaccess

# Set permissions for the web server user
chown -R www-data:www-data /var/www/html/data /var/www/html/uploads

# Optional upload limits from .env (e.g. 512M) - override the defaults in uploads.ini
limits=/usr/local/etc/php/conf.d/zz-upload-limits.ini
rm -f "$limits"
size='^[0-9]+[KMGkmg]?$'
post="${POST_MAX_SIZE:-$UPLOAD_MAX_FILESIZE}"
if [ -n "$UPLOAD_MAX_FILESIZE" ]; then
	if [[ "$UPLOAD_MAX_FILESIZE" =~ $size ]]; then echo "upload_max_filesize = $UPLOAD_MAX_FILESIZE" >> "$limits"; else echo "ignoring invalid UPLOAD_MAX_FILESIZE: $UPLOAD_MAX_FILESIZE" >&2; fi
fi
if [ -n "$post" ]; then
	if [[ "$post" =~ $size ]]; then echo "post_max_size = $post" >> "$limits"; else echo "ignoring invalid POST_MAX_SIZE: $post" >&2; fi
fi

# Execute the main command (Apache)
apache2-foreground