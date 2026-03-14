# Stage 1: Install Composer dependencies
FROM php:8.4-cli-alpine AS vendor

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --ignore-platform-reqs

# Stage 2: Build frontend assets
FROM node:22-alpine AS assets

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY . .
COPY --from=vendor /app/vendor vendor

RUN npm run build

# Stage 3: PHP application
FROM php:8.4-fpm AS app

# Install system dependencies
RUN apt-get update && apt-get install -y --no-install-recommends \
    curl \
    libzip-dev \
    libpng-dev \
    libicu-dev \
    libonig-dev \
    unzip \
    && docker-php-ext-install \
        pdo_mysql \
        zip \
        gd \
        intl \
        bcmath \
        pcntl \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

# Ensure PHP-FPM listens on all interfaces (not just localhost)
RUN printf '[global]\ndaemonize = no\n\n[www]\nlisten = 0.0.0.0:9000\n' > /usr/local/etc/php-fpm.d/zz-docker.conf

# Copy application source
COPY . .

# Remove hot file if it was copied from host (forces production manifest)
RUN rm -f public/hot

# Copy vendor from stage 1 and built assets from stage 2
COPY --from=vendor /app/vendor vendor
COPY --from=assets /app/public/build public/build

# Install Composer for autoload optimization
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
RUN composer dump-autoload --optimize

# Set permissions
RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# Pre-cache views only (config and routes are cached at runtime via
# entrypoint so they pick up .env values and produce consistent hashes)
RUN php artisan view:cache

# Copy entrypoint
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 9000

ENTRYPOINT ["entrypoint.sh"]

# Stage 4: Nginx with built assets
FROM nginx:alpine AS nginx

COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=app /var/www/html/public /var/www/html/public
