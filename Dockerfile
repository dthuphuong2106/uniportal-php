FROM php:8.3-apache

# PHP extension for MySQL + Apache rewrite
RUN docker-php-ext-install pdo_mysql && a2enmod rewrite

# Render sends traffic to port 10000
RUN sed -i 's/Listen 80/Listen 10000/' /etc/apache2/ports.conf \
 && sed -i 's/<VirtualHost \*:80>/<VirtualHost *:10000>/' /etc/apache2/sites-available/000-default.conf

# The code links to /UniPortal/public/..., so keep that folder name
COPY . /var/www/html/UniPortal/

# Redirect the site root to the login page
RUN echo '<?php header("Location: /UniPortal/public/index.php"); exit;' > /var/www/html/index.php \
 && mkdir -p /var/www/html/UniPortal/storage/uploads \
 && chown -R www-data:www-data /var/www/html/UniPortal/storage

EXPOSE 10000
