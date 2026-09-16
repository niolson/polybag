# Stage 1: Install Composer dependencies
# Pin digests to prevent supply chain attacks — update with `docker manifest inspect <image>`
FROM php:8.4.25-cli-alpine@sha256:87a90d80f1a13452267243e6744e835c9af040af025cee2e5b99d3e1627ca3f6 AS vendor

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --ignore-platform-reqs

# Stage 2: Build frontend assets
FROM node:22-alpine@sha256:c610fcdfb1d5b4740dd70c284ed3cb16bb857e0f7166196e36a5501df7a3aa32 AS assets

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY . .
COPY --from=vendor /app/vendor vendor

RUN npm run build

# Stage 3: PHP application
FROM php:8.4.25-fpm@sha256:c27f0d1c15968d17f40cd6f2755fbaca234474899583611afab46ae869da0c0e AS app

# Changing this on every build forces apt-get to re-fetch the package index
# instead of reusing a stale cached layer, so OS security patches (e.g. Debian
# security-tracker fixes published after the last build) actually land.
ARG APT_CACHE_BUST=1

# Install system dependencies
RUN apt-get update && apt-get upgrade -y && apt-get install -y --no-install-recommends \
    curl \
    libzip-dev \
    libpng-dev \
    libicu-dev \
    libonig-dev \
    libpq-dev \
    libkrb5-3 \
    unixodbc \
    unixodbc-dev \
    odbcinst \
    openssh-client \
    unzip \
    && docker-php-ext-install \
        pdo_mysql \
        pdo_pgsql \
        zip \
        gd \
        intl \
        bcmath \
        pcntl \
        sockets \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Microsoft ODBC Driver 18 + pdo_sqlsrv, so DataSource records can import from a
# customer's SQL Server ERP. Installed from a version-pinned .deb with a
# hardcoded checksum rather than by adding Microsoft's apt repo, so a rebuild
# cannot silently pull a different driver. Update both the version and the
# checksum together from
# https://packages.microsoft.com/debian/13/prod/dists/trixie/main/binary-<arch>/Packages
# arm64 trails amd64 by a patch release, hence the separate pins.
ARG TARGETARCH
RUN set -eux; \
    arch="${TARGETARCH:-amd64}"; \
    case "$arch" in \
        amd64) msodbc_version=18.6.2.1-1; msodbc_sha256=11e26f96feb95a7ecf55544441fa24233fab0debe8901e0ca9715c19fea05ab0 ;; \
        arm64) msodbc_version=18.6.1.1-1; msodbc_sha256=7ff666b1d18387d1b5640093a4f42c1f0461d8715c9da31e2c03bb213d61743b ;; \
        *) echo "msodbcsql18: unsupported architecture '$arch'" >&2; exit 1 ;; \
    esac; \
    curl -fsSL -o /tmp/msodbcsql18.deb \
        "https://packages.microsoft.com/debian/13/prod/pool/main/m/msodbcsql18/msodbcsql18_${msodbc_version}_${arch}.deb"; \
    echo "${msodbc_sha256}  /tmp/msodbcsql18.deb" > /tmp/msodbcsql18.sha256; \
    sha256sum -c /tmp/msodbcsql18.sha256; \
    ACCEPT_EULA=Y DEBIAN_FRONTEND=noninteractive dpkg -i /tmp/msodbcsql18.deb; \
    rm /tmp/msodbcsql18.deb /tmp/msodbcsql18.sha256; \
    pecl install pdo_sqlsrv-5.13.3; \
    docker-php-ext-enable pdo_sqlsrv

WORKDIR /var/www/html

# Ensure PHP-FPM listens on all interfaces (not just localhost)
RUN printf '[global]\ndaemonize = no\n\n[www]\nlisten = 0.0.0.0:9000\n' > /usr/local/etc/php-fpm.d/zz-docker.conf

# Keep function arguments out of exception stack traces — they would carry
# recipient PII (addresses, phones) into logs and error tracking, which
# Amazon's data protection policy forbids.
RUN printf 'zend.exception_ignore_args = 1\n' > /usr/local/etc/php/conf.d/zz-polybag.ini

# Copy application source
COPY . .

# Remove hot file if it was copied from host (forces production manifest)
# Ensure bind-mount destinations exist as empty files. Use rm -rf before touch
# so that if Docker previously created a directory at these paths (due to a
# missing bind-mount source), the build doesn't silently embed that directory.
RUN rm -f public/hot \
    && rm -rf public/qz-certificate.pem && touch public/qz-certificate.pem \
    && rm -rf storage/app/private/qz-private-key.pem && touch storage/app/private/qz-private-key.pem

# Copy vendor from stage 1 and built assets from stage 2
COPY --from=vendor /app/vendor vendor
COPY --from=assets /app/public/build public/build

# Install Composer for autoload optimization
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
RUN composer dump-autoload --optimize

# Set permissions — o+rX normalises source files regardless of host umask
RUN chmod -R o+rX . \
    && chown -R www-data:www-data storage bootstrap/cache \
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
FROM nginx:alpine@sha256:72ba65eb42c10344912a84ff42408db7d34f2feb642204570ab8fc5ffd29f1d3 AS nginx

COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=app /var/www/html/public /var/www/html/public
