# Dhiarlink Documentation

> Comprehensive reference for the Dhiarlink project — a self-hosted URL shortener forked from [Shlink](https://shlink.io).

---

## Table of Contents

- [About Dhiarlink](#about-dhiarlink)
- [Architecture](#architecture)
    - [Production Architecture](#production-architecture)
    - [Request Flow](#request-flow)
    - [How URL Shortening Works](#how-url-shortening-works)
- [Tech Stack](#tech-stack)
- [Dependencies](#dependencies)
    - [Production Dependencies (Composer)](#production-dependencies-composer)
    - [Dev Dependencies](#dev-dependencies)
    - [Required PHP Extensions](#required-php-extensions)
    - [Supported Databases](#supported-databases)
- [Project Structure](#project-structure)
- [Environment Variables](#environment-variables)
    - [Application](#application)
    - [URL Shortener](#url-shortener)
    - [Database](#database)
    - [Redis](#redis)
    - [Mercure (Real-Time)](#mercure-real-time)
    - [RabbitMQ](#rabbitmq)
    - [Matomo (Analytics)](#matomo-analytics)
    - [GeoLite2 (Geolocation)](#geolite2-geolocation)
    - [CORS](#cors)
    - [Tracking & Privacy](#tracking--privacy)
    - [Redirects](#redirects)
    - [Workers & Performance](#workers--performance)
    - [Reverse Proxy](#reverse-proxy)
    - [Dashboard Pre-Configuration](#dashboard-pre-configuration)
    - [Production Database](#production-database)
- [API Reference](#api-reference)
- [URL Structure](#url-structure)

---

## About Dhiarlink

Dhiarlink is a PHP-based, self-hosted URL shortener that serves shortened URLs under your own domain. It provides:

- **REST API** — Full-featured JSON API for creating, managing, and tracking short URLs
- **CLI** — Command-line interface for all operations (admin, debugging, scripting)
- **Redirect Engine** — High-performance redirect handling with visit tracking
- **Landing Page** — Terminal/hacker-themed landing page at the root domain
- **Dashboard** — React-based web UI for managing links (separate project: [dhiarlink-web-client](https://github.com/dhiar/dhiarlink-web-client))

Dhiarlink is a fork of Shlink with cosmetic rebranding and infrastructure improvements. PHP namespaces (`Shlinkio\Shlink\*`) and Composer dependencies remain unchanged for upstream compatibility.

**Live URLs:**

| URL | Purpose |
|-----|---------|
| `https://www.dhiarr.qzz.io` | Landing page |
| `https://www.dhiarr.qzz.io/rest/health` | Health check endpoint |
| `https://www.dhiarr.qzz.io/docs` | API documentation (Swagger UI) |
| `https://app.dhiarr.qzz.io` | Dashboard (dhiarlink-web-client) |
| `https://link.dhiarr.qzz.io/<code>` | Short URL redirects |

---

## Architecture

### Production Architecture

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

**Key design decisions:**
- **cloudflared runs as a system service** on the host (not inside Docker)
- Caddy is the only service exposed to the host (port `3000`)
- TLS/HTTPS is terminated at Cloudflare's edge — internal traffic is plain HTTP
- Caddy routes requests to the correct backend based on hostname
- Database and Redis are only accessible within the Docker network
- The dashboard is built from the sibling `dhiarlink-web-client` fork

### Request Flow

When a request hits the Dhiarlink server, it goes through this middleware pipeline:

```
Request arrives (e.g., GET /spotify)
    │
    ├──→ Access Logger          (logs the request)
    ├──→ Request ID             (assigns unique tracking ID)
    ├──→ Security Headers       (adds CSP, HSTS, X-Frame-Options, etc.)
    ├──→ Error Handler          (catches any exceptions)
    ├──→ CORS Handler           (handles cross-origin requests for the API)
    │
    ├──→ Route Matching         (is this a short code? an API call? robots.txt?)
    │       │
    │       ├── If /rest/v3/...  → Authentication → API handler → JSON response
    │       │
    │       ├── If /             → Landing page (LandingAction)
    │       │
    │       └── If /{shortCode}  → Redirect flow:
    │               │
    │               ├── IP Address extraction
    │               ├── Geolocation lookup (GeoLite2)
    │               ├── Trailing slash normalization
    │               └── RedirectAction:
    │                       ├── Resolve short URL from DB
    │                       ├── Track the visit (async via RoadRunner jobs)
    │                       ├── Build redirect response (302/301)
    │                       └── Add DNS prefetch header for target domain
    │
    └──→ If not found:
            ├── Track as orphan visit
            ├── Check for redirect rules
            └── Show 404 error page (terminal-themed)
```

### How URL Shortening Works

**Creating a Short URL:**

```
Dashboard/CLI                    Dhiarlink Server                    Database
     |                                |                               |
     |  1. "Create short URL for      |                               |
     |     spotify.com/... with       |                               |
     |     custom slug 'spotify'"     |                               |
     |------------------------------>|                               |
     |                                |  2. Check if 'spotify'        |
     |                                |     slug already exists       |
     |                                |------------------------------>|
     |                                |                               |
     |                                |  3. It's free!                |
     |                                |<------------------------------|
     |                                |                               |
     |                                |  4. Save the mapping:         |
     |                                |     "spotify" → long URL      |
     |                                |------------------------------>|
     |                                |                               |
     |  5. "Here's your short URL:    |                               |
     |     link.dhiarr.qzz.io/spotify"|                               |
     |<------------------------------|                               |
```

**Someone Clicks a Short Link:**

```
Visitor's Browser          Dhiarlink Server          Database
     |                         |                      |
     |  1. GET /spotify        |                      |
     |------------------------>|                      |
     |                         |  2. Look up "spotify"|
     |                         |--------------------->|
     |                         |                      |
     |                         |  3. Found! Redirect   |
     |                         |     to long URL       |
     |                         |<---------------------|
     |                         |                      |
     |  4. HTTP 302 Redirect   |  5. (async) Record   |
     |     to Spotify          |     visit: IP, country,|
     |<------------------------|     browser, device   |
     |                         |--------------------->|
```

Visit tracking happens **asynchronously** via RoadRunner's job queue — the visitor gets redirected instantly without waiting for the database write.

---

## Tech Stack

| Layer | Technology | Purpose |
|-------|-----------|---------|
| **Language** | PHP 8.4 / 8.5 | Core application language |
| **App Server** | [RoadRunner](https://roadrunner.dev) | High-performance PHP application server (persistent workers, no cold starts) |
| **Alternative Server** | [FrankenPHP](https://frankenphp.dev) | Modern PHP app server built on Caddy (alternative runtime) |
| **Web Server** | Nginx 1.25 | Reverse proxy and static file serving (PHP-FPM setup) |
| **Framework** | [Mezzio](https://docs.mezzio.dev) (Laminas) | PSR-15 middleware framework for routing and HTTP handling |
| **ORM** | [Doctrine ORM](https://www.doctrine-project.org/projects/orm.html) 3.x | Database abstraction and entity management |
| **Migrations** | [Doctrine Migrations](https://www.doctrine-project.org/projects/migrations.html) 3.x | Schema versioning and database migrations |
| **DI Container** | [Laminas ServiceManager](https://docs.laminas.dev/laminas-servicemanager/) 4.x | Dependency injection and service wiring |
| **Config** | [Laminas Config Aggregator](https://github.com/laminas/laminas-config-aggregator) | Modular configuration merging |
| **Routing** | [Mezzio Fastroute](https://github.com/mezzio/mezzio-fastroute) | Fast HTTP routing based on FastRoute |
| **HTTP** | [Laminas Diactoros](https://docs.laminas.dev/laminas-diactoros/) | PSR-7 HTTP message implementation |
| **Object Mapping** | [Valinor](https://valinor.cuyz.io) (via mezzio-valinor) | Request body mapping to PHP objects |
| **Job Queue** | [RoadRunner Jobs](https://github.com/roadrunner-server/jobs) | Async task processing (visit tracking, geolocation) |
| **Message Broker** | RabbitMQ 3.11 | Optional external message queue for async jobs |
| **Real-time** | [Mercure](https://mercure.rocks) v0.18 | Server-Sent Events (SSE) hub for live visit notifications |
| **Caching** | APCu + Redis 7.4 | APCu for in-process metadata cache, Redis for distributed cache & pub/sub |
| **Geolocation** | [GeoLite2](https://www.maxmind.com) (MaxMind) | IP-based visitor geolocation for visit analytics |
| **Analytics** | [Matomo](https://matomo.org) 5.0 | Optional external analytics platform integration |
| **API Docs** | OpenAPI / [Swagger UI](https://swagger.io/tools/swagger-ui/) v5 | REST API specification and interactive documentation |
| **Containerization** | Docker + Docker Compose | All development and production dependencies |
| **Testing** | PHPUnit 13, PHPStan 2.x | Unit tests, DB integration tests, API E2E tests, static analysis |
| **Linting** | [Mago](https://mago.carthage.software) | Code linting and formatting |
| **Production Proxy** | Caddy 2 + cloudflared | Reverse proxy + Cloudflare Tunnel (no public IP needed) |
| **Dashboard** | [dhiarlink-web-client](https://github.com/dhiar/dhiarlink-web-client) | React 19 PWA (Vite 8, nginx-unprivileged) |

---

## Dependencies

### Production Dependencies (Composer)

| Package | Version | Purpose |
|---------|---------|---------|
| `shlinkio/shlink-common` | ^9.0 | Shared middleware, Doctrine setup, caching, Redis integration |
| `shlinkio/shlink-config` | ^4.1 | Configuration handling and env var parsing |
| `shlinkio/shlink-event-dispatcher` | ^4.4 | Event dispatching (visit tracking, Mercure, Redis pub/sub) |
| `shlinkio/shlink-importer` | ^5.8 | Import short URLs from other services (Bitly, YOURLS, etc.) |
| `shlinkio/shlink-installer` | ^10.1 | Interactive CLI installer for initial setup |
| `shlinkio/shlink-ip-geolocation` | ^5.0 | GeoLite2 IP geolocation integration |
| `shlinkio/shlink-json` | ^1.3 | JSON encoding/decoding utilities |
| `shlinkio/doctrine-specification` | ^2.3 | Specification pattern for Doctrine repositories |
| `mezzio/mezzio` | ^3.28 | PSR-15 middleware framework |
| `mezzio/mezzio-fastroute` | ^3.14 | Router implementation |
| `mezzio/mezzio-problem-details` | ^1.19 | RFC 7807 Problem Details for API errors |
| `mezzio/mezzio-valinor` | ^1.0 | Request body to object mapping |
| `doctrine/orm` | ^3.6 | Object-Relational Mapper |
| `doctrine/dbal` | ^4.4 | Database Abstraction Layer |
| `doctrine/migrations` | ^3.9 | Database schema migrations |
| `spiral/roadrunner` | ^2025.1 | PHP application server |
| `spiral/roadrunner-http` | ^4.1 | RoadRunner HTTP plugin |
| `spiral/roadrunner-jobs` | ^4.7 | RoadRunner async job queues |
| `symfony/console` | ^8.1 | CLI framework |
| `symfony/lock` | ^8.1 | Locking for concurrent operations |
| `symfony/filesystem` | ^8.1 | Filesystem utilities |
| `symfony/process` | ^8.1 | Process execution |
| `symfony/string` | ^8.1 | String manipulation |
| `guzzlehttp/guzzle` | ^7.11 | HTTP client (title resolution, etc.) |
| `geoip2/geoip2` | ^3.3 | GeoLite2 database reader |
| `ramsey/uuid` | ^4.9 | UUID generation for API keys |
| `hidehalo/nanoid-php` | ^2.0 | Short code generation (Nano ID) |
| `league/csv` | ^9.28 | CSV export for visit data |
| `matomo/matomo-php-tracker` | ^3.4 | Matomo analytics PHP client |
| `pagerfanta/core` | ^3.8 | Pagination for list endpoints |
| `akrabat/ip-address-middleware` | ^2.6 | Extract client IP from requests |
| `donatj/phpuseragentparser` | ^1.11 | Parse visitor user-agent strings |
| `jaybizzle/crawler-detect` | ^1.3 | Detect bot/crawler visits |
| `mlocati/ip-lib` | ^1.22 | IP address range handling |
| `cakephp/chronos` | ^3.5 | Date/time utilities |
| `friendsofphp/proxy-manager-lts` | ^1.0 | Lazy-loading proxy generation |

### Dev Dependencies

| Package | Version | Purpose |
|---------|---------|---------|
| `phpunit/phpunit` | ^13.0 | Testing framework |
| `phpstan/phpstan` | ^2.2 | Static analysis |
| `carthage-software/mago` | ^1.30 | Code linting & formatting |
| `devizzent/cebe-php-openapi` | ^1.1 | OpenAPI spec validation |
| `symfony/var-dumper` | ^8.1 | Debug variable dumping |
| `veewee/composer-run-parallel` | ^1.5 | Parallel Composer script execution |

### Required PHP Extensions

`pdo`, `pdo_mysql`, `pdo_pgsql`, `pdo_sqlite`, `intl`, `mbstring`, `curl`, `sockets`, `bcmath`, `zip`, `calendar`, `apcu` (production), `xdebug` (development only)

### Supported Databases

MySQL 8.0, MariaDB 12.3, PostgreSQL 16.3, Microsoft SQL Server 2022, SQLite

---

## Project Structure

```
dhiarlink
├── bin/                  CLI entry point and worker scripts
│   ├── cli              Main CLI tool
│   ├── doctrine         Doctrine ORM CLI
│   ├── frankenphp-worker.php
│   └── roadrunner-worker.php
├── config/
│   ├── autoload/        Merged application config (routes, middleware, cache, etc.)
│   ├── params/          Local dev config (git-ignored)
│   ├── roadrunner/      RoadRunner server configs (.rr.yml)
│   ├── test/            Test bootstrapping and configuration
│   ├── config.php       Config aggregator root
│   └── container.php    DI container bootstrap
├── data/                Runtime-writable: cache, logs, locks, proxies, migrations
│   ├── infra/           Infrastructure configs (Caddyfile, Dockerfiles, nginx)
│   └── migrations_template.txt
├── docker/              Production Docker entrypoint and PHP config
├── documentation/       Project documentation, guides, and references
│   ├── adr/             Architectural Decision Records
│   ├── async-api/       AsyncAPI specification
│   ├── changelog-archive/
│   ├── swagger/         OpenAPI/Swagger spec and custom UI theme
│   ├── deployment.md    Production deployment guide
│   ├── DEVELOPING.md    Development guide
│   └── docs.md          This file
├── module/
│   ├── CLI/             Command-line interface commands and config
│   ├── Core/            Domain logic: redirects, visits, short URLs, templates
│   │   ├── config/      Module configuration
│   │   ├── functions/   Shared utility functions
│   │   ├── migrations/  Database migration files
│   │   ├── src/         Source code (Actions, Middleware, Services, etc.)
│   │   ├── templates/   HTML templates (landing page, error pages)
│   │   └── test/        Unit and integration tests
│   └── Rest/            REST API actions, middleware, and config
├── public/              Web entry point, favicon, .htaccess
├── Dockerfile           Production Docker image (multi-stage, PHP 8.5 + RoadRunner)
├── docker-compose.yml           Full dev environment (all services)
├── docker-compose.prod.yml      Production stack (Caddy + cloudflared architecture)
├── composer.json        PHP dependencies and scripts
├── .env.example         Environment variable reference
└── mago.toml            Mago linter configuration
```

### Module Responsibilities

| Module | Responsibility |
|--------|---------------|
| **CLI** | Console commands: `api-key:generate`, `short-url:create`, `db:migrate`, `visit:*`, `tag:*`, `domain:*`, `import:*` |
| **Core** | Domain logic: short URL resolution, redirect handling, visit tracking, geolocation, templates, middleware (security headers, rate limiting) |
| **Rest** | REST API endpoints: `/rest/v3/short-urls`, `/rest/v3/tags`, `/rest/v3/domains`, `/rest/v3/visits`, `/rest/health` |

---

## Environment Variables

Complete reference of all environment variable. See also [.env.example](../.env.example).

### Application

| Variable | Default | Description |
|----------|---------|-------------|
| `APP_ENV` | `prod` | Environment mode: `prod` or `dev` |
| `DHIARLINK_VERSION` | `latest` | Version shown in `/rest/health` endpoint |
| `DHIARLINK_RUNTIME` | `rr` | Runtime engine: `rr` (RoadRunner) or unset |

### URL Shortener

| Variable | Default | Description |
|----------|---------|-------------|
| `DEFAULT_DOMAIN` | — | Domain used for generated short URLs (e.g., `link.dhiarr.qzz.io`) |
| `IS_HTTPS_ENABLED` | `false` | Whether to generate `https://` short URLs |
| `SHORT_URL_TRAILING_SLASH` | `false` | Append trailing slash to short URLs |
| `BASE_PATH` | — | Prefix path (e.g., `/shortener`) |
| `AUTO_RESOLVE_REDIRECTS` | `false` | Auto-resolve redirect chains for titles |
| `SHORT_URL_MODE` | `strict` | Character validation: `strict` or `loose` |

### Database

| Variable | Default | Description |
|----------|---------|-------------|
| `DB_DRIVER` | `mysql` | Driver: `mysql`, `maria`, `postgres`, `mssql`, `sqlite` |
| `DB_HOST` | `dhiarlink_db` | Database hostname |
| `DB_PORT` | `3306` | Database port |
| `DB_NAME` | `dhiarlink` | Database name |
| `DB_USER` | `dhiarlink` | Database user |
| `DB_PASSWORD` | — | Database password (required) |
| `DB_USE_ENCRYPTION` | `false` | Require encrypted DB connections |
| `DB_UNIX_SOCKET` | — | MySQL/Maria only: connect via socket |

### Redis

| Variable | Default | Description |
|----------|---------|-------------|
| `REDIS_SERVERS` | `tcp://dhiarlink_redis:6379` | Comma-separated connection strings |
| `REDIS_SENTINEL_SERVICE` | — | Sentinel service name |
| `REDIS_SERVERS_USER` | — | Redis AUTH username |
| `REDIS_SERVERS_PASSWORD` | — | Redis AUTH password |
| `REDIS_PUB_SUB_ENABLED` | `true` | Enable real-time visit notifications |
| `CACHE_NAMESPACE` | — | Prefix for all cache keys |

### Mercure (Real-Time)

| Variable | Default | Description |
|----------|---------|-------------|
| `MERCURE_ENABLED` | `false` | Enable real-time visit updates via Mercure |
| `MERCURE_PUBLIC_HUB_URL` | — | Public Mercure hub URL (e.g., `https://www.dhiarr.qzz.io/mercure`) |
| `MERCURE_INTERNAL_HUB_URL` | — | Internal hub URL (e.g., `http://dhiarlink_mercure_proxy`) |
| `MERCURE_JWT_SECRET` | — | JWT secret for Mercure authentication |

### RabbitMQ

| Variable | Default | Description |
|----------|---------|-------------|
| `RABBITMQ_ENABLED` | `false` | Enable RabbitMQ for async task processing |
| `RABBITMQ_HOST` | `dhiarlink_rabbitmq` | RabbitMQ hostname |
| `RABBITMQ_PORT` | `5672` | RabbitMQ AMQP port |
| `RABBITMQ_USER` | `rabbit` | RabbitMQ username |
| `RABBITMQ_PASSWORD` | `rabbit` | RabbitMQ password |

### Matomo (Analytics)

| Variable | Default | Description |
|----------|---------|-------------|
| `MATOMO_ENABLED` | `false` | Enable Matomo analytics integration |
| `MATOMO_BASE_URL` | — | Matomo instance URL |
| `MATOMO_SITE_ID` | `1` | Matomo site ID |
| `MATOMO_API_TOKEN` | — | Matomo API token |

### GeoLite2 (Geolocation)

| Variable | Default | Description |
|----------|---------|-------------|
| `GEOLITE_LICENSE_KEY` | — | MaxMind license key for IP geolocation. Get a free key at https://www.maxmind.com |

### CORS

| Variable | Default | Description |
|----------|---------|-------------|
| `CORS_ALLOW_ORIGIN` | `https://app\.dhiarr\.qzz\.io` | Allowed origins (regex or `*`) |
| `CORS_ALLOW_METHODS` | `GET,POST,PUT,PATCH,DELETE` | Allowed HTTP methods |
| `CORS_ALLOW_HEADERS` | — | Extra allowed headers |
| `CORS_MAX_AGE` | `1800` | Preflight cache (seconds) |
| `CORS_ALLOW_CREDENTIALS` | `false` | Allow credentials in CORS |

### Tracking & Privacy

| Variable | Default | Description |
|----------|---------|-------------|
| `DISABLE_TRACKING` | `false` | Disable all visit tracking |
| `DISABLE_TRACKING_FROM` | — | IP ranges to exclude from tracking |
| `DISABLE_IP_TRACKING` | `false` | Don't store visitor IPs |
| `DISABLE_REFERRER_TRACKING` | `false` | Don't store referrer URLs |
| `DISABLE_UA_TRACKING` | `false` | Don't store user-agent strings |
| `ANONYMIZE_REMOTE_ADDR` | `false` | Hash visitor IP addresses |

### Redirects

| Variable | Default | Description |
|----------|---------|-------------|
| `BASE_URL_REDIRECT` | — | Redirect for base URL visits |
| `INVALID_SHORT_URL_REDIRECT` | — | Redirect for invalid short codes |
| `REGULAR_404_REDIRECT` | — | Redirect for non-short-URL 404s |
| `DELETE_SHORT_URL_THRESHOLD` | `15` | Visits required before deleting |
| `CHECK_VISITS_THRESHOLD` | `true` | Enable visit threshold check |

### Workers & Performance

| Variable | Default | Description |
|----------|---------|-------------|
| `WEB_WORKER_NUM` | `0` | Number of RoadRunner web workers (0 = auto-detect CPU cores) |
| `TASK_WORKER_NUM` | `0` | Number of RoadRunner task workers for async jobs |
| `LOGS_FORMAT` | `console` | Log format: `console` (human) or `json` (structured) |

### Reverse Proxy

| Variable | Default | Description |
|----------|---------|-------------|
| `TRUSTED_PROXIES` | `1` | Number of proxies in front of Dhiarlink (1 = cloudflared, 2 = CF edge + cloudflared). Can also be comma-separated IPs |
| `CLOUDFLARE_TUNNEL_TOKEN` | — | Token from Cloudflare Zero Trust dashboard (only if using cloudflared as Docker container) |

### Dashboard Pre-Configuration

| Variable | Default | Description |
|----------|---------|-------------|
| `DHIARLINK_SERVER_URL` | `https://www.dhiarr.qzz.io` | Backend URL pre-configured in dashboard |
| `DHIARLINK_SERVER_API_KEY` | — | API key pre-configured in dashboard |
| `DHIARLINK_SERVER_NAME` | `Dhiarlink` | Display name in dashboard |
| `DHIARLINK_SERVER_FORWARD_CREDENTIALS` | `false` | Forward browser credentials |

### Production Database

| Variable | Default | Description |
|----------|---------|-------------|
| `DB_ROOT_PASSWORD` | — | MySQL root password (required for initial setup) |
| `INITIAL_API_KEY` | — | Auto-creates first API key on container startup. Generate: `openssl rand -hex 24` |
| `DHIARLINK_IMAGE` | `dhiarlink:latest` | Custom Docker image name/tag |

---

## API Reference

The full REST API specification is available at:
- **Interactive Swagger UI:** `https://www.dhiarr.qzz.io/docs` (production) or `http://localhost:8005` (development)
- **OpenAPI spec:** `documentation/swagger/swagger.json`
- **AsyncAPI spec:** `documentation/async-api/async-api.json`

### API Versioning

The API uses versioned URL paths:
- `/rest/v3/...` — Current version (recommended)
- `/rest/v2/...` — Legacy (still supported)
- `/rest/v1/...` — Deprecated

### Authentication

All API endpoints (except `/rest/health`) require an `X-Api-Key` header.

```bash
curl -H "X-Api-Key: YOUR_API_KEY" https://www.dhiarr.qzz.io/rest/v3/short-urls
```

API keys can only be generated via the CLI:
```bash
bin/cli api-key:generate
```

---

## URL Structure

| URL Pattern | Handler | Description |
|-------------|---------|-------------|
| `GET /` | `LandingAction` | Terminal-themed landing page |
| `GET /rest/health` | `HealthAction` | Health check (no auth required) |
| `GET /rest/v3/*` | REST API | All API endpoints (auth required) |
| `GET /robots.txt` | `RobotsAction` | Robots.txt with Dhiarlink branding |
| `GET /docs` | Swagger UI | Interactive API documentation |
| `GET /{shortCode}` | `RedirectAction` | Short URL redirect with visit tracking |

---

> This product includes GeoLite2 data created by MaxMind, available from [https://www.maxmind.com](https://www.maxmind.com)

> Dhiarlink is a fork of [Shlink](https://shlink.io) by Alejandro Celaya Alastrue.
