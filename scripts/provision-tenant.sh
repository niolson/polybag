#!/bin/bash
set -euo pipefail

# PolyBag Tenant Provisioning Script
# Usage: ./scripts/provision-tenant.sh [--mode shared|standalone] <tenant-name> [domain]
#
# Modes:
#   shared     - Uses shared MySQL + Redis from /opt/shared/ (default)
#   standalone - Per-tenant MySQL + Redis containers (for on-prem)
#
# Examples:
#   ./scripts/provision-tenant.sh acme                          # shared mode, acme.polybag.app
#   ./scripts/provision-tenant.sh --mode standalone acme        # standalone mode
#   ./scripts/provision-tenant.sh acme acme.example.com         # shared mode, custom domain

REPO_URL="https://github.com/niolson/polybag.git"
TENANTS_DIR="/opt/tenants"
CADDY_DIR="/opt/caddy"
SHARED_DIR="/opt/shared"
SHARED_QZ_DIR="${SHARED_DIR}/qz"
DEFAULT_DOMAIN_SUFFIX="polybag.app"

# --- Helpers ---

info()  { echo -e "\033[1;34m[INFO]\033[0m  $*"; }
error() { echo -e "\033[1;31m[ERROR]\033[0m $*" >&2; }
ok()    { echo -e "\033[1;32m[OK]\033[0m    $*"; }

generate_password() {
    openssl rand -base64 24 | tr -d '/+=' | head -c 32
}

# --- Parse arguments ---

MODE="shared"

while [[ $# -gt 0 ]]; do
    case "$1" in
        --mode)
            MODE="$2"
            shift 2
            ;;
        --mode=*)
            MODE="${1#*=}"
            shift
            ;;
        -*)
            error "Unknown option: $1"
            exit 1
            ;;
        *)
            break
            ;;
    esac
done

if [[ "$MODE" != "shared" && "$MODE" != "standalone" ]]; then
    error "Invalid mode: ${MODE}. Must be 'shared' or 'standalone'."
    exit 1
fi

# --- Validate ---

if [ $# -lt 1 ]; then
    error "Usage: $0 [--mode shared|standalone] <tenant-name> [domain]"
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

# --- Mode-specific validation ---

if [ "$MODE" = "shared" ]; then
    if [ ! -f "${SHARED_DIR}/.env" ]; then
        error "Shared infrastructure .env not found at ${SHARED_DIR}/.env"
        error "Set up shared infra first: cd ${SHARED_DIR} && docker compose up -d"
        exit 1
    fi

    # Verify shared containers are running
    if ! docker inspect shared-mysql --format '{{.State.Running}}' 2>/dev/null | grep -q true; then
        error "shared-mysql container is not running. Start shared infra first."
        exit 1
    fi
    if ! docker inspect shared-redis --format '{{.State.Running}}' 2>/dev/null | grep -q true; then
        error "shared-redis container is not running. Start shared infra first."
        exit 1
    fi
fi

# --- Clone ---

info "Cloning repo into ${TENANT_DIR}..."
git clone "$REPO_URL" "$TENANT_DIR"
cd "$TENANT_DIR"

# --- Environment ---

DB_PASSWORD=$(generate_password)
REDIS_PASSWORD=$(generate_password)

info "Creating .env (mode: ${MODE})..."
cp .env.example .env

# Common settings
sed -i "s|^APP_NAME=.*|APP_NAME=\"PolyBag\"|" .env
sed -i "s|^APP_ENV=.*|APP_ENV=production|" .env
sed -i "s|^APP_DEBUG=.*|APP_DEBUG=false|" .env
sed -i "s|^APP_URL=.*|APP_URL=https://${DOMAIN}|" .env
sed -i "s|^DB_CONNECTION=.*|DB_CONNECTION=mysql|" .env
sed -i "s|^QUEUE_CONNECTION=.*|QUEUE_CONNECTION=redis|" .env
sed -i "s|^SESSION_DRIVER=.*|SESSION_DRIVER=redis|" .env
sed -i "s|^CACHE_STORE=.*|CACHE_STORE=redis|" .env

if [ "$MODE" = "shared" ]; then
    # --- Shared mode: use shared-mysql and shared-redis ---

    SHARED_MYSQL_ROOT_PASS=$(grep '^MYSQL_ROOT_PASSWORD=' "${SHARED_DIR}/.env" | cut -d= -f2-)
    SHARED_REDIS_PASS=$(grep '^REDIS_PASSWORD=' "${SHARED_DIR}/.env" | cut -d= -f2-)

    DB_NAME="polybag_${TENANT}"
    DB_USER="polybag_${TENANT}"

    # Check for prefix collision in shared Redis
    for existing_dir in "${TENANTS_DIR}"/*/; do
        if [ -f "${existing_dir}.env" ]; then
            existing_prefix=$(grep '^REDIS_PREFIX=' "${existing_dir}.env" | cut -d= -f2- || true)
            if [ "$existing_prefix" = "${TENANT}-" ]; then
                error "Redis prefix '${TENANT}-' already in use by another tenant."
                rm -rf "$TENANT_DIR"
                exit 1
            fi
        fi
    done

    # Create database and user in shared MySQL
    info "Creating database '${DB_NAME}' in shared-mysql..."
    docker exec shared-mysql mysql -uroot -p"${SHARED_MYSQL_ROOT_PASS}" -e "
        CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
        CREATE USER IF NOT EXISTS '${DB_USER}'@'%' IDENTIFIED BY '${DB_PASSWORD}';
        GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'%';
        FLUSH PRIVILEGES;
    "
    ok "Database created."

    sed -i "s|^DB_HOST=.*|DB_HOST=shared-mysql|" .env
    sed -i "s|^DB_DATABASE=.*|DB_DATABASE=${DB_NAME}|" .env
    sed -i "s|^DB_USERNAME=.*|DB_USERNAME=${DB_USER}|" .env
    sed -i "s|^DB_PASSWORD=.*|DB_PASSWORD=${DB_PASSWORD}|" .env
    sed -i "s|^REDIS_HOST=.*|REDIS_HOST=shared-redis|" .env
    sed -i "s|^REDIS_PASSWORD=.*|REDIS_PASSWORD=${SHARED_REDIS_PASS}|" .env

    # Set Redis prefix for tenant isolation
    sed -i "s|^# REDIS_PREFIX=.*|REDIS_PREFIX=${TENANT}-|" .env
    # If the line wasn't commented, update it directly
    sed -i "s|^REDIS_PREFIX=.*|REDIS_PREFIX=${TENANT}-|" .env
    sed -i "s|^# CACHE_PREFIX=.*|CACHE_PREFIX=${TENANT}-cache-|" .env

    # Point QZ cert volumes at shared location
    echo "" >> .env
    echo "# QZ Tray certificate paths (shared)" >> .env
    echo "QZ_PRIVATE_KEY_PATH=${SHARED_QZ_DIR}/qz-private-key.pem" >> .env
    echo "QZ_CERTIFICATE_PATH=${SHARED_QZ_DIR}/qz-certificate.pem" >> .env

else
    # --- Standalone mode: per-tenant MySQL + Redis ---

    sed -i "s|^DB_HOST=.*|DB_HOST=mysql|" .env
    sed -i "s|^DB_DATABASE=.*|DB_DATABASE=polybag|" .env
    sed -i "s|^DB_USERNAME=.*|DB_USERNAME=polybag|" .env
    sed -i "s|^DB_PASSWORD=.*|DB_PASSWORD=${DB_PASSWORD}|" .env
    sed -i "s|^REDIS_HOST=.*|REDIS_HOST=redis|" .env
    sed -i "s|^REDIS_PASSWORD=.*|REDIS_PASSWORD=${REDIS_PASSWORD}|" .env
fi

# --- QZ Tray Certificate ---

if [ -f "${SHARED_QZ_DIR}/qz-private-key.pem" ] && [ -f "${SHARED_QZ_DIR}/qz-certificate.pem" ]; then
    if [ "$MODE" = "standalone" ]; then
        info "Copying shared QZ Tray certificate..."
        mkdir -p storage/app/private
        cp "${SHARED_QZ_DIR}/qz-private-key.pem" storage/app/private/qz-private-key.pem
        cp "${SHARED_QZ_DIR}/qz-certificate.pem" public/qz-certificate.pem
        ok "QZ Tray certificate copied."
    else
        ok "QZ Tray certificate will be mounted from shared location."
    fi
else
    info "No shared QZ Tray certificate found at ${SHARED_QZ_DIR}."
    info "Generate one after setup: docker compose exec -it app php artisan app:generate-qz-cert"
fi

# --- Build & Start ---

info "Building and starting containers (${MODE} mode)..."
if [ "$MODE" = "standalone" ]; then
    docker compose --profile standalone up -d --build
else
    docker compose up -d --build
fi

info "Waiting for app to become healthy..."
timeout=120
elapsed=0
while [ $elapsed -lt $timeout ]; do
    if [ "$MODE" = "standalone" ]; then
        status=$(docker compose --profile standalone ps app --format '{{.Status}}' 2>/dev/null || echo "")
    else
        status=$(docker compose ps app --format '{{.Status}}' 2>/dev/null || echo "")
    fi
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
if [ "$MODE" = "standalone" ]; then
    docker compose --profile standalone exec app php artisan key:generate --force
    docker compose --profile standalone exec app php artisan config:cache
    docker compose --profile standalone restart app
else
    docker compose exec app php artisan key:generate --force
    docker compose exec app php artisan config:cache
    docker compose restart app
fi
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
echo "  Mode:    ${MODE}"
echo "  Domain:  https://${DOMAIN}"
echo "  Dir:     ${TENANT_DIR}"
if [ "$MODE" = "shared" ]; then
echo "  DB:      polybag_${TENANT} @ shared-mysql"
echo "  Redis:   shared-redis (prefix: ${TENANT}-)"
fi
echo "==========================================="
echo ""
echo "Next steps:"
echo "  1. Create the first admin user:"
echo "     cd ${TENANT_DIR}"
if [ "$MODE" = "standalone" ]; then
echo "     docker compose --profile standalone exec -it app php artisan app:create-user"
else
echo "     docker compose exec -it app php artisan app:create-user"
fi
echo ""
echo "  2. Log in at https://${DOMAIN}"
echo ""
