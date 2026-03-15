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
    openssl rand -hex 16
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
    ok "Network 'proxy' created."
fi

if ! docker network inspect shared &>/dev/null; then
    info "Creating Docker network 'shared'..."
    docker network create shared
    ok "Network 'shared' created."
fi

# --- Build & Start ---

# Create placeholder QZ cert files so Docker doesn't mount them as directories
mkdir -p storage/app/private
touch storage/app/private/qz-private-key.pem
touch public/qz-certificate.pem

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
# Write key to .env on the host (avoids container bind-mount write permission issues)

info "Generating application key..."
APP_KEY="base64:$(openssl rand -base64 32)"
sed -i "s|^APP_KEY=.*|APP_KEY=${APP_KEY}|" .env
ok "App key generated."

# --- Generate QZ Tray certificate ---

info "Generating QZ Tray certificate..."
QZ_DOMAIN=$(grep '^APP_URL=' .env | sed 's|^APP_URL=https\?://||' | sed 's|:.*||')
openssl genrsa -out storage/app/private/qz-private-key.pem 2048 2>/dev/null
openssl req -x509 -new -key storage/app/private/qz-private-key.pem \
    -out public/qz-certificate.pem -days 3650 \
    -subj "/CN=${QZ_DOMAIN}" 2>/dev/null
chmod 600 storage/app/private/qz-private-key.pem
ok "QZ Tray certificate generated for ${QZ_DOMAIN}."

# --- Restart to pick up new key ---

info "Restarting app with new key..."
docker compose --profile standalone \
    -f docker-compose.yml \
    -f docker-compose.onprem.yml \
    up -d --force-recreate app queue
ok "App restarted."

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
