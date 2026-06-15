# Dhiarlink Development — Prompt History

> A chronological record of all development prompts, questions, decisions, and outcomes across every session working on the Dhiarlink project.

---

## Table of Contents

- [Session 1: Rebranding & Core Improvements](#session-1-rebranding--core-improvements)
- [Session 2: Documentation & Deployment Infrastructure](#session-2-documentation--deployment-infrastructure)
- [Session 3: Deployment Debugging & Hostname Migration](#session-3-deployment-debugging--hostname-migration)
- [Session 4: Documentation Overhaul](#session-4-documentation-overhaul)
- [Session 5: Landing Page Mobile Fix & Social Links](#session-5-landing-page-mobile-fix--social-links)
- [Session 6: Performance Optimization & Bare Metal Deployment](#session-6-performance-optimization--bare-metal-deployment)
- [Session 7: Fresh Bare Metal Guide & Docker Migration Cleanup](#session-7-fresh-bare-metal-guide--docker-migration-cleanup)
- [Session 8: Bare Metal Deployment Debugging & Stabilization](#session-8-bare-metal-deployment-debugging--stabilization)
- [Session 9: Bare Metal Post-Deployment Debugging](#session-9-bare-metal-post-deployment-debugging)

---

## Session 1: Rebranding & Core Improvements

### Prompt 1: Clone & Rebrand Shlink to Dhiarlink

**User asked:** Clone the Shlink URL shortener project and rebrand it from "Shlink" to "Dhiarlink" — updating all cosmetic and user-facing references while preserving PHP namespaces and Composer dependencies for upstream compatibility.

**Outcome — Section 1: Core Branding (18 items completed):**

| Area | Changes |
|------|---------|
| App Identity | `AppOptions.php` name → `Dhiarlink`, `composer.json` description/homepage, `Dockerfile` env vars → `DHIARLINK_*` |
| HTML Templates | `404.html` and `invalid-short-code.html` rewritten with terminal-dark-green theme and Dhiarlink branding |
| REST API | `HealthAction.php` links → `https://dhiarr.qzz.io` |
| API Docs | `swagger.json` title → `Dhiarlink`, description updated |
| Docker/Infra | All container names → `dhiarlink_*`, RoadRunner pipeline → `dhiarlink`, dev env config renamed |
| Static Assets | New terminal-prompt SVG favicon (`>_` in teal) |
| Documentation | README, DEVELOPING.md, CHANGELOG.md updated |

**Key decision:** PHP namespaces (`Shlinkio\Shlink\*`), Composer package names, and internal class names were intentionally left unchanged to maintain upstream compatibility with Shlink packages.

---

### Prompt 2: Performance, Speed & UX Improvements

**User asked:** Add performance optimizations, security hardening, and UX improvements to the Dhiarlink project.

**Outcome — Section 2: Performance & UX (16 items completed):**

| Category | Changes |
|----------|---------|
| **OPcache** | Production `php.ini` fully tuned (256MB, validate_timestamps=0, interned_strings_buffer=16). APCu installed for metadata caching. |
| **HTTP Compression** | `gzip` enabled in all three RoadRunner configs |
| **Worker Pool** | Production `max_jobs` 250→500. Supervisor config with memory limits (128MB HTTP / 256MB jobs), idle TTL, exec TTL |
| **Docker Layers** | Extension installs batched into single parallel compilation. APCu added as separate layer |
| **Doctrine Caching** | APCu-backed metadata cache in production |
| **DNS Prefetch** | `Link: <scheme://host>; rel=dns-prefetch` header added to redirect responses |
| **Security Headers** | New `SecurityHeadersMiddleware`: CSP, HSTS, X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Permissions-Policy |
| **Rate Limiting** | New `RateLimitMiddleware`: APCu-backed sliding window (30 req/60s), keyed by API key or IP |
| **Health Endpoint** | Enhanced with DB check, memory usage, PHP version, OPcache stats |
| **Swagger UI** | Custom Deep Ocean theme CSS |
| **`.env.example`** | Created with 104 lines of documented env vars |

---

### Prompt 3: Terminal/Hacker Theme — Deep Ocean Palette

**User asked:** Create a landing page and theme system with a terminal/hacker aesthetic using the "Deep Ocean" color palette.

**Design tokens defined:**
```
Background:    #0f1419  (very dark teal-gray)
Card Surface:  #192028  (dark card/panel)
Accent:        #4a9a8e  (muted teal)
Accent Hover:  #5cc4b3  (brighter teal on hover)
Text Primary:  #a8b2c1  (silver)
Text Muted:    #6b7a8d  (dimmed silver)
Success:       #4ade80  (terminal green)
Error:         #f87171  (soft red)
Font:          "JetBrains Mono", "Fira Code", "SF Mono", monospace
```

**Outcome — Section 3: Theme (completed):**

- **`landing.html`** — Full landing page with nav bar, hero section with typing animation (`curl -X POST ...`), animated stats counters, features grid, social links, footer. Responsive (desktop/tablet/mobile), WCAG 2.1 AA compliant, vanilla JS only.
- **`_theme.css`** — Shared CSS custom properties for the design system
- **`LandingAction.php`** — New route handler serving the landing page at `/`
- **Error pages** — Redesigned with terminal theme, blinking cursor, navigation
- **CSP headers** — Updated `SecurityHeadersMiddleware` to allow inline scripts/styles for the landing page

---

## Session 2: Documentation & Deployment Infrastructure

### Prompt 4: Architecture Clarification

**User asked:** "Is this project only for the backend? Do I need shlink-web-client for the dashboard?"

**Response:** Confirmed Dhiarlink is **backend-only** — providing the REST API, redirect engine, CLI, and landing page. The dashboard is a **separate project**:
- Original: [shlink-web-client](https://github.com/shlinkio/shlink-web-client) (React)
- User's fork: [dhiarlink-web-client](https://github.com/dhiar/dhiarlink-web-client) (React 19 PWA with Vite 8)

The dashboard connects to the Dhiarlink REST API through the user's browser (not server-side).

---

### Prompt 5: README.md Rewrite

**User asked:** "Update the README.md with the tech stack, dependencies, and local deployment instructions."

**Outcome:** Complete README rewrite with:
- Tech Stack table (20+ technologies with versions and purposes)
- Dependencies tables (35+ production packages, 6 dev packages, PHP extensions)
- Local Development Setup (Quick Start, Ports & Services table, Common Commands, Running Tests)
- Production Deployment (Docker and Bare Metal options)
- Project Structure overview

---

### Prompt 6: Production Deployment Guide

**User asked:** "Create deployment.md for deploying on my Proxmox LXC container with Cloudflare Tunnel. Include all steps from zero to fully operational."

**Outcome:** Created comprehensive deployment guide (~1070 lines) covering:
- **Architecture diagram** — Cloudflare Edge → cloudflared → Caddy → Dhiarlink/Dashboard
- **10 steps**: LXC setup, system prep, Docker install, project clone, secrets generation, cloudflared config, DNS setup, build & start, Dhiarlink init, verification
- **Optional features**: GeoLite2 geolocation, Mercure real-time updates, Matomo analytics
- **Maintenance**: Updating, backup/restore procedures
- **Troubleshooting**: Common issues and fixes

**Initial design decisions:**
- cloudflared was initially a Docker container (later changed to system service)
- Dashboard was initially downloaded from shlink-web-client releases (later changed to local fork)
- Caddy routes by hostname: `dhiarr.qzz.io`, `app.dhiarr.qzz.io`, `link.dhiarr.qzz.io`

**Files created/modified:**
- `documentation/deployment.md` — New (~1070 lines)
- `docker-compose.prod.yml` — New production orchestration
- `data/infra/Caddyfile` — New reverse proxy config
- `data/infra/dashboard.Dockerfile` — Dashboard download+serve (later deleted)
- `data/infra/spa-nginx.conf` — SPA nginx config (later deleted)

---

### Prompt 7: Switch to Local Fork & System cloudflared

**User asked:** "I want to use the web client from my fork at `../dhiarlink-web-client`, not the official shlink-web-client. Also, I already installed cloudflared as a system service on the LXC, not Docker. I've already followed the guide up to `docker compose up`. Update everything."

**Key context:** The user already had their own fork of the web client with Dhiarlink branding, and had cloudflared running as a systemd service.

**Outcome:** Major infrastructure changes:

| Change | Details |
|--------|---------|
| **Removed cloudflared container** | From `docker-compose.prod.yml`. Caddy now exposed on host port `3000` for the system cloudflared |
| **Dashboard builds from fork** | `dhiarlink_dashboard` now uses `build.context: ../dhiarlink-web-client` instead of downloading releases |
| **Deleted redundant files** | `dashboard.Dockerfile` and `spa-nginx.conf` deleted (fork has its own Dockerfile and nginx config) |
| **Updated Caddyfile** | Dashboard proxy target changed from port 80 to 8080 (matching the fork's nginx config) |
| **Updated `.env.example`** | Added `DHIARLINK_SERVER_*` variables for dashboard pre-configuration |
| **Rewrote deployment.md** | Updated architecture diagram, Steps 4-10, added "Fixing an Existing Deployment" section |

**Architecture flow updated:**
```
Client → Cloudflare Edge (TLS) → cloudflared (system service) → localhost:3000 → Caddy (Docker) → Dhiarlink or Dashboard
```

---

## Session 3: Deployment Debugging & Hostname Migration

### Prompt 8: Docker Pull Error

**User reported:** `dhiarlink-web-client:latest pull access denied` and `dhiarlink:latest pull access denied`

**Diagnosis:** Docker was trying to pull images from Docker Hub because they hadn't been built locally yet.

**Fix:** Run `docker compose -f docker-compose.prod.yml build` before `up`. Both images are local builds, not from Docker Hub.

---

### Prompt 9: Dashboard Customization Question

**User asked:** "Do I need to customize dhiarlink-web-client on the LXC before building?"

**Response:** No. The Dockerfile copies the entire repo (with existing customizations), runs `npm ci` + `npm run build`, and serves via nginx. Server connection is injected at runtime via `DHIARLINK_SERVER_*` env vars.

---

### Prompt 10: MySQL Container Unhealthy

**User reported:** `container dhiarlink_db is unhealthy` with error: `MYSQL_USER="root", MYSQL_USER and MYSQL_PASSWORD are for configuring a regular user and cannot be used for the root user`

**Root cause:** `.env` had `DB_USER=root`. MySQL's `MYSQL_USER` creates a regular user and "root" is reserved.

**Fix:**
1. Changed `DB_USER=root` → `DB_USER=dhiarlink`
2. Changed `DB_HOST=dhiarlink_db_mysql` → `DB_HOST=dhiarlink_db` (production container name, not dev)
3. Removed bad volume: `docker volume rm dhiarlink_mysql_data`
4. Restarted stack

**Lesson:** `DB_USER` must not be "root". `DB_HOST` in `.env` must match the production container name (`dhiarlink_db`), not the dev one (`dhiarlink_db_mysql`).

---

### Prompt 11: Dhiarlink Container Crash-Looping

**User reported:** `dhiarlink` container showing `Restarting (1) 2 seconds ago`. Error: `[ERROR] Error generating database.. Set SHELL_VERBOSITY=3`

**Diagnosis:** `SHELL_VERBOSITY=3` was set but the verbose output wasn't captured. The actual root cause was the wrong `DB_HOST` still in `.env` — MySQL was rejecting the connection because the user/password didn't match.

**Fix:** Same as above — correcting `DB_USER` and `DB_HOST` in `.env` resolved both the MySQL and Dhiarlink startup issues.

---

### Prompt 12: Hostname Migration to www.dhiarr.qzz.io

**User asked:** "Change the landing page to `www.dhiarr.qzz.io` because if I use the bare domain, it needs an A record DNS which I don't have since I'm using Cloudflare Tunnel (CNAME only)."

**Key insight:** Cloudflare Tunnel uses CNAME records, which don't work for bare/apex domains. Using `www.dhiarr.qzz.io` (a subdomain) solves this.

**Files modified:**

| File | Change |
|------|--------|
| `data/infra/Caddyfile` | Host match: `dhiarr.qzz.io` → `www.dhiarr.qzz.io` |
| `docker-compose.prod.yml` | Dashboard URL default: `https://dhiarr.qzz.io` → `https://www.dhiarr.qzz.io` |
| `.env.example` | Dashboard vars uncommented, URL → `www.dhiarr.qzz.io` |
| `documentation/deployment.md` | All `dhiarr.qzz.io` references → `www.dhiarr.qzz.io` (architecture, URLs, cloudflared config, health checks, troubleshooting) |

**What stayed unchanged:**
- `app.dhiarr.qzz.io` — Dashboard (still a subdomain, CNAME works)
- `link.dhiarr.qzz.io` — Short URLs (still a subdomain)
- `DEFAULT_DOMAIN=link.dhiarr.qzz.io` — Short URL generation domain
- `CORS_ALLOW_ORIGIN=https://app.dhiarr.qzz.io` — CORS allows the dashboard origin

---

## Session 4: Documentation Overhaul

### Prompt 13: Complete Documentation Restructuring

**User asked:** "Read and understand the core structure again, then update the documentation folder:
1. Update `prompt-history.md` with all our previous prompts and responses
2. Update `DEVELOPING.md` and `deployment.md` with the newest `.env.example` and docker-compose changes
3. Update `docs.md` with comprehensive project info — move tech stack, env vars, dependencies, project structure from README
4. Simplify README to: short about, quick start, using dhiarlink, contributing
5. Make the documentation folder clean and well-structured"

**Decisions made:**

| Decision | Rationale |
|----------|-----------|
| Move detailed technical info to `docs.md` | README was 395 lines — too long for a landing page |
| Keep README under 100 lines | Quick orientation for new visitors |
| Fix `.env.example` defaults | `DB_HOST=dhiarlink_db_mysql` (dev) and `DB_USER=root` were still present |
| Add Production Stack section to `DEVELOPING.md` | Developers need to know about the production compose file |
| Create `prompt-history.md` | Track all development decisions for future reference |

**Files created/modified:**
- `.env.example` — Fixed `DB_HOST`, `DB_USER`, `DB_PASSWORD`; uncommented dashboard pre-config vars
- `documentation/docs.md` — Rewritten (539 lines): about, architecture, tech stack, dependencies, project structure, complete env var reference, API reference, URL structure
- `README.md` — Rewritten (~128 lines): short about, quick start (dev + production), using, contributing, docs table
- `documentation/DEVELOPING.md` — Added Production Stack section and `.env` reference note
- `docker-compose.prod.yml` — Fixed Caddy comment (`www.dhiarr.qzz.io`)
- `documentation/prompt-history.md` — Created (this file)

---

## Deployment Issues Encountered (Summary)

| Issue | Root Cause | Fix |
|-------|-----------|-----|
| `dhiarlink-web-client:latest` pull denied | Image not built locally | Run `docker compose build` before `up` |
| MySQL `MYSQL_USER="root"` error | `DB_USER=root` in `.env` | Change to `DB_USER=dhiarlink` |
| MySQL connection refused | `DB_HOST=dhiarlink_db_mysql` (dev name) | Change to `DB_HOST=dhiarlink_db` |
| Dhiarlink crash-looping | Wrong DB connection params | Fix `DB_USER` and `DB_HOST` in `.env` |
| `www.dhiarr.qzz.io` shows "Not Found" | Caddyfile only had `dhiarr.qzz.io` | Update Caddyfile to `www.dhiarr.qzz.io` |
| `link.dhiarr.qzz.io` bad gateway | Dhiarlink container was down | Fix DB params (see above) |
| Stale MySQL volume | Failed first-run initialization | `docker volume rm dhiarlink_mysql_data` |

---

## Session 5: Landing Page Mobile Fix & Social Links

### Prompt 14: Fix Terminal Animation Overflow & Update Social Links

**User asked:**
1. Fix the landing page terminal animation on mobile — it extends far to the right causing horizontal scroll
2. Change social links: X/Twitter → Instagram (`instagram.com/dhiarharianto`), Mastodon → Saweria donate (`saweria.co/dhiarharianto`)
3. Update to-do.md and prompt-history.md

**Root cause (terminal overflow):** The terminal typing animation outputs a long URL (`curl -X POST https://www.dhiarr.qzz.io/rest/v3/short-urls/shorten`) with `white-space: nowrap`. On mobile screens (<640px), the text exceeds the container width, causing page-level horizontal scroll.

**Outcome:**

| Fix | Details |
|-----|--------|
| **`overflow-x: hidden`** on `html` and `body` | Safety net prevents any page-level horizontal scroll |
| **Mobile terminal styling** | At `max-width:640px`: `.terminal__line` changes to `white-space: normal; word-break: break-all`, `.terminal__body` padding reduced to `1rem`, font size to `.72rem` |
| **`text-overflow: ellipsis`** | Added to `.terminal__line` for desktop — truncates gracefully if text overflows |
| **Social links updated** | X/Twitter SVG + link → Instagram SVG + `instagram.com/dhiarharianto`. Mastodon SVG + link → Heart/donate SVG + `saweria.co/dhiarharianto` |

**Files modified:**
- `module/Core/templates/landing.html` — CSS overflow fixes, mobile terminal styling, social link/icon SVG replacements
- `documentation/to-do.md` — Marked items 1, 3, 4 as done with details
- `documentation/prompt-history.md` — This session entry

---

## Session 6: Performance Optimization & Bare Metal Deployment

### Prompt 15: Optimize Backend Performance & Add Bare Metal Deployment

**User asked:**
1. Understand the full codebase again and optimize backend/API performance (slow load times from dashboard)
2. Create a complete bare metal deployment guide (no Docker — raw LXC performance)
3. Provide Docker uninstall/migration instructions
4. Update prompt-history.md

**Critical discoveries during codebase review:**

| Issue | Impact | Fix |
|-------|--------|-----|
| **Missing `config/roadrunner/.rr.yml`** | Production Docker image had no RoadRunner config — RR ran with defaults or failed | Created production config with worker pool tuning |
| **Missing `data/infra/Caddyfile`** | Referenced in docs but absent (`.dockerignore` excluded `data/infra/`) | Recreated with hostname routing + gzip/zstd compression |
| **Missing `docker-compose.prod.yml`** | Production stack orchestration file absent | Recreated 5-service stack |
| **Missing `.env.example`** | Environment variable reference file absent | Recreated with all documented variables |
| **Double compression** | Both RoadRunner gzip and Caddy encode were active | Removed gzip from RR production config, Caddy handles all compression |
| **No OPcache preloading** | First-request compilation overhead | Created `config/opcache-preload.php` using Composer class map |

**Performance optimizations applied:**

| Optimization | Details |
|-------------|---------|
| **RoadRunner production config** | `num_workers: 0` (auto CPU), `max_jobs: 500`, supervisor with memory limits (128MB HTTP / 256MB jobs), idle TTL, exec TTL, `prefetch: 100` for job pipeline |
| **OPcache preloading** | `config/opcache-preload.php` reads Composer's `autoload_classmap.php` and preloads all classes via `opcache_compile_file()`. Logs preload count to stderr |
| **PHP ini preload directive** | `opcache.preload=/etc/dhiarlink/config/opcache-preload.php` added to Docker `php.ini` |
| **Caddy compression** | `encode gzip zstd` in Caddyfile — faster than RoadRunner's gzip, supports zstd for modern browsers |
| **Removed double compression** | RR middleware: `['static']` only (no `gzip`) since Caddy compresses all responses |

**Files created:**

| File | Purpose |
|------|---------|
| `config/roadrunner/.rr.yml` | Production RoadRunner config with env var overrides (`WEB_WORKER_NUM`, `TASK_WORKER_NUM`, `LOGS_FORMAT`) |
| `data/infra/Caddyfile` | Docker Caddy reverse proxy: hostname routing, gzip+zstd, proper header forwarding |
| `docker-compose.prod.yml` | Production Docker Compose: 5 services (Dhiarlink, MySQL, Redis, Caddy, Dashboard) |
| `.env.example` | Environment variable reference with Docker and bare metal defaults |
| `config/opcache-preload.php` | OPcache class preloading script using Composer's optimized class map |
| `data/infra/systemd/dhiarlink.service` | Systemd unit file for RoadRunner with security hardening |
| `data/infra/Caddyfile.bare-metal` | Bare metal Caddy config (listens on :3000, proxies to localhost:8080/8081) |

**Files modified:**

| File | Change |
|------|--------|
| `docker/config/php.ini` | Added `opcache.preload` and `opcache.preload_user` directives |
| `documentation/deployment.md` | Added "Migrating from Docker to Bare Metal" section + complete "Bare Metal Deployment (No Docker)" guide (~720 lines) |
| `documentation/to-do.md` | Updated tracking items |
| `documentation/prompt-history.md` | This session entry |

**Bare metal deployment guide covers:**
1. System preparation (Debian 12 / Ubuntu 24.04)
2. PHP 8.5 installation from deb.sury.org with 12 extensions
3. Production PHP tuning (OPcache 256MB, APCu 64MB, preload, assertions disabled)
4. MySQL 8.0 native install with performance tuning (InnoDB buffer pool, connection limits)
5. Redis 7.4 native install with memory management (128MB, LRU eviction)
6. Composer 2.x installation
7. Project setup: `composer install --no-dev --optimize-autoloader`, class map generation
8. RoadRunner binary download and systemd service installation
9. Caddy 2 installation from official repo + bare metal Caddyfile
10. Dashboard: Node.js build + nginx serving on port 8081
11. Cloudflare Tunnel configuration (same as Docker — `localhost:3000`)
12. Verification steps and maintenance commands
13. Performance tuning checklist table

**Docker migration guide covers:**
1. Database backup via `mysqldump`
2. `.env` and GeoLite DB backup
3. Stop and remove Docker stack (`down --rmi all -v`)
4. Optional Docker uninstall (`apt remove docker-ce...`)
5. Set up fresh bare metal environment

---

## Session 7: Fresh Bare Metal Guide & Docker Migration Cleanup

### Prompt 7: Update bare metal guide for fresh setup

**User asked:**
1. Update the bare metal deployment guide — user is setting up a brand new environment (no existing Docker data to migrate)
2. Remove the "create/restore database" from Docker migration section, add a proper database creation guide in the bare metal section
3. Update prompt-history.md and CHANGELOG.md

**Problem identified:** The bare metal guide was originally written as a migration path from Docker. The user is deploying on a fresh server with no existing data, so references to "restoring database from backup" and "skipping database creation" were confusing and unnecessary.

**Changes made:**

| File | Change |
|------|--------|
| `documentation/deployment.md` | Restructured: removed "Restore Your Database" step from Docker migration; expanded MySQL section with comprehensive fresh database creation (secure install walkthrough, SQL with comments, user verification step); updated bare metal intro to emphasize fresh standalone setup; improved "Initialize the Database" section with clearer context |
| `CHANGELOG.md` | Added full Unreleased section with Added/Changed entries for all Session 6+7 work |
| `documentation/prompt-history.md` | This session entry |

**Bare metal MySQL section now includes:**
1. `mysql_secure_installation` walkthrough with expected answers
2. Full SQL with comments explaining each command
3. `SHOW DATABASES` and user verification queries
4. "Verify Database Access" step — test that `dhiarlink` user can connect independently
5. Password reminder note pointing to Step 6 `.env` configuration
6. Performance tuning (InnoDB, connection limits, slow query log)

**Docker migration section simplified:**
- Removed: Step 4 (Restore Your Database) and Step 5 (Continue with Bare Metal Setup)
- Added: Step 4 (Set Up Bare Metal) — points directly to the complete bare metal guide
- Migration section now covers: backup → stop Docker → remove Docker → set up fresh bare metal

---

## Session 8: Bare Metal Deployment Debugging & Stabilization
- [Session 9: Bare Metal Post-Deployment Debugging](#session-9-bare-metal-post-deployment-debugging)

### Prompt 8: Live bare metal deployment — fix all issues encountered during actual setup

**User asked:** Deploy the bare metal stack on a fresh Debian 13 LXC and fix every error encountered along the way.

**Issues discovered and fixed during live deployment:**

| # | Issue | Root Cause | Fix |
|---|-------|------------|-----|
| 1 | `php -v` fatal error on opcache preload | PHP config (Step 2) references `opcache-preload.php` before repo is cloned (Step 6) | Added ordering note: comment out preload lines until Step 6, then `sed` to re-enable |
| 2 | `mysql-server` package not available | Debian 13 ships MariaDB, not MySQL | Rewrote Step 3: `mariadb-server`, `mariadb-secure-installation`, MariaDB config paths, service names |
| 3 | RoadRunner `max_request_size` parse error | `10M` string not valid — RoadRunner requires integer bytes | Changed to `10485760` in `.rr.yml` |
| 4 | RoadRunner `bin/rr version` unknown command | Newer RoadRunner uses `--version` flag | Updated deployment guide |
| 5 | `no encoder registered for name "json"` | `${LOGS_FORMAT:-json}` shell-style defaults not supported by RoadRunner envsubst | Removed `:-default` syntax; defaults set in `.env` and systemd `Environment=` directives |
| 6 | RoadRunner still failing after envsubst fix | YAML header comment text leaking into parsed value | Cleaned all inline comments from `.rr.yml` values |
| 7 | Systemd service: wrong deps, no env defaults | `mysql.service` and `redis.service` don't exist on Debian 13 | Changed to `mariadb.service` and `redis-server.service`; added `Environment=` defaults for worker vars |
| 8 | MemoryMax=512M too tight | 4 workers × 128MB + overhead exceeds 512M | Increased to `1G` |
| 9 | Cloudflare 502 Bad Gateway | Caddy not installed — nothing listening on port 3000 | Installed Caddy with bare metal Caddyfile |
| 10 | Cloudflare still 502 after Caddy install | cloudflared used `https://localhost:3000` but bare metal Caddy has `auto_https off` | Changed cloudflared to `http://localhost:3000` |
| 11 | HTTP 500 on all non-REST routes | Monolog `StreamHandler` fails with `errno=9 Bad file descriptor` writing to `php://stderr` under RoadRunner on bare metal | Made logger conditional: Docker uses `php://stderr` (detected via `/.dockerenv`), bare metal writes to log files |

**Files modified (source code):**

| File | Change |
|------|--------|
| `config/roadrunner/.rr.yml` | Fixed `max_request_size` to integer, removed `:-default` envsubst syntax, removed inline comments from values |
| `config/autoload/logger.global.php` | Added `/.dockerenv` detection; Access and Shlink loggers use file output on bare metal, stream on Docker |
| `data/infra/systemd/dhiarlink.service` | Fixed service deps (`mariadb.service`, `redis-server.service`), added `Environment=` defaults, increased `MemoryMax=1G` |
| `.env.example` | Uncommented `WEB_WORKER_NUM` and `TASK_WORKER_NUM`; changed `LOGS_FORMAT` default to `console` |

**Files modified (documentation):**

| File | Change |
|------|--------|
| `documentation/deployment.md` | PHP 8.5→8.4, MySQL→MariaDB throughout, expanded PHP install step (no PPA needed), MariaDB secure install walkthrough, DB verification steps, OPcache preload ordering note, RoadRunner env vars in `.env` section, `LOGS_FORMAT=console` default |
| `documentation/docs.md` | Bare metal architecture diagram: MySQL → MariaDB |
| `documentation/to-do.md` | Bare metal item updated: PHP 8.4, MariaDB, Debian 13 |
| `CHANGELOG.md` | Added `### Fixed` section with 5 bare metal fixes; updated Added section for Debian 13/MariaDB |
| `documentation/prompt-history.md` | This session entry |

**Key learnings:**
1. RoadRunner envsubst only supports `${VAR}` — no `${VAR:-default}` shell syntax
2. `max_request_size` must be integer bytes — no human-readable strings like `10M`
3. Monolog `StreamHandler` with `php://stderr` fails under RoadRunner workers on bare metal (errno=9)
4. Debian 13 ships MariaDB (not MySQL) and PHP 8.4 (not 8.5) — no PPA needed
5. `/.dockerenv` file reliably detects Docker vs bare metal environments
6. RoadRunner workers take ~15-20 seconds to initialize (OPcache preload + class loading)
7. cloudflared must use `http://` (not `https://`) when bare metal Caddy has `auto_https off`

---

## Session 9: Bare Metal Post-Deployment Debugging

### Prompt 9: Fix bare metal deployment issues — database, OPcache, pipeline, and env config

**User asked:** Web client dashboard connects to API but short URLs cannot be created or accessed. Multiple issues discovered during live bare metal usage.

**Issues discovered and fixed:**

| # | Issue | Root Cause | Fix |
|---|-------|------------|-----|
| 1 | `bin/cli` slow — `[opcache-preload] Preloaded 7498 files` on every command | `opcache.preload` set in CLI php.ini, runs on every PHP CLI invocation | Remove `opcache.preload` and `opcache.preload_user` from `/etc/php/8.4/cli/conf.d/99-dhiarlink.ini` |
| 2 | `bin/cli env-var:read DB_DRIVER` returns `sqlite` | Application reads from `config/params/*.php`, NOT `.env` file | Create `config/params/prod.php` with all production env vars |
| 3 | `bin/cli env-var:read DEFAULT_DOMAIN` returns empty | Same as #2 — `.env` is for Docker Compose/systemd, app reads PHP config files | Same as #2 |
| 4 | `short-url:create` generates malformed URL `https:/E6IJC` | `DEFAULT_DOMAIN` empty → no domain in generated URL | Fixed by #2 |
| 5 | 500 Internal Server Error on short URL redirects | RoadRunner jobs pipeline named `dhiarlink` but vendor code (`shlinkio/shlink-event-dispatcher`) hardcodes `shlink` | Renamed pipeline back to `shlink` in `.rr.yml` |
| 6 | `--tags` option doesn't exist on `short-url:create` | CLI uses `--tag` (singular), can be repeated | Documentation fix |

**Files modified (source code):**

| File | Change |
|------|--------|
| `config/roadrunner/.rr.yml` | Renamed jobs pipeline from `dhiarlink` back to `shlink` in both `consume` and `pipelines` sections |

**Files modified (documentation):**

| File | Change |
|------|--------|
| `CHANGELOG.md` | Updated Changed entry about pipeline naming; added Fixed entry for pipeline name issue |
| `documentation/prompt-history.md` | This session entry |

**Key learnings:**
1. Shlink/Dhiarlink does NOT read `.env` directly — it reads from `config/params/*.php` via `loadEnvVarsFromConfig()` in `container.php`
2. `.env` is only used by Docker Compose (passes env vars to containers) or systemd `EnvironmentFile=`
3. `opcache.preload` in CLI php.ini wastes 1-2 seconds per `bin/cli` invocation — only useful for long-running RoadRunner workers
4. Vendor packages (like `shlinkio/shlink-event-dispatcher`) hardcode pipeline name `shlink` — renaming breaks async task dispatch
5. `DB_DRIVER` must be `maria` (not `mysql`) for MariaDB on Debian 13
6. After re-initializing the database, a new API key must be generated (old key is in the old database)
