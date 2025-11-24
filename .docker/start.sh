#!/bin/bash

# Ensure necessary directories exist
mkdir -p /var/www/storage/framework/{sessions,cache,views}
mkdir -p /var/www/storage/logs
mkdir -p /var/www/bootstrap/cache

# Set proper permissions
chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache
chmod -R ug+rwx /var/www/storage /var/www/bootstrap/cache

# Laravel setup
php /var/www/artisan cache:clear
php /var/www/artisan config:clear
php /var/www/artisan view:clear
php /var/www/artisan config:cache
php /var/www/artisan storage:link

# Start PHP-FPM and Nginx
service php8.4-fpm start
nginx -g "daemon off;"
