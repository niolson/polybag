# Stage 1: Install Composer dependencies
# Pin digests to prevent supply chain attacks — update with `docker manifest inspect <image>`
FROM php:8.4-cli-alpine@sha256:35d2128457116c6842350c3aad3ecd123755f69e75efb047acfd6275625496e4 AS vendor

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --ignore-platform-reqs

# Stage 2: Build frontend assets
FROM node:22-alpine@sha256:4d64b49e6c891c8fc821007cb1cdc6c0db7773110ac2c34bf2e6960adef62ed3 AS assets

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY . .
COPY --from=vendor /app/vendor vendor

RUN npm run build

# Stage 3: PHP application
FROM php:8.4-fpm@sha256:d31cf114d43fee26655a76b0d09dddeaab3f09391eb4edf3f35e1f1c919e1b28 AS app

# Install system dependencies
RUN apt-get update && apt-get upgrade -y && apt-get install -y --no-install-recommends \
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
FROM nginx:alpine@sha256:645eda1c2477aaa9b879f73909b9222c6f19798dd45be6706268d82a661c6e6d AS nginx

COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=app /var/www/html/public /var/www/html/public
