#!/bin/sh
# Runs each time the server starts on Render (also after it wakes up on the free plan).
set -e
cd /var/www/html

# Render tells the app which port to use (10000 by default)
PORT="${PORT:-10000}"
sed -i "s/Listen 80$/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

# the settings come from Render's Environment page: cache them for speed
php artisan config:cache
php artisan route:cache
php artisan storage:link >/dev/null 2>&1 || true

# new database changes (only what hasn't run yet); set RUN_MIGRATIONS=false to skip
if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
  php artisan migrate --force
fi

chown -R www-data:www-data storage bootstrap/cache
exec apache2-foreground