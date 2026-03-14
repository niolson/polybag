#!/bin/bash
set -euo pipefail

# PolyBag On-Premise Installer
# Single-tenant install with standalone MySQL + Redis containers.
#
# Usage: ./scripts/install-onprem.sh

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"

# --- Helpers ---

info()  { echo -e "\033[1;34m[INFO]\033[0m  $*"; }
error() { echo -e "\033[1;31m[ERROR]\033[0m $*" >&2; }
ok()    { echo -e "\033[1;32m[OK]\033[0m    $*"; }

generate_password() {
    openssl rand -base64 24 | tr -d '/+=' | head -c 32
}

cd "$PROJECT_DIR"

# --- Pre-flight checks ---

if ! command -v docker &>/dev/null; then
    error "Docker is not installed. Install it first: https://docs.docker.com/engine/install/"
    exit 1
fi

if ! docker compose version &>/dev/null; then
    error "Docker Compose plugin is not installed."
    exit 1
fi

# --- Gather input ---

echo ""
echo "=== PolyBag On-Premise Installer ==="
echo ""

if [ -f .env ]; then
    info ".env already exists. Skipping environment setup."
    SKIP_ENV=true
else
    SKIP_ENV=false

    read -rp "Enter your domain or IP address (e.g. polybag.example.com or 192.168.1.100): " APP_HOST

    if [ -z "$APP_HOST" ]; then
        error "Domain/IP is required."
        exit 1
    fi

    # Determine protocol — IPs get http, domains get https
    if [[ "$APP_HOST" =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
        APP_URL="http://${APP_HOST}"
    else
        APP_URL="https://${APP_HOST}"
    fi
fi

# --- Create .env ---

if [ "$SKIP_ENV" = false ]; then
    DB_PASSWORD=$(generate_password)
    REDIS_PASSWORD=$(generate_password)

    info "Creating .env..."
    cp .env.example .env

    sed -i "s|^APP_ENV=.*|APP_ENV=production|" .env
    sed -i "s|^APP_DEBUG=.*|APP_DEBUG=false|" .env
    sed -i "s|^APP_URL=.*|APP_URL=${APP_URL}|" .env
    sed -i "s|^DB_HOST=.*|DB_HOST=mysql|" .env
    sed -i "s|^DB_DATABASE=.*|DB_DATABASE=polybag|" .env
    sed -i "s|^DB_USERNAME=.*|DB_USERNAME=polybag|" .env
    sed -i "s|^DB_PASSWORD=.*|DB_PASSWORD=${DB_PASSWORD}|" .env
    sed -i "s|^REDIS_HOST=.*|REDIS_HOST=redis|" .env
    sed -i "s|^REDIS_PASSWORD=.*|REDIS_PASSWORD=${REDIS_PASSWORD}|" .env

    ok ".env created."
fi

# --- Docker network ---

if ! docker network inspect proxy &>/dev/null; then
    info "Creating Docker network 'proxy'..."
    docker network create proxy
    ok "Network created."
fi

# --- Build & Start ---

info "Building and starting containers (standalone mode)..."
docker compose --profile standalone \
    -f docker-compose.yml \
    -f docker-compose.onprem.yml \
    up -d --build

info "Waiting for app to become healthy..."
timeout=120
elapsed=0
while [ $elapsed -lt $timeout ]; do
    status=$(docker compose --profile standalone \
        -f docker-compose.yml \
        -f docker-compose.onprem.yml \
        ps app --format '{{.Status}}' 2>/dev/null || echo "")
    if echo "$status" | grep -q "(healthy)"; then
        break
    fi
    sleep 5
    elapsed=$((elapsed + 5))
done

if [ $elapsed -ge $timeout ]; then
    error "App container did not become healthy within ${timeout}s."
    error "Check logs: docker compose logs app"
    exit 1
fi

ok "Containers running."

# --- Generate app key ---

info "Generating application key..."
docker compose --profile standalone \
    -f docker-compose.yml \
    -f docker-compose.onprem.yml \
    exec app php artisan key:generate --force
ok "App key generated."

# --- Generate QZ Tray certificate ---

info "Generating QZ Tray certificate..."
docker compose --profile standalone \
    -f docker-compose.yml \
    -f docker-compose.onprem.yml \
    exec app php artisan app:generate-qz-cert --no-interaction
ok "QZ Tray certificate generated."

# --- Rebuild config cache with new key and restart ---

info "Rebuilding config cache..."
docker compose --profile standalone \
    -f docker-compose.yml \
    -f docker-compose.onprem.yml \
    exec app php artisan config:cache
docker compose --profile standalone \
    -f docker-compose.yml \
    -f docker-compose.onprem.yml \
    restart app
ok "App restarted with config cached."

# --- Summary ---

APP_URL_DISPLAY=$(grep '^APP_URL=' .env | cut -d= -f2-)

echo ""
echo "==========================================="
echo "  PolyBag installed successfully!"
echo "  URL: ${APP_URL_DISPLAY}"
echo "==========================================="
echo ""
echo "Next steps:"
echo "  1. Create the first admin user:"
echo "     docker compose --profile standalone -f docker-compose.yml -f docker-compose.onprem.yml exec -it app php artisan app:create-user"
echo ""
echo "  2. Open ${APP_URL_DISPLAY} in your browser"
echo ""
