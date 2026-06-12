# Dhiarlink — Production Deployment Guide

**Target environment:** Proxmox LXC container + Cloudflare Tunnel (no public IP required)

This guide walks you through deploying Dhiarlink from zero to fully operational on a self-hosted Proxmox LXC container, with Cloudflare Tunnel providing public access without a public IP address.

---

## Table of Contents

- [Architecture Overview](#architecture-overview)
- [Step 1: Proxmox LXC Container Setup](#step-1-proxmox-lxc-container-setup)
- [Step 2: System Preparation](#step-2-system-preparation)
- [Step 3: Install Docker & Docker Compose](#step-3-install-docker--docker-compose)
- [Step 4: Clone & Prepare the Project](#step-4-clone--prepare-the-project)
- [Step 5: Generate Secrets & Create .env](#step-5-generate-secrets--create-env)
- [Step 6: Cloudflare Tunnel Setup](#step-6-cloudflare-tunnel-setup)
- [Step 7: Cloudflare DNS Configuration](#step-7-cloudflare-dns-configuration)
- [Step 8: Build & Start the Production Stack](#step-8-build--start-the-production-stack)
- [Step 9: Initialize Dhiarlink](#step-9-initialize-dhiarlink)
- [Step 10: Verify Everything Works](#step-10-verify-everything-works)
- [Optional Features](#optional-features)
    - [GeoLite2 IP Geolocation](#geolite2-ip-geolocation)
    - [Mercure Real-Time Updates](#mercure-real-time-updates)
    - [Matomo Analytics](#matomo-analytics)
- [Updating & Maintenance](#updating--maintenance)
- [Backup & Restore](#backup--restore)
- [Troubleshooting](#troubleshooting)
- [Quick Reference](#quick-reference)

---

## Architecture Overview

```
                          Cloudflare Edge (TLS termination)
                          ├── dhiarr.qzz.io       (landing page)
                          ├── app.dhiarr.qzz.io   (dashboard UI)
                          └── link.dhiarr.qzz.io  (short URL redirects)
                                    │
                          Cloudflare Tunnel (encrypted)
                                    │
                            ┌───────▼───────┐
                            │  cloudflared   │  ← Docker container
                            └───────┬───────┘
                                    │  (plain HTTP, port 80)
                            ┌───────▼───────┐
                            │     Caddy      │  ← Reverse proxy (routes by hostname)
                            └───┬───────┬───┘
                    ┌───────────┘       └───────────┐
                    │                               │
           ┌────────▼────────┐            ┌─────────▼──────────┐
           │    Dhiarlink     │            │  shlink-web-client  │
           │  (RoadRunner)    │            │  (React dashboard)  │
           │  port 8080       │            │  port 80            │
           └───┬──────────┬──┘            └────────────────────┘
               │          │
        ┌──────▼──┐  ┌────▼────┐
        │ MySQL 8 │  │ Redis 7 │
        └─────────┘  └─────────┘
```

**Key points:**
- No ports are exposed to the host machine — all traffic enters via the Cloudflare Tunnel
- TLS/HTTPS is terminated at Cloudflare's edge — internal traffic is plain HTTP
- Caddy routes requests to the correct backend based on the hostname
- The database and Redis are only accessible within the Docker network

---

## Step 1: Proxmox LXC Container Setup

### 1.1 Create the LXC Container

From the Proxmox web UI or CLI, create a new LXC container with these settings:

| Setting | Recommended Value |
|---------|-------------------|
| **Template** | Debian 12 (Bookworm) or Ubuntu 24.04 LTS |
| **Hostname** | `dhiarlink` |
| **CPU cores** | 2 (minimum) |
| **Memory** | 2048 MB (4096 MB recommended) |
| **Swap** | 1024 MB |
| **Root disk** | 20 GB minimum (SSD recommended) |
| **Network** | DHCP or static IP on your LAN |

### 1.2 Enable Nesting (Required for Docker)

Docker requires nested containers in LXC. **This must be done while the container is stopped.**

```bash
# From the Proxmox host (replace CTID with your container ID)
pct set <CTID> --features nesting=1
```

Or in the Proxmox web UI:
1. Select the container → **Options** → **Features** → Enable **Nesting**

### 1.3 Start the Container

```bash
pct start <CTID>
pct enter <CTID>
```

---

## Step 2: System Preparation

Inside the LXC container:

### 2.1 Update the System

```bash
apt update && apt upgrade -y
```

### 2.2 Install Essential Packages

```bash
apt install -y \
    curl \
    wget \
    gnupg \
    ca-certificates \
    lsb-release \
    git \
    unzip \
    nano \
    htop
```

### 2.3 Set the Timezone

```bash
timedatectl set-timezone Asia/Jakarta
# Or your preferred timezone:
# timedatectl set-timezone $(cat /etc/timezone)
```

### 2.4 (Recommended) Create a Non-Root User

```bash
adduser dhiarlink
usermod -aG sudo dhiarlink
su - dhiarlink
```

> **Note:** All subsequent commands assume you're running as a user with `sudo` access. Adjust `sudo` usage accordingly.

---

## Step 3: Install Docker & Docker Compose

### 3.1 Install Docker Engine

```bash
# Remove old versions
sudo apt remove -y docker docker-engine docker.io containerd runc 2>/dev/null

# Add Docker's official GPG key
sudo install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/debian/gpg | sudo gpg --dearmor -o /etc/apt/keyrings/docker.gpg
sudo chmod a+r /etc/apt/keyrings/docker.gpg

# Add Docker repository (Debian)
echo \
  "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/debian \
  $(lsb_release -cs) stable" | sudo tee /etc/apt/sources.list.d/docker.list > /dev/null

# Install Docker
sudo apt update
sudo apt install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
```

> **For Ubuntu:** Replace `debian` with `ubuntu` in the repository URL above.

### 3.2 Add Your User to the Docker Group

```bash
sudo usermod -aG docker $USER
newgrp docker
```

### 3.3 Verify Installation

```bash
docker --version
docker compose version
docker run --rm hello-world
```

### 3.4 Enable Docker on Boot

```bash
sudo systemctl enable docker
sudo systemctl start docker
```

---

## Step 4: Clone & Prepare the Project

### 4.1 Clone the Repository

```bash
# Choose your preferred location
cd /opt
sudo git clone <your-repo-url> dhiarlink
sudo chown -R $USER:$USER dhiarlink
cd dhiarlink
```

### 4.2 Create the Production .env File

```bash
cp .env.example .env
```

You'll fill in the values in the next step.

---

## Step 5: Generate Secrets & Create .env

### 5.1 Generate Secure Passwords

```bash
# Generate random passwords for the database and other secrets
echo "DB_PASSWORD: $(openssl rand -base64 32)"
echo "DB_ROOT_PASSWORD: $(openssl rand -base64 32)"
echo "INITIAL_API_KEY: $(openssl rand -hex 24)"
```

**Save these somewhere safe** (like a password manager). You'll need them below.

### 5.2 Edit the .env File

Open `.env` and fill in **at minimum** these values:

```bash
nano .env   # or use your preferred editor
```

```env
# === REQUIRED — Fill these in ===

# Database passwords (use the values generated above)
DB_PASSWORD=<your-generated-db-password>
DB_ROOT_PASSWORD=<your-generated-root-password>

# Cloudflare Tunnel token (from Step 6 below)
CLOUDFLARE_TUNNEL_TOKEN=<your-tunnel-token>

# Initial API key (used to auto-create the first API key on startup)
INITIAL_API_KEY=<your-generated-api-key>

# === Domain Configuration ===

# The domain used when generating short URLs
DEFAULT_DOMAIN=link.dhiarr.qzz.io

# Short URLs will use HTTPS (Cloudflare handles TLS)
IS_HTTPS_ENABLED=true

# === Database ===
DB_DRIVER=mysql
DB_HOST=dhiarlink_db
DB_PORT=3306
DB_NAME=dhiarlink
DB_USER=dhiarlink

# === Redis ===
REDIS_SERVERS=tcp://dhiarlink_redis:6379
REDIS_PUB_SUB_ENABLED=true

# === Behind Cloudflare Tunnel ===
TRUSTED_PROXIES=1

# === CORS — allow the dashboard to call the API ===
CORS_ALLOW_ORIGIN=https://app\.dhiarr\.qzz\.io

# === Application ===
APP_ENV=prod
DHIARLINK_VERSION=1.0.0
DHIARLINK_RUNTIME=rr
```

> **Important:** The `CORS_ALLOW_ORIGIN` value uses regex escaping. The backslashes before the dots (`\.`) are intentional — they match literal dots in the domain name.

---

## Step 6: Cloudflare Tunnel Setup

### 6.1 Prerequisites

- A Cloudflare account (free tier works)
- Your domain (`qzz.io`) added to Cloudflare with DNS managed there
- [Cloudflare Zero Trust dashboard](https://one.dash.cloudflare.com/) access

### 6.2 Create a Tunnel

1. Go to **[Cloudflare Zero Trust](https://one.dash.cloudflare.com/)** → **Networks** → **Tunnels**
2. Click **Create a tunnel**
3. Choose **Cloudflared** as the connector
4. Name the tunnel: `dhiarlink`
5. Click **Save tunnel**

### 6.3 Copy the Tunnel Token

After creating the tunnel, you'll see a token (a long string). **Copy this token** — it's the value for `CLOUDFLARE_TUNNEL_TOKEN` in your `.env` file.

### 6.4 Configure Public Hostnames

In the tunnel configuration, add these **three public hostnames**. For all of them, set the **Service** to point to Caddy:

| Public Hostname | Service | Notes |
|-----------------|---------|-------|
| `dhiarr.qzz.io` | `http://dhiarlink_caddy:80` | Landing page |
| `app.dhiarr.qzz.io` | `http://dhiarlink_caddy:80` | Dashboard |
| `link.dhiarr.qzz.io` | `http://dhiarlink_caddy:80` | Short URL redirects |

**Additional settings for ALL three hostnames** (under "Additional application settings"):

| Setting | Value |
|---------|-------|
| **HTTP Settings → No TLS Verify** | `Off` (not needed — internal is HTTP) |
| **HTTP Settings → Keep Alive Timeout** | `90s` (helps with long-lived connections) |
| **HTTP Settings → HTTP Host Header** | (leave default — Caddy routes by hostname) |

> **Important:** The Service URL `http://dhiarlink_caddy:80` uses the Docker container name as hostname. This works because cloudflared and Caddy are on the same Docker network. If you see connection errors, you may need to use the Caddy container's IP address instead (find it with `docker inspect dhiarlink_caddy`).

### 6.5 (Alternative) Use Local Config File Instead of Dashboard

If you prefer managing the tunnel config locally instead of via the Cloudflare dashboard, create a config file:

```bash
mkdir -p cloudflared
```

Create `cloudflared/config.yml`:

```yaml
tunnel: dhiarlink
credentials-file: /etc/cloudflared/credentials.json

ingress:
    # Landing page
    - hostname: dhiarr.qzz.io
      service: http://dhiarlink_caddy:80
    # Dashboard
    - hostname: app.dhiarr.qzz.io
      service: http://dhiarlink_caddy:80
    # Short URL redirects
    - hostname: link.dhiarr.qzz.io
      service: http://dhiarlink_caddy:80
    # Required catch-all
    - service: http_status:404
```

Then modify the `cloudflared` service in `docker-compose.prod.yml` to use the local config:

```yaml
    cloudflared:
        container_name: cloudflared
        image: cloudflare/cloudflared:latest
        restart: unless-stopped
        command: tunnel --config /etc/cloudflared/config.yml run
        volumes:
            - ./cloudflared:/etc/cloudflared:ro
        networks:
            - dhiarlink_internal
        depends_on:
            - dhiarlink_caddy
```

And remove the `CLOUDFLARE_TUNNEL_TOKEN` from `.env` — you'd use a credentials file instead (generated via `cloudflared tunnel login` and `cloudflared tunnel create dhiarlink` on the host first).

---

## Step 7: Cloudflare DNS Configuration

### 7.1 Add DNS Records

In the **Cloudflare dashboard** → **DNS** → **Records**, add CNAME records for each subdomain pointing to the tunnel:

| Type | Name | Target | Proxy Status |
|------|------|--------|-------------|
| **CNAME** | `dhiarr` | `<your-tunnel-id>.cfargotunnel.com` | **Proxied** (orange cloud) |
| **CNAME** | `app` | `<your-tunnel-id>.cfargotunnel.com` | **Proxied** (orange cloud) |
| **CNAME** | `link` | `<your-tunnel-id>.cfargotunnel.com` | **Proxied** (orange cloud) |

> The `<your-tunnel-id>` is shown when you created the tunnel. It looks like: `a1b2c3d4e5f6...`

> **If you configured public hostnames via the Zero Trust dashboard (Step 6.4)**, Cloudflare automatically creates these DNS records for you. Verify they exist in the DNS tab.

### 7.2 SSL/TLS Settings

In the **Cloudflare dashboard** → **SSL/TLS** → **Overview**:

| Setting | Value |
|---------|-------|
| **SSL/TLS encryption mode** | **Full** (not Full Strict — internal traffic is HTTP) |
| **Always Use HTTPS** | **On** |
| **Minimum TLS Version** | **TLS 1.2** |

> **Why "Full" and not "Full (Strict)"?** With Cloudflare Tunnel, the connection between Cloudflare and your server goes through the encrypted tunnel. "Full" is sufficient and avoids certificate validation issues.

### 7.3 (Recommended) Enable Additional Security

In **Cloudflare dashboard** → **Security** → **Settings**:

| Setting | Value |
|---------|-------|
| **Security Level** | **Medium** |
| **Browser Integrity Check** | **On** |

---

## Step 8: Build & Start the Production Stack

### 8.1 Build the Docker Images

```bash
cd /opt/dhiarlink

# Build all images (Dhiarlink backend + dashboard)
docker compose -f docker-compose.prod.yml build
```

This will:
- Build the Dhiarlink backend image (PHP 8.5 + RoadRunner + all extensions)
- Download the latest shlink-web-client release and build the dashboard image
- Pull MySQL 8.0, Redis 7.4, Caddy 2, and cloudflared images

> **First build takes 5-15 minutes** depending on your LXC resources and internet speed.

### 8.2 Start the Stack

```bash
docker compose -f docker-compose.prod.yml up -d
```

### 8.3 Verify All Containers Are Running

```bash
docker compose -f docker-compose.prod.yml ps
```

Expected output — all services should show `Up` or `running`:

```
NAME              STATUS
dhiarlink         Up (healthy)
dhiarlink_db      Up (healthy)
dhiarlink_redis   Up (healthy)
dhiarlink_caddy   Up
dhiarlink_dashboard  Up
cloudflared       Up
```

### 8.4 Check Logs for Errors

```bash
# All services
docker compose -f docker-compose.prod.yml logs -f

# Individual service
docker compose -f docker-compose.prod.yml logs -f dhiarlink
docker compose -f docker-compose.prod.yml logs -f cloudflared
```

---

## Step 9: Initialize Dhiarlink

### 9.1 Run the Database Setup

The Dhiarlink Docker entrypoint automatically runs the installer on first start. Verify it completed:

```bash
docker compose -f docker-compose.prod.yml logs dhiarlink | grep -i "install\|migrat\|ready"
```

If you see migration errors, manually run:

```bash
docker exec -it dhiarlink php vendor/bin/shlink-installer init --no-interaction --clear-db-cache
```

### 9.2 Generate an API Key

If you set `INITIAL_API_KEY` in your `.env`, a key was auto-created. To generate additional keys:

```bash
docker exec -it dhiarlink bin/cli api-key:generate
```

**Save the generated API key** — you'll need it to configure the dashboard.

### 9.3 Verify the Health Endpoint

```bash
# From inside the container
docker exec -it dhiarlink curl -s http://127.0.0.1:8080/rest/health | head

# Or from the host (if you temporarily expose the port for testing)
curl -s http://localhost:8080/rest/health
```

You should see a JSON response with `"status": "pass"`.

---

## Step 10: Verify Everything Works

### 10.1 Landing Page

Open in your browser: **https://dhiarr.qzz.io**

You should see the Dhiarlink landing page with the terminal/hacker theme.

### 10.2 Dashboard

Open: **https://app.dhiarr.qzz.io**

On first visit, the dashboard will ask you to configure a server:
- **Shlink URL:** `https://dhiarr.qzz.io`
- **API Key:** Paste the key generated in Step 9.2

> The dashboard connects to the Dhiarlink REST API through your browser, so it uses the public URL (via Cloudflare Tunnel).

### 10.3 Create a Test Short URL

From the dashboard (or CLI):

```bash
docker exec -it dhiarlink bin/cli short-url:create https://github.com --tags test
```

Note the generated short code. Then visit: **https://link.dhiarr.qzz.io/<short-code>**

You should be redirected to `https://github.com`.

### 10.4 Check Visit Tracking

```bash
docker exec -it dhiarlink bin/cli short-url:visits <short-code>
```

You should see 1 visit recorded.

---

## Optional Features

### GeoLite2 IP Geolocation

Enables visitor location tracking on the visit analytics page.

1. **Get a free MaxMind license key:**
   - Sign up at [https://www.maxmind.com/en/geolite2/signup](https://www.maxmind.com/en/geolite2/signup)
   - Go to **Account** → **Services** → **My License Key** → Generate a new key

2. **Add to `.env`:**
   ```env
   GEOLITE_LICENSE_KEY=your_maxmind_license_key
   ```

3. **Restart Dhiarlink:**
   ```bash
   docker compose -f docker-compose.prod.yml restart dhiarlink
   ```

   The GeoLite2 database will be downloaded automatically on startup.

### Mercure Real-Time Updates

Enables live visit notifications in the dashboard (new visits appear without refreshing).

1. **Add to `docker-compose.prod.yml`** (add a `dhiarlink_mercure` service):

   ```yaml
       dhiarlink_mercure:
           container_name: dhiarlink_mercure
           image: dunglas/mercure:v0.18
           restart: unless-stopped
           environment:
               SERVER_NAME: ":80"
               MERCURE_PUBLISHER_JWT_KEY: ${MERCURE_JWT_SECRET:?MERCURE_JWT_SECRET is required}
               MERCURE_SUBSCRIBER_JWT_KEY: ${MERCURE_JWT_SECRET:?MERCURE_JWT_SECRET is required}
               MERCURE_EXTRA_DIRECTIVES: "cors_origins https://app.dhiarr.qzz.io"
           networks:
               - dhiarlink_internal
   ```

2. **Update Caddy** to route Mercure through the proxy:
   Add to the Caddyfile:
   ```
       @mercure host dhiarr.qzz.io path /mercure*
       handle @mercure {
           reverse_proxy dhiarlink_mercure:80 {
               header_up X-Forwarded-Host {host}
               header_up X-Forwarded-Proto https
           }
       }
   ```

3. **Add to `.env`:**
   ```env
   MERCURE_ENABLED=true
   MERCURE_JWT_SECRET=$(openssl rand -base64 32)
   MERCURE_PUBLIC_HUB_URL=https://dhiarr.qzz.io/mercure
   MERCURE_INTERNAL_HUB_URL=http://dhiarlink_mercure
   ```

4. **Restart:**
   ```bash
   docker compose -f docker-compose.prod.yml up -d --build
   ```

### Matomo Analytics

External analytics platform for detailed visit reports.

1. **Add to `docker-compose.prod.yml`:**
   ```yaml
       dhiarlink_matomo:
           container_name: dhiarlink_matomo
           image: matomo:5.0-apache
           restart: unless-stopped
           volumes:
               - matomo_data:/var/www/html
           environment:
               MATOMO_DATABASE_HOST: dhiarlink_db
               MATOMO_DATABASE_ADAPTER: mysql
               MATOMO_DATABASE_DBNAME: matomo
               MATOMO_DATABASE_USERNAME: root
               MATOMO_DATABASE_PASSWORD: ${DB_ROOT_PASSWORD}
           networks:
               - dhiarlink_internal
   ```
   And add `matomo_data` to the `volumes:` section.

2. **Add to `.env`:**
   ```env
   MATOMO_ENABLED=true
   MATOMO_BASE_URL=https://analytics.dhiarr.qzz.io  # or your Matomo URL
   MATOMO_SITE_ID=1
   MATOMO_API_TOKEN=your_matomo_token
   ```

3. You'll also need to add a CNAME + tunnel hostname for `analytics.dhiarr.qzz.io` if you want to access Matomo's UI.

---

## Updating & Maintenance

### Update Dhiarlink Code

```bash
cd /opt/dhiarlink

# Pull latest changes
git pull

# Rebuild and restart
docker compose -f docker-compose.prod.yml build
docker compose -f docker-compose.prod.yml up -d
```

### Update Docker Images

```bash
# Pull latest base images (MySQL, Redis, Caddy, cloudflared)
docker compose -f docker-compose.prod.yml pull

# Restart with new images
docker compose -f docker-compose.prod.yml up -d
```

### Update shlink-web-client Dashboard

```bash
# Rebuild just the dashboard image (fetches latest release)
docker compose -f docker-compose.prod.yml build dhiarlink_dashboard
docker compose -f docker-compose.prod.yml up -d dhiarlink_dashboard
```

### View Logs

```bash
# All services
docker compose -f docker-compose.prod.yml logs -f

# Specific service
docker compose -f docker-compose.prod.yml logs -f dhiarlink
docker compose -f docker-compose.prod.yml logs -f cloudflared
docker compose -f docker-compose.prod.yml logs -f dhiarlink_db
```

### Restart a Single Service

```bash
docker compose -f docker-compose.prod.yml restart dhiarlink
```

---

## Backup & Restore

### Backup

```bash
# Create a backup directory
mkdir -p ~/dhiarlink-backups
BACKUP_DIR=~/dhiarlink-backups/$(date +%Y%m%d_%H%M%S)
mkdir -p $BACKUP_DIR

# 1. Dump the database
docker exec dhiarlink_db mysqldump -u root -p${DB_ROOT_PASSWORD} dhiarlink > $BACKUP_DIR/dhiarlink.sql

# 2. Backup the .env file (contains secrets!)
cp .env $BACKUP_DIR/.env

# 3. Backup Dhiarlink data (cache, GeoLite DB, etc.)
docker exec dhiarlink tar czf - /etc/dhiarlink/data > $BACKUP_DIR/dhiarlink-data.tar.gz

# 4. Compress the backup
tar czf ~/dhiarlink-backups/backup_$(date +%Y%m%d_%H%M%S).tar.gz -C ~/dhiarlink-backups $(basename $BACKUP_DIR)

echo "Backup saved to: $BACKUP_DIR"
```

### Restore

```bash
# 1. Stop the stack
docker compose -f docker-compose.prod.yml down

# 2. Restore database
docker compose -f docker-compose.prod.yml up -d dhiarlink_db
sleep 10  # Wait for MySQL to be ready
docker exec -i dhiarlink_db mysql -u root -p${DB_ROOT_PASSWORD} dhiarlink < /path/to/backup/dhiarlink.sql

# 3. Restore .env
cp /path/to/backup/.env .env

# 4. Start everything
docker compose -f docker-compose.prod.yml up -d
```

### Automated Backups (Cron)

```bash
# Add a daily backup at 3:00 AM
crontab -e

# Add this line:
0 3 * * * cd /opt/dhiarlink && docker exec dhiarlink_db mysqldump -u root -pYOUR_ROOT_PASSWORD dhiarlink > ~/dhiarlink-backups/db_$(date +\%Y\%m\%d).sql && find ~/dhiarlink-backups -name "db_*.sql" -mtime +30 -delete
```

---

## Troubleshooting

### Container won't start

```bash
# Check if Docker daemon is running
sudo systemctl status docker

# Check container logs
docker compose -f docker-compose.prod.yml logs dhiarlink
docker compose -f docker-compose.prod.yml logs cloudflared

# Check if ports are already in use (shouldn't be since we don't expose any)
sudo ss -tlnp
```

### Cloudflare Tunnel not connecting

```bash
# Check cloudflared logs
docker compose -f docker-compose.prod.yml logs cloudflared

# Verify the token is correct
docker exec cloudflared cloudflared tunnel info

# Re-create the tunnel token if needed (from Cloudflare dashboard)
```

### "Connection refused" from cloudflared to Caddy

The cloudflared container can't reach `dhiarlink_caddy`. This happens when Docker DNS resolution fails.

**Fix 1:** Ensure both are on the same network:
```bash
docker network inspect dhiarlink_dhiarlink_internal
```

**Fix 2:** Use the Caddy container's IP instead:
```bash
docker inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' dhiarlink_caddy
```
Then in the Cloudflare Tunnel dashboard, change the service URL to `http://<caddy-ip>:80`

### Database connection refused

```bash
# Wait for MySQL to be fully ready
docker compose -f docker-compose.prod.yml logs dhiarlink_db | grep "ready for connections"

# Test connection from the Dhiarlink container
docker exec -it dhiarlink sh -c "mysql -h dhiarlink_db -u dhiarlink -p"
```

### Dashboard shows "Could not connect to Shlink"

1. Verify the API URL you entered is `https://dhiarr.qzz.io` (not `http://`)
2. Check the API key is correct
3. Test the API directly:
   ```bash
   curl -H "X-Api-Key: YOUR_KEY" https://dhiarr.qzz.io/rest/health
   ```
4. Check CORS is configured correctly in `.env`:
   ```env
   CORS_ALLOW_ORIGIN=https://app\.dhiarr\.qzz\.io
   ```

### Short URLs return 404

```bash
# Check that migrations ran successfully
docker exec -it dhiarlink bin/cli db:migrate:status

# Check the short URL exists
docker exec -it dhiarlink bin/cli short-url:list
```

### Visit tracking shows no data

```bash
# Check Redis is connected
docker exec dhiarlink_redis redis-cli ping

# Check that tracking is not disabled
docker exec -it dhiarlink bin/cli env-var:read DISABLE_TRACKING
# Should output nothing or "false"
```

### LXC Docker permission issues

If you see permission errors in LXC:

```bash
# Ensure nesting is enabled
# From Proxmox host:
pct set <CTID> --features nesting=1

# Restart the container
pct reboot <CTID>
```

---

## Quick Reference

### Useful Commands

| Command | Description |
|---------|-------------|
| `docker compose -f docker-compose.prod.yml ps` | Check container status |
| `docker compose -f docker-compose.prod.yml logs -f` | Follow all logs |
| `docker exec -it dhiarlink bin/cli` | List CLI commands |
| `docker exec -it dhiarlink bin/cli short-url:list` | List all short URLs |
| `docker exec -it dhiarlink bin/cli short-url:create <url>` | Create a short URL |
| `docker exec -it dhiarlink bin/cli api-key:generate` | Generate a new API key |
| `docker exec -it dhiarlink bin/cli api-key:list` | List API keys |
| `docker exec -it dhiarlink bin/cli db:migrate` | Run pending migrations |
| `docker exec -it dhiarlink bin/cli visit:locate` | Geolocate pending visits |
| `docker compose -f docker-compose.prod.yml restart <service>` | Restart a service |
| `docker compose -f docker-compose.prod.yml down` | Stop all containers |
| `docker compose -f docker-compose.prod.yml up -d --build` | Rebuild and restart |

### URLs

| URL | Purpose |
|-----|---------|
| `https://dhiarr.qzz.io` | Landing page |
| `https://dhiarr.qzz.io/rest/health` | Health check endpoint |
| `https://dhiarr.qzz.io/docs` | API documentation (Swagger) |
| `https://app.dhiarr.qzz.io` | Dashboard (shlink-web-client) |
| `https://link.dhiarr.qzz.io/<code>` | Short URL redirects |

### Files Created/Modified for Production

| File | Purpose |
|------|---------|
| `docker-compose.prod.yml` | Production Docker Compose stack |
| `data/infra/Caddyfile` | Caddy reverse proxy routing rules |
| `data/infra/dashboard.Dockerfile` | Dashboard image build |
| `data/infra/spa-nginx.conf` | Dashboard nginx SPA config |
| `.env` | Your production environment variables (git-ignored) |

---

> This product includes GeoLite2 data created by MaxMind, available from [https://www.maxmind.com](https://www.maxmind.com)
>
> Dhiarlink is a fork of [Shlink](https://shlink.io) by Alejandro Celaya Alastrué.
