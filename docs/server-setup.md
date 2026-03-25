# Server Setup

One-time setup for a VPS that will host PolyBag tenant instances.

## Requirements

- Ubuntu 24.04 LTS (recommended)
- 2 GB RAM minimum (shared infra), 4+ GB recommended for multiple tenants
- SSH access as root
- A domain with wildcard DNS pointing to the server (e.g. `*.polybag.app` -> server IP)

## Resource Estimates

| Component | RAM |
|---|---|
| Shared MySQL | ~300 MB |
| Shared Redis | ~16 MB (256 MB max) |
| Each tenant (app + nginx + queue) | ~120 MB |
| Caddy | ~30 MB |

With shared infra on a 2 GB server, you can comfortably run 8-10 tenants.

## 1. System Updates

```bash
apt update && apt upgrade -y
```

Reboot if the kernel was updated:

```bash
reboot
```

## 2. Firewall

If using a cloud provider firewall (Hetzner, DigitalOcean, etc.), allow inbound on:

- **22** (SSH)
- **80** (HTTP)
- **443** (HTTPS)

Block everything else.

## 3. Install Docker

```bash
curl -fsSL https://get.docker.com | sh
docker compose version  # verify
```

## 4. Create Docker Networks

```bash
docker network create proxy   # Caddy <-> nginx routing
docker network create shared  # Tenant app <-> shared MySQL/Redis
```

## 5. Set Up Caddy (Reverse Proxy)

Caddy runs as a Docker container on the `proxy` network, handling TLS and routing subdomains to tenant containers.

```bash
mkdir -p /opt/caddy
```

Create `/opt/caddy/Caddyfile`:

```
# Tenant entries are added by the provisioning script.
# Each entry maps a subdomain to a tenant's nginx container.
#
# Example:
# acme.polybag.app {
#     reverse_proxy acme-nginx-1:80
# }
```

Create `/opt/caddy/docker-compose.yml`:

```yaml
services:
  caddy:
    image: caddy:alpine@sha256:a1b7e624f860619cea121bdbc5dec2e112401666298c6507c6793b0a3ee6fc8e
    restart: unless-stopped
    ports:
      - "80:80"
      - "443:443"
      - "443:443/udp"
    volumes:
      - ./Caddyfile:/etc/caddy/Caddyfile
      - caddy_data:/data
      - caddy_config:/config
    networks:
      - proxy

volumes:
  caddy_data:
  caddy_config:

networks:
  proxy:
    external: true
```

Start Caddy:

```bash
cd /opt/caddy && docker compose up -d
```

## 6. Shared Infrastructure Setup

Shared MySQL + Redis serve all tenants from a single instance, reducing memory usage significantly.

```bash
mkdir -p /opt/shared
cp <repo>/infra/docker-compose.yml /opt/shared/docker-compose.yml
cp <repo>/infra/.env.example /opt/shared/.env
```

Edit `/opt/shared/.env` and set strong passwords:

```bash
cd /opt/shared
nano .env   # set MYSQL_ROOT_PASSWORD and REDIS_PASSWORD
```

Start shared infrastructure:

```bash
cd /opt/shared && docker compose up -d
```

Verify:

```bash
docker exec shared-mysql mysqladmin ping -h localhost
docker exec shared-redis redis-cli -a <password> ping
```

### Encryption at Rest (TDE)

Shared MySQL is configured with InnoDB tablespace encryption by default. The `mysql.cnf` file enables the `keyring_file` plugin and sets `default_table_encryption=ON`, so all new tables are encrypted automatically.

**How it works:**
- Data files on disk are encrypted with AES — queries, indexes, and search work normally (decrypted transparently at the query layer)
- The keyring file is stored in a separate Docker volume (`mysql-keyring`) from the data volume (`mysql-data`)
- Each tenant's `db:encrypt-tables` command runs on startup to encrypt any pre-existing unencrypted tables

**Copy the MySQL config to the shared directory:**

```bash
cp <repo>/docker/mysql.cnf /opt/shared/mysql.cnf
```

**Keyring backup:**

The keyring file is critical — if lost, encrypted data is unrecoverable. Back it up separately from the database:

```bash
# Find the keyring volume mount
docker volume inspect shared_mysql-keyring --format '{{ .Mountpoint }}'

# Copy the keyring file to a secure backup location (NOT the same location as DB backups)
cp /var/lib/docker/volumes/shared_mysql-keyring/_data/keyring /path/to/secure/backup/
```

Back up the keyring after initial setup and after any key rotation. Store it in a different location from your database backups (different cloud account, different physical location, or a password manager vault).

**Existing servers:** If upgrading an existing shared-mysql instance, restart it after adding the config:

```bash
cd /opt/shared && docker compose up -d
```

Then each tenant's next deploy will run `db:encrypt-tables` to encrypt existing tables.

## 7. Create Tenants Directory

```bash
mkdir -p /opt/tenants
```

## 8. Generate Wildcard QZ Tray Certificate (Optional)

For `*.polybag.app` tenants, a shared QZ Tray signing certificate avoids generating one per tenant:

```bash
mkdir -p /opt/shared/qz
openssl genrsa -out /opt/shared/qz/qz-private-key.pem 2048
openssl req -x509 -new -key /opt/shared/qz/qz-private-key.pem \
  -out /opt/shared/qz/qz-certificate.pem -days 3650 \
  -subj "/CN=*.polybag.app"
```

## 9. OAuth Broker (Optional)

The OAuth broker (`connect.<domain>`) handles OAuth authorization code flows on behalf of all PolyBag instances (shared tenants and on-prem). It holds provider client credentials centrally so individual instances don't need them. Skip this if you don't need OAuth integrations.

The broker is a separate Laravel app — see the [polybag-connect](https://github.com/niolson/polybag-connect) repo.

### Generate shared secret

```bash
echo "OAUTH_BROKER_SECRET=$(openssl rand -hex 32)" > /opt/shared/oauth.env
```

> The provisioning script reads this file and sets `OAUTH_BROKER_SECRET`, `OAUTH_BROKER_URL`, and `OAUTH_INSTANCE_ID` in each tenant's `.env`.

### Deploy the broker

```bash
git clone https://github.com/niolson/polybag-connect.git /opt/polybag-connect
cd /opt/polybag-connect
cp .env.example .env
```

Edit `/opt/polybag-connect/.env`:
- Set `SHARED_TENANT_SECRET` to the value from `/opt/shared/oauth.env`
- Set `REDIS_HOST=shared-redis` and `REDIS_PASSWORD` (from `/opt/shared/.env`)
- Add provider credentials (`SHOPIFY_CLIENT_ID`, `SHOPIFY_CLIENT_SECRET`, etc.)

Build and start:

```bash
cd /opt/polybag-connect && docker compose up -d --build
```

### Register on-prem instances

On-prem instances need individual secrets. Register them from inside the broker container:

```bash
docker exec -it polybag-connect-app-1 php artisan instance:register <instance-id>
```

This outputs a secret to give to the on-prem customer for their `.env`.

### Add Caddy route

Append to `/opt/caddy/Caddyfile`:

```
connect.polybag.app {
    reverse_proxy polybag-connect-app-1:8080
}
```

Reload Caddy:

```bash
docker compose -f /opt/caddy/docker-compose.yml exec caddy caddy reload --config /etc/caddy/Caddyfile
```

### Verify

```bash
curl -s -o /dev/null -w "%{http_code}" "https://connect.polybag.app/health"
# Should return 200
```

## 10. Provision Tenants

### Shared mode (default)

Uses the shared MySQL + Redis from step 6:

```bash
cd /opt/tenants
/opt/tenants/<any-tenant>/scripts/provision-tenant.sh acme
# or explicitly:
scripts/provision-tenant.sh --mode shared acme
```

The script will:
- Create a dedicated database (`polybag_acme`) and user in shared-mysql
- Set Redis prefix (`acme-`) for key isolation
- Mount QZ certs from `/opt/shared/qz/`

### Standalone mode

Per-tenant MySQL + Redis containers (higher memory, full isolation):

```bash
scripts/provision-tenant.sh --mode standalone acme
```

See `scripts/provision-tenant.sh` for details.

## On-Premise Installation

For single-tenant on-premise deployments (no Caddy, direct port access):

```bash
git clone https://github.com/niolson/polybag.git
cd polybag
./scripts/install-onprem.sh
```

The installer will:
1. Prompt for domain/IP
2. Generate database and Redis passwords
3. Build and start containers in standalone mode
4. Generate app key and QZ Tray certificate
5. Print instructions for creating the first admin user

On-prem uses `docker-compose.onprem.yml` to publish nginx ports directly (no reverse proxy needed).
