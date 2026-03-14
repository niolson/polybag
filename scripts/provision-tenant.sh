#!/bin/bash
set -euo pipefail

# PolyBag Tenant Provisioning Script
# Usage: ./scripts/provision-tenant.sh <tenant-name> [domain]
#
# Examples:
#   ./scripts/provision-tenant.sh acme                    # -> acme.polybag.app
#   ./scripts/provision-tenant.sh acme acme.example.com   # -> custom domain

REPO_URL="https://github.com/niolson/polybag.git"
TENANTS_DIR="/opt/tenants"
CADDY_DIR="/opt/caddy"
SHARED_QZ_DIR="/opt/shared/qz"
DEFAULT_DOMAIN_SUFFIX="polybag.app"

# --- Helpers ---

info()  { echo -e "\033[1;34m[INFO]\033[0m  $*"; }
error() { echo -e "\033[1;31m[ERROR]\033[0m $*" >&2; }
ok()    { echo -e "\033[1;32m[OK]\033[0m    $*"; }

generate_password() {
    openssl rand -base64 24 | tr -d '/+=' | head -c 32
}

# --- Validate ---

if [ $# -lt 1 ]; then
    error "Usage: $0 <tenant-name> [domain]"
    exit 1
fi

TENANT="$1"
DOMAIN="${2:-${TENANT}.${DEFAULT_DOMAIN_SUFFIX}}"
TENANT_DIR="${TENANTS_DIR}/${TENANT}"

if [ -d "$TENANT_DIR" ]; then
    error "Tenant directory already exists: ${TENANT_DIR}"
    exit 1
fi

if ! docker network inspect proxy &>/dev/null; then
    error "Docker network 'proxy' does not exist. Run: docker network create proxy"
    exit 1
fi

# --- Clone ---

info "Cloning repo into ${TENANT_DIR}..."
git clone "$REPO_URL" "$TENANT_DIR"
cd "$TENANT_DIR"

# --- Environment ---

DB_PASSWORD=$(generate_password)

info "Creating .env..."
cp .env.example .env
sed -i "s|^APP_NAME=.*|APP_NAME=\"PolyBag\"|" .env
sed -i "s|^APP_ENV=.*|APP_ENV=production|" .env
sed -i "s|^APP_DEBUG=.*|APP_DEBUG=false|" .env
sed -i "s|^APP_URL=.*|APP_URL=https://${DOMAIN}|" .env
sed -i "s|^DB_CONNECTION=.*|DB_CONNECTION=mysql|" .env
sed -i "s|^DB_HOST=.*|DB_HOST=mysql|" .env
sed -i "s|^DB_DATABASE=.*|DB_DATABASE=polybag|" .env
sed -i "s|^DB_USERNAME=.*|DB_USERNAME=polybag|" .env
sed -i "s|^DB_PASSWORD=.*|DB_PASSWORD=${DB_PASSWORD}|" .env
sed -i "s|^REDIS_HOST=.*|REDIS_HOST=redis|" .env
sed -i "s|^QUEUE_CONNECTION=.*|QUEUE_CONNECTION=redis|" .env
sed -i "s|^SESSION_DRIVER=.*|SESSION_DRIVER=redis|" .env
sed -i "s|^CACHE_STORE=.*|CACHE_STORE=redis|" .env

# --- QZ Tray Certificate ---

if [ -f "${SHARED_QZ_DIR}/qz-private-key.pem" ] && [ -f "${SHARED_QZ_DIR}/qz-certificate.pem" ]; then
    info "Copying shared QZ Tray certificate..."
    mkdir -p storage/app/private
    cp "${SHARED_QZ_DIR}/qz-private-key.pem" storage/app/private/qz-private-key.pem
    cp "${SHARED_QZ_DIR}/qz-certificate.pem" public/qz-certificate.pem
    ok "QZ Tray certificate copied."
else
    info "No shared QZ Tray certificate found at ${SHARED_QZ_DIR}."
    info "Generate one after setup: docker compose exec -it app php artisan app:generate-qz-cert"
fi

# --- Build & Start ---

info "Building and starting containers..."
docker compose up -d --build

info "Waiting for app to become healthy..."
timeout=120
elapsed=0
while [ $elapsed -lt $timeout ]; do
    status=$(docker compose ps app --format '{{.Status}}' 2>/dev/null || echo "")
    if echo "$status" | grep -q "(healthy)"; then
        break
    fi
    sleep 5
    elapsed=$((elapsed + 5))
done

if [ $elapsed -ge $timeout ]; then
    error "App container did not become healthy within ${timeout}s."
    error "Check logs: cd ${TENANT_DIR} && docker compose logs app"
    exit 1
fi

ok "Containers running."

# --- Generate App Key ---

info "Generating application key..."
docker compose exec app php artisan key:generate --force
ok "App key generated."

# --- Caddy ---

info "Adding Caddy route for ${DOMAIN}..."
cat >> "${CADDY_DIR}/Caddyfile" << EOF

${DOMAIN} {
    reverse_proxy ${TENANT}-nginx-1:80
}
EOF

info "Reloading Caddy..."
docker compose -f "${CADDY_DIR}/docker-compose.yml" exec caddy caddy reload --config /etc/caddy/Caddyfile
ok "Caddy reloaded."

# --- Summary ---

echo ""
echo "==========================================="
echo "  Tenant provisioned: ${TENANT}"
echo "  Domain:  https://${DOMAIN}"
echo "  Dir:     ${TENANT_DIR}"
echo "==========================================="
echo ""
echo "Next steps:"
echo "  1. Create the first admin user:"
echo "     cd ${TENANT_DIR}"
echo "     docker compose exec -it app php artisan app:create-user"
echo ""
echo "  2. Log in at https://${DOMAIN}"
echo ""
