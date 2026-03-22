FROM php:8.4-cli-alpine

RUN docker-php-ext-install openssl 2>/dev/null || true

COPY public/ /app/public/

WORKDIR /app
EXPOSE 8080

CMD ["php", "-S", "0.0.0.0:8080", "-t", "public"]
