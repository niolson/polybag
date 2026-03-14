# Server Setup

One-time setup for a VPS that will host PolyBag tenant instances.

## Requirements

- Ubuntu 24.04 LTS (recommended)
- 4 CPU / 8 GB RAM minimum (scales ~10-20 tenants per server)
- SSH access as root
- A domain with wildcard DNS pointing to the server (e.g. `*.polybag.app` -> server IP)

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

## 6. Create Tenants Directory

```bash
mkdir -p /opt/tenants
```

## 7. Generate Wildcard QZ Tray Certificate (Optional)

For `*.polybag.app` tenants, a shared QZ Tray signing certificate avoids generating one per tenant:

```bash
mkdir -p /opt/shared/qz
openssl genrsa -out /opt/shared/qz/qz-private-key.pem 2048
openssl req -x509 -new -key /opt/shared/qz/qz-private-key.pem \
  -out /opt/shared/qz/qz-certificate.pem -days 3650 \
  -subj "/CN=*.polybag.app"
```

The provisioning script symlinks these into each tenant. On-premise installs generate their own cert via `php artisan app:generate-qz-cert`.

## 8. Provision Tenants

Use the provisioning script to add tenants:

```bash
scripts/provision-tenant.sh acme
```

See `scripts/provision-tenant.sh` for details.
