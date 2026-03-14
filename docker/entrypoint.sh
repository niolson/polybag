#!/bin/bash
set -e

# Fix ownership on mounted files (volume mounts bypass Dockerfile permissions)
chown www-data:www-data storage/app/private/qz-private-key.pem 2>/dev/null || true

# Wait for database connection before migrating
count=0
until php artisan db:monitor --databases=mysql > /dev/null 2>&1 || [ $count -ge 30 ]; do
    echo "Waiting for database... ($count/30)"
    sleep 2
    count=$((count + 1))
done

if [ $count -ge 30 ]; then
    echo "WARNING: Database not reachable after 60s, attempting migrate anyway..."
fi

php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache

exec php-fpm
