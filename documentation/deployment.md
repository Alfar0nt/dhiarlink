# Dhiarlink — Production Deployment Guide

**Target environment:** Proxmox LXC container OR Linux Machine running Debian/Ubuntu + Cloudflare Tunnel (no public IP required)

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
- [Migrating from Docker to Bare Metal](#migrating-from-docker-to-bare-metal)
- [Bare Metal Deployment (No Docker)](#bare-metal-deployment-no-docker)

---

## Architecture Overview

```
                          Cloudflare Edge (TLS termination)
                          ├── www.dhiarr.qzz.io   (landing page)
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

## Step 1: Proxmox LXC Container Setup - You can jump to [step 2](#step-2-system-preparation) if you're not using proxmox.

### 1.1 Create the LXC Container

From the Proxmox web UI or CLI, create a new LXC container with these settings:

| Setting | Recommended Value |
|---------|-------------------|
| **Template** | Debian 13 (Trixie) |
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
CORS_ALLOW_ORIGIN=https://app.dhiarr.qzz.io

# === Dashboard Pre-configuration ===
# These auto-configure the server connection in the dashboard
# so users don't have to manually add it on first visit.
DHIARLINK_SERVER_URL=https://www.dhiarr.qzz.io
DHIARLINK_SERVER_API_KEY=<your-generated-api-key>
DHIARLINK_SERVER_NAME=Dhiarlink
DHIARLINK_SERVER_FORWARD_CREDENTIALS=false

# === Application ===
APP_ENV=prod
DHIARLINK_VERSION=1.0.0
DHIARLINK_RUNTIME=rr
```

> **Important:** The `CORS_ALLOW_ORIGIN` value is an exact match (or comma-separated list of origins) without regex escaping. Do NOT include backslashes before the dots.

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
    # Landing page (www.dhiarr.qzz.io)
    - hostname: www.dhiarr.qzz.io
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
cloudflared tunnel route dns dhiarlink www.dhiarr.qzz.io
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
curl -s -H "Host: www.dhiarr.qzz.io" http://localhost:3000/rest/health
```

You should see a JSON response with `"status": "pass"`.

---

## Step 10: Verify Everything Works

### 10.1 Landing Page

Open in your browser: **https://www.dhiarr.qzz.io**

You should see the Dhiarlink landing page with the terminal/hacker theme.

### 10.2 Dashboard

Open: **https://app.dhiarr.qzz.io**

If you set `DHIARLINK_SERVER_API_KEY` in your `.env`, the dashboard is **already pre-configured** with your Dhiarlink server. It should connect automatically and show the dashboard interface.

If you didn't pre-configure, the dashboard will ask you to add a server:
- **URL:** `https://www.dhiarr.qzz.io`
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
       @mercure host www.dhiarr.qzz.io path /mercure*
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
   MERCURE_PUBLIC_HUB_URL=https://www.dhiarr.qzz.io/mercure
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

1. Verify the API URL you entered is `https://www.dhiarr.qzz.io` (not `http://`)
2. Check the API key is correct
3. Test the API directly:
   ```bash
   curl -H "X-Api-Key: YOUR_KEY" https://www.dhiarr.qzz.io/rest/health
   ```
4. Check CORS is configured correctly in `.env`:
   ```env
   CORS_ALLOW_ORIGIN=https://app.dhiarr.qzz.io
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
| `https://www.dhiarr.qzz.io` | Landing page |
| `https://www.dhiarr.qzz.io/rest/health` | Health check endpoint |
| `https://www.dhiarr.qzz.io/docs` | API documentation (Swagger) |
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
DHIARLINK_SERVER_URL=https://www.dhiarr.qzz.io
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
    - hostname: www.dhiarr.qzz.io
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
- https://www.dhiarr.qzz.io (landing page)
- https://app.dhiarr.qzz.io (dashboard — should be pre-configured)
- https://link.dhiarr.qzz.io/<any-short-code> (redirect)

---

## Migrating from Docker to Bare Metal

If you currently run Dhiarlink via Docker and want to switch to bare metal for raw performance, follow these steps to cleanly migrate without data loss.

### Step 1: Backup Your Data

```bash
cd /opt/dhiarlink

# Backup the database
docker exec dhiarlink_db mysqldump -u root -p${DB_ROOT_PASSWORD} dhiarlink > ~/dhiarlink-full-backup.sql

# Backup your .env file (contains secrets!)
cp .env ~/dhiarlink.env.backup

# Backup GeoLite2 database (if downloaded)
docker exec dhiarlink tar czf - /etc/dhiarlink/data/GeoLite2-City.mmdb > ~/geolite-backup.tar.gz 2>/dev/null || true
```

### Step 2: Stop the Docker Stack

```bash
cd /opt/dhiarlink

# Stop all containers
docker compose -f docker-compose.prod.yml down

# Remove containers, images, and volumes (irreversible!)
docker compose -f docker-compose.prod.yml down --rmi all -v

# Verify nothing is left
docker ps -a --filter "name=dhiarlink"
docker volume ls --filter "name=dhiarlink"
```

### Step 3: (Optional) Uninstall Docker

If you no longer need Docker on this machine:

```bash
# Stop Docker daemon
sudo systemctl stop docker
sudo systemctl disable docker

# Remove Docker packages
sudo apt remove -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin

# Remove Docker data
sudo rm -rf /var/lib/docker
sudo rm -rf /var/lib/containerd
sudo rm -rf /etc/docker

# Remove Docker group
sudo groupdel docker 2>/dev/null || true

# Clean up unused packages
sudo apt autoremove -y
```

### Step 4: Set Up Bare Metal

Follow the complete [Bare Metal Deployment](#bare-metal-deployment-no-docker) guide below to set up your fresh environment. It covers everything from scratch — PHP, MariaDB, Redis, RoadRunner, Caddy, and the dashboard.

---

## Bare Metal Deployment (No Docker)

This guide deploys Dhiarlink natively on a Debian 13 server from scratch — no Docker, no containers, no prior installation needed. All services run directly on the host for maximum raw performance.

**Target environment:** Debian 13 (Trixie) + Cloudflare Tunnel

> **Fresh setup:** This guide assumes a clean server. Every step is self-contained — you don't need an existing Docker installation or any prior Dhiarlink data.

### Architecture

```
                          Cloudflare Edge (TLS termination)
                          ├── www.dhiarr.qzz.io   (landing page)
                          ├── app.dhiarr.qzz.io   (dashboard UI)
                          └── link.dhiarr.qzz.io  (short URL redirects)
                                    │
                          Cloudflare Tunnel (encrypted)
                                    │
                     ┌──────────────▼──────────────┐
                     │  cloudflared (SYSTEM SERVICE) │
                     └──────────────┬──────────────┘
                                    │  (plain HTTP, localhost:3000)
                     ┌──────────────▼──────────────┐
                     │  Caddy 2 (port 3000)         │
                     │  Reverse proxy + compression │
                     └────┬────────────┬───────────┘
                          │            │
                     ┌────▼──────┐ ┌───▼─────────────┐
                     │ RoadRunner │ │ nginx (port 8081)│
                     │ (port 8080)│ │ Dashboard SPA    │
                     └──┬──────┬─┘ └──────────────────┘
                        │      │
                     ┌───▼───┐ ┌─▼─────┐
                     │MariaDB│ │Redis  │
                     └───────┘ └───────┘
```

All services run natively on the same host — no containerization overhead.

### System Requirements

| Resource | Minimum | Recommended |
|----------|---------|-------------|
| **CPU** | 2 cores | 4 cores |
| **RAM** | 2 GB | 4 GB |
| **Disk** | 20 GB SSD | 40 GB SSD |
| **OS** | Debian 13 (Trixie) | Debian 13 |

### Dependencies Overview

| Software | Version | Purpose |
|----------|---------|---------|
| PHP | 8.4 | Application runtime |
| RoadRunner | Latest | High-performance PHP app server |
| MariaDB | 11.x | Database (MySQL-compatible) |
| Redis | 7.4 | Caching + pub/sub |
| Caddy | 2.x | Reverse proxy + compression |
| Composer | 2.x | PHP dependency manager |
| Node.js | 20+ LTS | Dashboard build (one-time) |
| nginx | 1.25+ | Dashboard static file serving |
| cloudflared | Latest | Cloudflare Tunnel (already installed) |

---

### Step 1: System Preparation

```bash
# Update the system
sudo apt update && sudo apt upgrade -y

# Install essential packages
sudo apt install -y curl wget gnupg ca-certificates lsb-release git unzip nano htop

# Set timezone
sudo timedatectl set-timezone Asia/Jakarta

# Create a dedicated user
sudo adduser --disabled-password dhiarlink
sudo mkdir -p /opt/dhiarlink
sudo chown dhiarlink:dhiarlink /opt/dhiarlink
```

---

### Step 2: Install PHP 8.4

PHP 8.4 is available in Debian 13's standard repositories — no third-party PPA needed.

```bash
# Install PHP and all required extensions
sudo apt install -y \
    php8.4-cli \
    php8.4-curl \
    php8.4-mbstring \
    php8.4-intl \
    php8.4-bcmath \
    php8.4-sockets \
    php8.4-zip \
    php8.4-calendar \
    php8.4-mysql \
    php8.4-pgsql \
    php8.4-sqlite3 \
    php8.4-apcu \
    php8.4-opcache \
    php8.4-xml
```

#### Configure PHP for Production

Create a production php.ini override:

```bash
sudo nano /etc/php/8.4/cli/conf.d/99-dhiarlink.ini
```

```ini
; --- Error Handling ---
display_errors=Off
display_startup_errors=Off
log_errors=On
error_reporting=E_ALL & ~E_DEPRECATED & ~E_STRICT

; --- Assertions (disabled in production) ---
zend.assertions=-1
assert.exception=0

; --- OPcache (critical for performance) ---
opcache.enable=1
opcache.enable_cli=1
opcache.memory_consumption=256
opcache.max_accelerated_files=20000
opcache.validate_timestamps=0
opcache.interned_strings_buffer=16
opcache.save_comments=1
opcache.preload=/opt/dhiarlink/config/opcache-preload.php
opcache.preload_user=dhiarlink

; --- APCu (in-process metadata cache) ---
apc.enabled=1
apc.enable_cli=1
apc.shm_size=64M
apc.entries_hint=10000
apc.ttl=7200

; --- Memory & Execution ---
memory_limit=256M
max_execution_time=30
realpath_cache_size=4096K
realpath_cache_ttl=600
```

> **Important — ordering note:** The `opcache.preload` line references `/opt/dhiarlink/config/opcache-preload.php`, which doesn't exist until you clone the repo in Step 6. **Comment out the two preload lines** for now by adding `;` at the start:
> ```ini
> ;opcache.preload=/opt/dhiarlink/config/opcache-preload.php
> ;opcache.preload_user=dhiarlink
> ```
> You'll uncomment them after cloning the repo in Step 6.

Verify:

```bash
php -v
php -m | grep -E 'opcache|apcu|pdo_mysql|intl|mbstring|curl|sockets|bcmath|zip|calendar'
```

---

### Step 3: Install and Configure MariaDB

MariaDB is the default MySQL-compatible database on Debian 13. It works identically with Dhiarlink — same `pdo_mysql` driver, same SQL, same Doctrine ORM.

```bash
# Install MariaDB server
sudo apt install -y mariadb-server

# Secure the installation
sudo mariadb-secure-installation
```

During `mariadb-secure-installation`, answer:
- Enter current password for root: **press Enter** (none set by default)
- Set root password: **Yes** — choose a strong password and save it somewhere safe
- Switch to unix_socket authentication: **No** (keep password-based auth)
- Remove anonymous users: **Yes**
- Disallow root login remotely: **Yes**
- Remove test database: **Yes**
- Reload privilege tables: **Yes**

#### Create the Dhiarlink Database and User

```bash
sudo mariadb -u root -p
```

Run these SQL commands to create the database, user, and grant permissions:

```sql
-- Create the database with proper UTF-8 encoding
CREATE DATABASE dhiarlink CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Create a dedicated user (replace YOUR_SECURE_PASSWORD with a real password)
CREATE USER 'dhiarlink'@'localhost' IDENTIFIED BY 'YOUR_SECURE_PASSWORD';

-- Grant full privileges on the dhiarlink database only
GRANT ALL PRIVILEGES ON dhiarlink.* TO 'dhiarlink'@'localhost';

-- Apply changes
FLUSH PRIVILEGES;

-- Verify the database and user exist
SHOW DATABASES;
SELECT user, host FROM mysql.user WHERE user = 'dhiarlink';

EXIT;
```

> **Important:** Write down the `dhiarlink` user password — you'll need it for the `.env` file in Step 6.

#### Verify Database Access

Test that the new user can connect and access the database:

```bash
mariadb -u dhiarlink -p -e "SHOW DATABASES;"
# Enter the dhiarlink user password when prompted
# Should show: information_schema, dhiarlink
```

#### Tune MariaDB for Performance

```bash
sudo nano /etc/mysql/mariadb.conf.d/50-server.cnf
```

Add or modify these settings under `[mysqld]`:

```ini
[mysqld]
# InnoDB tuning
innodb_buffer_pool_size = 256M
innodb_log_file_size = 64M
innodb_flush_log_at_trx_commit = 2
innodb_flush_method = O_DIRECT

# Connection limits
max_connections = 100

# Character set
character-set-server = utf8mb4
collation-server = utf8mb4_unicode_ci

# Logging
slow_query_log = 1
slow_query_log_file = /var/log/mysql/slow.log
long_query_time = 2
```

```bash
sudo systemctl restart mariadb
sudo systemctl enable mariadb

# Verify MariaDB is running
sudo systemctl status mariadb
```

---

### Step 4: Install Redis 7.4

```bash
# Install Redis
sudo apt install -y redis-server

# Configure Redis
sudo nano /etc/redis/redis.conf
```

Key settings to modify:

```ini
# Bind to localhost only
bind 127.0.0.1

# Memory management
maxmemory 128mb
maxmemory-policy allkeys-lru

# Persistence (RDB snapshots)
save 900 1
save 300 10
save 60 10000

# Disable protected mode since we're bound to localhost
protected-mode yes
```

```bash
sudo systemctl restart redis-server
sudo systemctl enable redis-server

# Verify
redis-cli ping
# Should respond: PONG
```

---

### Step 5: Install Composer

```bash
# Download and install Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# Verify
composer --version
```

---

### Step 6: Clone and Set Up Dhiarlink

```bash
# Clone the repository
sudo git clone <your-backend-repo-url> /opt/dhiarlink
sudo chown -R dhiarlink:dhiarlink /opt/dhiarlink

# Switch to the dhiarlink user
sudo su - dhiarlink
cd /opt/dhiarlink

# Install PHP dependencies (production only, optimized autoloader)
composer install --no-dev --prefer-dist --optimize-autoloader --no-progress --no-interaction

# Generate the optimized class map (required for OPcache preloading)
composer dump-autoload --optimize --classmap-authoritative
```

#### Enable OPcache Preloading

Now that the repo is cloned, uncomment the preload lines in the PHP config:

```bash
# Exit back to your sudo user first
exit

# Uncomment the preload directives
sudo sed -i 's/^;opcache.preload=/opcache.preload=/' /etc/php/8.4/cli/conf.d/99-dhiarlink.ini
sudo sed -i 's/^;opcache.preload_user=/opcache.preload_user=/' /etc/php/8.4/cli/conf.d/99-dhiarlink.ini

# Verify
php -v  # Should run without preload errors
```

#### Create the .env File

```bash
cp .env.example .env
nano .env
```

Key differences from Docker — update these values:

```env
# Database: localhost instead of Docker hostname
DB_HOST=localhost
DB_USER=dhiarlink
DB_PASSWORD=YOUR_SECURE_PASSWORD

# Redis: localhost instead of Docker hostname
REDIS_SERVERS=tcp://127.0.0.1:6379

# Everything else stays the same
DEFAULT_DOMAIN=link.dhiarr.qzz.io
IS_HTTPS_ENABLED=true
TRUSTED_PROXIES=1
CORS_ALLOW_ORIGIN=https://app.dhiarr.qzz.io
APP_ENV=prod
DHIARLINK_RUNTIME=rr

# RoadRunner workers (required — no defaults in .rr.yml)
WEB_WORKER_NUM=0          # 0 = auto-detect CPU cores
TASK_WORKER_NUM=0         # 0 = auto-detect CPU cores
LOGS_FORMAT=console       # 'console' works with systemd journal; 'json' causes write failures on bare metal

# Dashboard pre-config
DHIARLINK_SERVER_URL=https://www.dhiarr.qzz.io
DHIARLINK_SERVER_API_KEY=YOUR_API_KEY
DHIARLINK_SERVER_NAME=Dhiarlink
DHIARLINK_SERVER_FORWARD_CREDENTIALS=false
```

#### Initialize the Database

The installer will create all tables, indexes, and the database schema in the empty `dhiarlink` database you created in Step 3.

```bash
# Create data directories
mkdir -p data/cache data/locks data/log data/proxies data/temp-geolite

# Run the installer — this creates the full schema and runs all migrations
php vendor/bin/shlink-installer init --no-interaction --clear-db-cache

# Generate your first API key (needed for the dashboard)
bin/cli api-key:generate
```

> **Tip:** Copy the generated API key — you'll need it for the `DHIARLINK_SERVER_API_KEY` in the dashboard configuration.

#### Download RoadRunner Binary

```bash
# Download RoadRunner using the Composer helper
php vendor/bin/rr get --no-interaction --no-config --location bin/
chmod +x bin/rr

# Verify
bin/rr --version
```

#### Test the Server

```bash
# Start RoadRunner manually to test (Ctrl+C to stop)
bin/rr serve -c config/roadrunner/.rr.yml

# In another terminal, test the health endpoint
curl -s http://127.0.0.1:8080/rest/health
# Should return: {"status":"pass",...}
```

---

### Step 7: Install RoadRunner as a Systemd Service

```bash
# Exit back to your sudo user
exit

# Copy the service file
sudo cp /opt/dhiarlink/data/infra/systemd/dhiarlink.service /etc/systemd/system/

# Reload systemd and enable the service
sudo systemctl daemon-reload
sudo systemctl enable --now dhiarlink

# Verify it's running
sudo systemctl status dhiarlink
sudo journalctl -u dhiarlink -f
```

#### Service Management Commands

```bash
sudo systemctl start dhiarlink      # Start
sudo systemctl stop dhiarlink       # Stop
sudo systemctl restart dhiarlink    # Restart
sudo systemctl status dhiarlink     # Check status
sudo journalctl -u dhiarlink -f     # Follow logs
sudo journalctl -u dhiarlink --since "1 hour ago"  # Recent logs
```

---

### Step 8: Install and Configure Caddy

```bash
# Install Caddy from the official repository
sudo apt install -y debian-keyring debian-archive-keyring apt-transport-https curl
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' | sudo gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' | sudo tee /etc/apt/sources.list.d/caddy-stable.list
sudo apt update
sudo apt install -y caddy

# Copy the bare metal Caddyfile
sudo cp /opt/dhiarlink/data/infra/Caddyfile.bare-metal /etc/caddy/Caddyfile

# Restart Caddy
sudo systemctl restart caddy
sudo systemctl enable caddy

# Verify Caddy is listening on port 3000
sudo ss -tlnp | grep 3000

# Test through Caddy
curl -s -H "Host: www.dhiarr.qzz.io" http://localhost:3000/rest/health
```

---

### Step 9: Build and Serve the Dashboard

The dashboard (dhiarlink-web-client) is a React app that needs to be built once and served as static files.

#### Clone and Build

```bash
# Clone the dashboard repo
sudo git clone <your-web-client-repo-url> /opt/dhiarlink-web-client
sudo chown -R dhiarlink:dhiarlink /opt/dhiarlink-web-client

# Install Node.js (if not already installed)
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt install -y nodejs

# Build the dashboard
sudo su - dhiarlink
cd /opt/dhiarlink-web-client
npm ci
npm run build
exit
```

#### Serve with nginx

```bash
# Install nginx
sudo apt install -y nginx

# Create nginx config for the dashboard
sudo nano /etc/nginx/sites-available/dhiarlink-dashboard
```

```nginx
server {
    listen 127.0.0.1:8081;
    server_name app.dhiarr.qzz.io;

    root /opt/dhiarlink-web-client/dist;
    index index.html;

    # SPA fallback — all routes serve index.html
    location / {
        try_files $uri $uri/ /index.html;
    }

    # Cache static assets
    location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }
}
```

```bash
# Enable the site
sudo ln -s /etc/nginx/sites-available/dhiarlink-dashboard /etc/nginx/sites-enabled/
sudo nginx -t  # Test configuration
sudo systemctl restart nginx
sudo systemctl enable nginx

# Verify dashboard is served
curl -s http://127.0.0.1:8081 | head -5
```

---

### Step 10: Configure Cloudflare Tunnel

cloudflared should already be installed as a system service. Update its config to point to Caddy on port 3000:

```bash
nano ~/.cloudflared/config.yml
```

```yaml
tunnel: <your-tunnel-id>
credentials-file: /home/<user>/.cloudflared/<TUNNEL_ID>.json

ingress:
    - hostname: www.dhiarr.qzz.io
      service: http://localhost:3000
    - hostname: app.dhiarr.qzz.io
      service: http://localhost:3000
    - hostname: link.dhiarr.qzz.io
      service: http://localhost:3000
    - service: http_status:404
```

```bash
sudo systemctl restart cloudflared
sudo systemctl status cloudflared
```

---

### Step 11: Verify Everything Works

```bash
# 1. Check all services are running
sudo systemctl status dhiarlink     # RoadRunner
sudo systemctl status mariadb      # Database
sudo systemctl status redis-server  # Cache
sudo systemctl status caddy         # Reverse proxy
sudo systemctl status nginx         # Dashboard
sudo systemctl status cloudflared   # Tunnel

# 2. Test the health endpoint through Caddy
curl -s -H "Host: www.dhiarr.qzz.io" http://localhost:3000/rest/health

# 3. Create a test short URL
sudo su - dhiarlink
cd /opt/dhiarlink
bin/cli short-url:create https://github.com --tags test

# 4. Test the redirect
curl -I -H "Host: link.dhiarr.qzz.io" http://localhost:3000/<short-code>
# Should return: HTTP/1.1 302 Found, Location: https://github.com
```

Then test all three URLs in your browser:
- **https://www.dhiarr.qzz.io** — Landing page
- **https://app.dhiarr.qzz.io** — Dashboard (pre-configured)
- **https://link.dhiarr.qzz.io/<short-code>** — Redirect

---

### Bare Metal Maintenance

#### Updating Dhiarlink

```bash
sudo su - dhiarlink
cd /opt/dhiarlink

# Pull latest changes
git pull

# Update dependencies
composer install --no-dev --prefer-dist --optimize-autoloader --no-progress

# Regenerate optimized class map
composer dump-autoload --optimize --classmap-authoritative

# Run any pending database migrations
bin/cli db:migrate

exit

# Restart RoadRunner to pick up changes
sudo systemctl restart dhiarlink
```

#### Updating the Dashboard

```bash
sudo su - dhiarlink
cd /opt/dhiarlink-web-client
git pull
npm ci
npm run build
exit

# No nginx restart needed — static files are updated
```

#### Viewing Logs

```bash
# RoadRunner (Dhiarlink)
sudo journalctl -u dhiarlink -f

# Caddy
sudo journalctl -u caddy -f

# MariaDB
sudo journalctl -u mariadb -f

# Redis
sudo journalctl -u redis-server -f

# cloudflared
sudo journalctl -u cloudflared -f

# nginx
sudo journalctl -u nginx -f
sudo tail -f /var/log/nginx/error.log
```

#### Backup and Restore

```bash
# Backup database
mysqldump -u root -p dhiarlink > ~/dhiarlink-backup-$(date +%Y%m%d).sql

# Backup .env and GeoLite DB
cp /opt/dhiarlink/.env ~/dhiarlink-env-backup
cp /opt/dhiarlink/data/GeoLite2-City.mmdb ~/geolite-backup 2>/dev/null || true

# Automated daily backup via cron
crontab -e
# Add:
# 0 3 * * * mysqldump -u root -pYOUR_PASSWORD dhiarlink > ~/dhiarlink-backups/db_$(date +\%Y\%m\%d).sql && find ~/dhiarlink-backups -name "db_*.sql" -mtime +30 -delete
```

#### Performance Tuning Checklist

| Component | Setting | Value | File |
|-----------|---------|-------|------|
| **PHP OPcache** | `opcache.memory_consumption` | `256` | `/etc/php/8.4/cli/conf.d/99-dhiarlink.ini` |
| **PHP OPcache** | `opcache.validate_timestamps` | `0` | Same file |
| **PHP OPcache** | `opcache.preload` | `/opt/dhiarlink/config/opcache-preload.php` | Same file |
| **PHP APCu** | `apc.shm_size` | `64M` | Same file |
| **MariaDB** | `innodb_buffer_pool_size` | `256M` | `/etc/mysql/mariadb.conf.d/50-server.cnf` |
| **MariaDB** | `max_connections` | `100` | Same file |
| **Redis** | `maxmemory` | `128mb` | `/etc/redis/redis.conf` |
| **Redis** | `maxmemory-policy` | `allkeys-lru` | Same file |
| **RoadRunner** | `WEB_WORKER_NUM` | `0` (auto) | `/opt/dhiarlink/.env` |
| **RoadRunner** | `max_jobs` | `500` | `config/roadrunner/.rr.yml` |

---

> This product includes GeoLite2 data created by MaxMind, available from [https://www.maxmind.com](https://www.maxmind.com)
>
> Dhiarlink is a fork of [Shlink](https://shlink.io) by Alejandro Celaya Alastrué.
