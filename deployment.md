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
- [Fixing an Existing Deployment](#fixing-an-existing-deployment)

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
                     ┌──────────────▼──────────────┐
                     │  cloudflared (SYSTEM SERVICE) │  ← Runs on host, NOT in Docker
                     │  on LXC host                 │
                     └──────────────┬──────────────┘
                                    │  (plain HTTP, localhost:3000)
                     ┌──────────────▼──────────────┐
                     │  Docker Network (bridge)      │
                     │                               │
                     │  ┌──────────────────────┐     │
                     │  │       Caddy           │     │  ← Reverse proxy (port 3000→80)
                     │  └────┬────────────┬────┘     │
                     │       │            │          │
                     │  ┌────▼─────────┐ ┌▼──────────────────┐
                     │  │  Dhiarlink   │ │ dhiarlink-web-client│
                     │  │ (RoadRunner) │ │  (React dashboard) │
                     │  │  port 8080   │ │  port 8080         │
                     │  └──┬───────┬──┘ └────────────────────┘
                     │     │       │
                     │  ┌──▼──┐ ┌──▼────┐
                     │  │MySQL│ │Redis  │
                     │  └─────┘ └───────┘
                     └───────────────────────────────┘
```

**Key points:**
- **cloudflared runs as a system service** on the LXC host (not inside Docker)
- Caddy is exposed on host port `3000` — the only port exposed to the host
- cloudflared connects to `http://localhost:3000` (Caddy) on the host
- TLS/HTTPS is terminated at Cloudflare's edge — internal traffic is plain HTTP
- Caddy routes requests to the correct backend based on the hostname
- The database and Redis are only accessible within the Docker network
- The dashboard is built from your own `dhiarlink-web-client` fork (at `../dhiarlink-web-client`)

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

## Step 4: Clone & Prepare the Projects

You need **both** repositories cloned as siblings in the same parent directory.

### 4.1 Clone Both Repositories

```bash
# Choose your preferred parent directory
cd /opt

# Clone the backend
sudo git clone <your-backend-repo-url> dhiarlink
sudo chown -R $USER:$USER dhiarlink

# Clone the dashboard (your fork)
sudo git clone <your-web-client-repo-url> dhiarlink-web-client
sudo chown -R $USER:$USER dhiarlink-web-client
```

The final directory structure should look like:
```
/opt/
├── dhiarlink/              ← Backend (this project)
└── dhiarlink-web-client/   ← Dashboard (your fork)
```

> **Important:** The `docker-compose.prod.yml` builds the dashboard from `../dhiarlink-web-client`, so both repos MUST be siblings in the same parent directory.

### 4.2 Create the Production .env File

```bash
cd /opt/dhiarlink
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

# === Dashboard Pre-configuration ===
# These auto-configure the server connection in the dashboard
# so users don't have to manually add it on first visit.
DHIARLINK_SERVER_URL=https://dhiarr.qzz.io
DHIARLINK_SERVER_API_KEY=<your-generated-api-key>
DHIARLINK_SERVER_NAME=Dhiarlink
DHIARLINK_SERVER_FORWARD_CREDENTIALS=false

# === Application ===
APP_ENV=prod
DHIARLINK_VERSION=1.0.0
DHIARLINK_RUNTIME=rr
```

> **Important:** The `CORS_ALLOW_ORIGIN` value uses regex escaping. The backslashes before the dots (`\.`) are intentional — they match literal dots in the domain name.

> **Note:** `CLOUDFLARE_TUNNEL_TOKEN` is **not needed** in `.env` — cloudflared runs as a system service on the host and is configured separately (Step 6).

---

## Step 6: Cloudflare Tunnel Setup

Since you already have cloudflared installed on your LXC, you'll configure it as a system service that routes to Caddy on `localhost:3000`.

### 6.1 Prerequisites

- A Cloudflare account (free tier works)
- Your domain (`qzz.io`) added to Cloudflare with DNS managed there
- [Cloudflare Zero Trust dashboard](https://one.dash.cloudflare.com/) access
- `cloudflared` installed on your LXC (which you already have)

### 6.2 Create a Tunnel (if not already done)

If you haven't created a tunnel yet:

**Option A: Via Cloudflare Zero Trust Dashboard**
1. Go to **[Cloudflare Zero Trust](https://one.dash.cloudflare.com/)** → **Networks** → **Tunnels**
2. Click **Create a tunnel** → choose **Cloudflared**
3. Name the tunnel: `dhiarlink`
4. Copy the install token and run it on your LXC:
   ```bash
   cloudflared service install <your-tunnel-token>
   ```

**Option B: Via CLI**
```bash
# Authenticate with Cloudflare
cloudflared tunnel login

# Create the tunnel
cloudflared tunnel create dhiarlink
# Note the tunnel ID and credentials file path
```

### 6.3 Configure the Tunnel

Edit your cloudflared config file (typically at `~/.cloudflared/config.yml` or `/etc/cloudflared/config.yml`):

```bash
nano ~/.cloudflared/config.yml
```

**All three hostnames point to `localhost:3000`** (Caddy, exposed from Docker):

```yaml
tunnel: <your-tunnel-id-or-name>
credentials-file: /home/<user>/.cloudflared/<TUNNEL_ID>.json

ingress:
    # Landing page (dhiarr.qzz.io)
    - hostname: dhiarr.qzz.io
      service: http://localhost:3000

    # Dashboard (app.dhiarr.qzz.io)
    - hostname: app.dhiarr.qzz.io
      service: http://localhost:3000

    # Short URL redirects (link.dhiarr.qzz.io)
    - hostname: link.dhiarr.qzz.io
      service: http://localhost:3000

    # Required catch-all
    - service: http_status:404
```

> **Why `localhost:3000`?** Caddy inside Docker is mapped to host port `3000` via the `docker-compose.prod.yml`. Since cloudflared runs on the host (not in Docker), it connects to Caddy via `localhost`.

### 6.4 Configure DNS

```bash
# Point all three subdomains to the tunnel
cloudflared tunnel route dns dhiarlink dhiarr.qzz.io
cloudflared tunnel route dns dhiarlink app.dhiarr.qzz.io
cloudflared tunnel route dns dhiarlink link.dhiarr.qzz.io
```

### 6.5 Enable and Start the Tunnel Service

```bash
# If using dashboard token (Option A), the service is already installed.
# If using CLI (Option B), install as system service:
sudo cloudflared service install
sudo systemctl enable cloudflared
sudo systemctl restart cloudflared
```

### 6.6 Verify the Tunnel

```bash
# Check tunnel status
cloudflared tunnel info dhiarlink

# Check the service is running
sudo systemctl status cloudflared
```

> **If you already had a tunnel configured from the previous guide** (pointing to `http://dhiarlink_caddy:80`), you need to update the service URLs to `http://localhost:3000`. See the [Fixing an Existing Deployment](#fixing-an-existing-deployment) section at the bottom.

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

# Build all images (Dhiarlink backend + dashboard from your fork)
docker compose -f docker-compose.prod.yml build
```

This will:
- Build the Dhiarlink backend image (PHP 8.5 + RoadRunner + all extensions)
- Build the dashboard image from your `../dhiarlink-web-client` fork (Node.js build + nginx)
- Pull MySQL 8.0, Redis 7.4, and Caddy 2 images

> **First build takes 10-20 minutes** depending on your LXC resources and internet speed. The dashboard build includes `npm ci` and Vite production build which can be slow.

### 8.2 Start the Stack

```bash
docker compose -f docker-compose.prod.yml up -d
```

### 8.3 Verify All Containers Are Running

```bash
docker compose -f docker-compose.prod.yml ps
```

Expected output — all 5 services should show `Up` or `running`:

```
NAME                 STATUS
dhiarlink            Up (healthy)
dhiarlink_db         Up (healthy)
dhiarlink_redis      Up (healthy)
dhiarlink_caddy      Up
dhiarlink_dashboard  Up
```

> Note: There is no `cloudflared` container — it runs as a system service on the host.

### 8.4 Check Logs for Errors

```bash
# All Docker services
docker compose -f docker-compose.prod.yml logs -f

# Individual service
docker compose -f docker-compose.prod.yml logs -f dhiarlink
docker compose -f docker-compose.prod.yml logs -f dhiarlink_dashboard

# cloudflared (system service on host)
sudo journalctl -u cloudflared -f
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

# Or through Caddy from the host (via the landing page hostname)
curl -s -H "Host: dhiarr.qzz.io" http://localhost:3000/rest/health
```

You should see a JSON response with `"status": "pass"`.

---

## Step 10: Verify Everything Works

### 10.1 Landing Page

Open in your browser: **https://dhiarr.qzz.io**

You should see the Dhiarlink landing page with the terminal/hacker theme.

### 10.2 Dashboard

Open: **https://app.dhiarr.qzz.io**

If you set `DHIARLINK_SERVER_API_KEY` in your `.env`, the dashboard is **already pre-configured** with your Dhiarlink server. It should connect automatically and show the dashboard interface.

If you didn't pre-configure, the dashboard will ask you to add a server:
- **URL:** `https://dhiarr.qzz.io`
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
# Pull latest base images (MySQL, Redis, Caddy)
docker compose -f docker-compose.prod.yml pull

# Restart with new images
docker compose -f docker-compose.prod.yml up -d
```

### Update dhiarlink-web-client Dashboard

```bash
# Pull latest changes in the dashboard repo
cd /opt/dhiarlink-web-client
git pull

# Rebuild and restart just the dashboard container
cd /opt/dhiarlink
docker compose -f docker-compose.prod.yml build dhiarlink_dashboard
docker compose -f docker-compose.prod.yml up -d dhiarlink_dashboard
```

### View Logs

```bash
# All Docker services
docker compose -f docker-compose.prod.yml logs -f

# Specific Docker service
docker compose -f docker-compose.prod.yml logs -f dhiarlink
docker compose -f docker-compose.prod.yml logs -f dhiarlink_dashboard
docker compose -f docker-compose.prod.yml logs -f dhiarlink_db

# Cloudflared (system service on host)
sudo journalctl -u cloudflared -f
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
docker compose -f docker-compose.prod.yml logs dhiarlink_dashboard

# Check if port 3000 is already in use (Caddy's host port)
sudo ss -tlnp | grep 3000
```

### Cloudflare Tunnel not connecting

```bash
# Check cloudflared system service logs
sudo journalctl -u cloudflared -f

# Check tunnel status
cloudflared tunnel info dhiarlink

# Restart the tunnel service
sudo systemctl restart cloudflared

# Verify Caddy is reachable from the host
curl -s http://localhost:3000
```

### "Connection refused" from cloudflared to Caddy

The host's cloudflared can't reach Caddy at `localhost:3000`. This means the Caddy container's port mapping isn't working.

**Fix 1:** Verify Caddy is running and port 3000 is mapped:
```bash
docker compose -f docker-compose.prod.yml ps dhiarlink_caddy
# Should show: 0.0.0.0:3000->80/tcp
```

**Fix 2:** Check if port 3000 is already in use:
```bash
sudo ss -tlnp | grep 3000
```

**Fix 3:** Restart Caddy:
```bash
docker compose -f docker-compose.prod.yml restart dhiarlink_caddy
```

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
| `https://app.dhiarr.qzz.io` | Dashboard (dhiarlink-web-client) |
| `https://link.dhiarr.qzz.io/<code>` | Short URL redirects |

### Files Created/Modified for Production

| File | Purpose |
|------|--------|
| `docker-compose.prod.yml` | Production Docker Compose stack (5 services) |
| `data/infra/Caddyfile` | Caddy reverse proxy routing rules |
| `../dhiarlink-web-client/` | Dashboard built from your fork (sibling directory) |
| `.env` | Your production environment variables (git-ignored) |
| `~/.cloudflared/config.yml` | Cloudflare Tunnel config (on LXC host) |

---

## Fixing an Existing Deployment

If you already ran the previous version of this guide (which used a cloudflared Docker container and shlink-web-client), here's what you need to change on your LXC:

### Step 1: Stop the Old Stack

```bash
cd /opt/dhiarlink
docker compose -f docker-compose.prod.yml down
```

### Step 2: Pull the Updated Files

```bash
cd /opt/dhiarlink
git pull
```

This brings in the updated `docker-compose.prod.yml`, `Caddyfile`, and `.env.example`.

### Step 3: Update Your .env

Add these new variables to your `.env` (and remove `CLOUDFLARE_TUNNEL_TOKEN`):

```env
# REMOVE this line (no longer needed — cloudflared is a system service now):
# CLOUDFLARE_TUNNEL_TOKEN=...

# ADD these lines (dashboard pre-configuration):
DHIARLINK_SERVER_URL=https://dhiarr.qzz.io
DHIARLINK_SERVER_API_KEY=<your-api-key>
DHIARLINK_SERVER_NAME=Dhiarlink
DHIARLINK_SERVER_FORWARD_CREDENTIALS=false
```

### Step 4: Update Cloudflare Tunnel Config

If your tunnel was configured with Docker container hostnames like `http://dhiarlink_caddy:80`, update them to use `localhost:3000`:

**If using Zero Trust Dashboard:**
1. Go to Cloudflare Zero Trust → Networks → Tunnels → your tunnel
2. Edit each public hostname's Service URL from `http://dhiarlink_caddy:80` to `http://localhost:3000`

**If using local config file (`~/.cloudflared/config.yml`):**
```yaml
ingress:
    - hostname: dhiarr.qzz.io
      service: http://localhost:3000     # ← was http://dhiarlink_caddy:80
    - hostname: app.dhiarr.qzz.io
      service: http://localhost:3000
    - hostname: link.dhiarr.qzz.io
      service: http://localhost:3000
    - service: http_status:404
```

Then restart cloudflared:
```bash
sudo systemctl restart cloudflared
```

### Step 5: Rebuild and Start

```bash
cd /opt/dhiarlink

# Clean up old images
docker compose -f docker-compose.prod.yml down --rmi local

# Rebuild everything (includes building dashboard from ../dhiarlink-web-client)
docker compose -f docker-compose.prod.yml build
docker compose -f docker-compose.prod.yml up -d
```

### Step 6: Verify

```bash
# Check all containers are running
docker compose -f docker-compose.prod.yml ps

# Check Caddy is accessible from the host
curl -s http://localhost:3000

# Check cloudflared is connected
sudo systemctl status cloudflared
```

Then test all three URLs in your browser:
- https://dhiarr.qzz.io (landing page)
- https://app.dhiarr.qzz.io (dashboard — should be pre-configured)
- https://link.dhiarr.qzz.io/<any-short-code> (redirect)

---

> This product includes GeoLite2 data created by MaxMind, available from [https://www.maxmind.com](https://www.maxmind.com)
>
> Dhiarlink is a fork of [Shlink](https://shlink.io) by Alejandro Celaya Alastrué.
