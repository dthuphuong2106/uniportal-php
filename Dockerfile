FROM php:8.3-apache

# PHP extension for MySQL + Apache rewrite
RUN docker-php-ext-install pdo_mysql && a2enmod rewrite

# Render sends traffic to port 10000
RUN sed -i 's/Listen 80/Listen 10000/' /etc/apache2/ports.conf \
 && sed -i 's/<VirtualHost \*:80>/<VirtualHost *:10000>/' /etc/apache2/sites-available/000-default.conf

# Honour the project's .htaccess files (they block direct access to
# storage/uploads and to .env)
RUN sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# The app allows 20 MB per file / 30 MB per upload; PHP defaults are only 2 MB / 8 MB
RUN { \
      echo 'upload_max_filesize = 20M'; \
      echo 'post_max_size = 32M'; \
      echo 'max_file_uploads = 20'; \
      echo 'memory_limit = 256M'; \
    } > /usr/local/etc/php/conf.d/uniportal-uploads.ini

# The code links to /UniPortal/public/..., so keep that folder name
COPY . /var/www/html/UniPortal/

# Redirect the site root to the login page
RUN echo '<?php header("Location: /UniPortal/public/index.php"); exit;' > /var/www/html/index.php \
 && mkdir -p /var/www/html/UniPortal/storage/uploads \
 && chown -R www-data:www-data /var/www/html/UniPortal/storage

EXPOSE 10000
