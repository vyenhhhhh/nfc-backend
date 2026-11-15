# The backend (Laravel) for Render: PHP 8.3 + Apache, document root public/.
# Render builds this file on every push to GitHub; docker/start.sh runs when the server starts.
FROM php:8.3-apache

# PHP extensions the system needs (PostgreSQL/Supabase, zip, intl, bcmath) + OPcache for speed
RUN apt-get update \
 && apt-get install -y --no-install-recommends libpq-dev libzip-dev libicu-dev unzip git \
 && docker-php-ext-install pdo_pgsql pgsql zip intl bcmath opcache \
 && a2enmod rewrite headers \
 && rm -rf /var/lib/apt/lists/*

# OPcache: the compiled PHP stays in memory (the code never changes while the server runs)
RUN { \
      echo 'opcache.enable=1'; \
      echo 'opcache.memory_consumption=128'; \
      echo 'opcache.max_accelerated_files=20000'; \
      echo 'opcache.validate_timestamps=0'; \
      echo 'upload_max_filesize=20M'; \
      echo 'post_max_size=25M'; \
      echo 'memory_limit=256M'; \
      echo 'expose_php=Off'; \
    } > /usr/local/etc/php/conf.d/mytrack.ini

# Apache serves public/ and reads its .htaccess
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
 && sed -ri 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
 && sed -ri 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf \
 && echo 'ServerName localhost' >> /etc/apache2/apache2.conf \
 && echo 'ServerTokens Prod' >> /etc/apache2/apache2.conf

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html

# the libraries first (cached between builds when composer.lock doesn't change)
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress

# then the code
COPY . .
RUN composer dump-autoload --optimize --no-dev \
 && php artisan package:discover --ansi \
 && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache

COPY docker/start.sh /usr/local/bin/start.sh
RUN sed -i 's/\r$//' /usr/local/bin/start.sh && chmod +x /usr/local/bin/start.sh

EXPOSE 10000
CMD ["/usr/local/bin/start.sh"]