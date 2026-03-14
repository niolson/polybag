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

## 4. Create Shared Docker Network

```bash
docker network create proxy
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
    image: caddy:alpine
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

## 9. Provision Tenants

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
