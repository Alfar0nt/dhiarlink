# Dhiarlink

> A self-hosted URL shortener with CLI and REST interfaces, forked from [Shlink](https://shlink.io).

A PHP-based self-hosted URL shortener that can be used to serve shortened URLs under your own domain.

**Landing page:** [https://dhiarr.qzz.io](https://dhiarr.qzz.io)
**Dashboard:** [https://app.dhiarr.qzz.io](https://app.dhiarr.qzz.io)

## Table of Contents

- [Tech Stack](#tech-stack)
- [Dependencies](#dependencies)
- [Local Development Setup](#local-development-setup)
    - [Prerequisites](#prerequisites)
    - [Quick Start](#quick-start)
    - [Available Ports & Services](#available-ports--services)
    - [Common Commands](#common-commands)
    - [Running Tests](#running-tests)
- [Production Deployment](#production-deployment)
    - [Using Docker](#using-docker)
    - [Self-Hosted (Bare Metal)](#self-hosted-bare-metal)
    - [Environment Variables](#environment-variables)
- [Using Dhiarlink](#using-dhiarlink)
- [Project Structure](#project-structure)
- [Contributing](#contributing)

---

## Tech Stack

| Layer | Technology | Purpose |
|-------|-----------|---------|
| **Language** | PHP 8.4 / 8.5 | Core application language |
| **App Server** | [RoadRunner](https://roadrunner.dev) | High-performance PHP application server (keeps workers alive, no cold starts) |
| **Alternative Server** | [FrankenPHP](https://frankenphp.dev) | Modern PHP app server built on Caddy (available as alternative runtime) |
| **Web Server** | Nginx 1.25 | Reverse proxy and static file serving (used with PHP-FPM setup) |
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

### Supported Databases

MySQL 8.0, MariaDB 12.3, PostgreSQL 16.3, Microsoft SQL Server 2022, SQLite

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

### PHP Extensions Required

`pdo`, `pdo_mysql`, `pdo_pgsql`, `pdo_sqlite`, `intl`, `mbstring`, `curl`, `sockets`, `bcmath`, `zip`, `calendar`, `apcu` (production), `xdebug` (development only)

---

## Local Development Setup

### Prerequisites

The only system dependency is [Docker](https://docs.docker.com/get-docker/) (with Docker Compose v2+).

All other dependencies (PHP, databases, Redis, Mercure, etc.) are provided as Docker containers.

### Quick Start

```bash
# 1. Clone the repository
git clone <your-repo-url> dhiarlink
cd dhiarlink

# 2. Create your local dev config (edit as needed)
cp config/params/dhiarlink_dev_env.php.dist config/params/dhiarlink_dev_env.php

# 3. Start all containers (first run takes a while to build images)
docker compose up

# 4. In another terminal, create the database and run migrations
./indocker bin/cli db:create
./indocker bin/cli db:migrate

# 5. Generate your first API key
./indocker bin/cli api-key:generate
```

> **Note:** The `./indocker` script is a helper that runs commands inside the RoadRunner container. If containers aren't running yet, it will start them automatically.

That's it. The app is now running locally.

### Available Ports & Services

| Port | Service | URL |
|------|---------|-----|
| `8800` | **RoadRunner** (primary) | http://localhost:8800 |
| `8008` | **FrankenPHP** (alternative) | http://localhost:8008 |
| `8000` | **Nginx + PHP-FPM** (alternative) | http://localhost:8000 |
| `8005` | **Swagger UI** (API docs) | http://localhost:8005 |
| `3307` | MySQL 8.0 | `localhost:3307` |
| `3308` | MariaDB 12.3 | `localhost:3308` |
| `5434` | PostgreSQL 16.3 | `localhost:5434` |
| `1433` | MSSQL Server 2022 | `localhost:1433` |
| `6380` | Redis (open) | `localhost:6380` |
| `6382` | Redis (ACL-protected) | `localhost:6382` |
| `8002` | Mercure proxy | http://localhost:8002 |
| `3080` | Mercure hub (direct) | http://localhost:3080 |
| `15673` | RabbitMQ management UI | http://localhost:15673 |
| `5673` | RabbitMQ AMQP | `localhost:5673` |
| `8003` | Matomo analytics | http://localhost:8003 |

The default dev config uses **MySQL on port 3307** with the database name `dhiarlink`. Edit `config/params/dhiarlink_dev_env.php` to switch to a different driver.

### Common Commands

```bash
# Start all dev containers
docker compose up

# Start in background (detached)
docker compose up -d

# Stop all containers
docker compose down

# Rebuild containers after Dockerfile changes
docker compose build

# Run commands inside the RoadRunner container
./indocker bin/cli                          # List all CLI commands
./indocker bin/cli db:create                 # Create database
./indocker bin/cli db:migrate                # Run migrations
./indocker bin/cli api-key:generate          # Generate API key
./indocker bin/cli short-url:create          # Create a short URL interactively
./indocker composer install                  # Install/update PHP dependencies

# Code quality checks
./indocker composer cs                       # Check coding standards
./indocker composer cs:fix                   # Fix coding standards
./indocker composer stan                     # Static analysis with PHPStan
```

### Running Tests

```bash
# Run unit tests
./indocker composer test:unit

# Run database tests (all engines in parallel)
./indocker composer test:db

# Run database tests for a specific engine
./indocker composer test:db:mysql
./indocker composer test:db:postgres
./indocker composer test:db:maria
./indocker composer test:db:sqlite
./indocker composer test:db:ms

# Run API end-to-end tests
./indocker composer test:api

# Run CLI end-to-end tests
./indocker composer test:cli

# Run everything (CI mode)
./indocker composer ci
```

---

## Production Deployment

### Using Docker

The simplest way to deploy Dhiarlink in production is using the official Docker image:

```bash
docker run -d \
    --name dhiarlink \
    -p 8080:8080 \
    -e DEFAULT_DOMAIN=dhiarr.qzz.io \
    -e IS_HTTPS_ENABLED=true \
    -e DB_DRIVER=mysql \
    -e DB_HOST=your-db-host \
    -e DB_NAME=dhiarlink \
    -e DB_USER=dhiarlink \
    -e DB_PASSWORD=your-secure-password \
    dhiarlink:latest
```

Or with Docker Compose — create a minimal `docker-compose.yml` for production:

```yaml
services:
    dhiarlink:
        image: dhiarlink:latest
        ports:
            - "8080:8080"
        environment:
            DEFAULT_DOMAIN: dhiarr.qzz.io
            IS_HTTPS_ENABLED: "true"
            DB_DRIVER: mysql
            DB_HOST: db
            DB_NAME: dhiarlink
            DB_USER: dhiarlink
            DB_PASSWORD: "${DB_PASSWORD}"
        depends_on:
            - db

    db:
        image: mysql:8.0
        environment:
            MYSQL_ROOT_PASSWORD: "${DB_ROOT_PASSWORD}"
            MYSQL_DATABASE: dhiarlink
            MYSQL_USER: dhiarlink
            MYSQL_PASSWORD: "${DB_PASSWORD}"
        volumes:
            - db_data:/var/lib/mysql

volumes:
    db_data:
```

Then put it behind a reverse proxy (nginx, Caddy, Traefik) that handles TLS termination and domain routing.

### Self-Hosted (Bare Metal)

Requirements:
- PHP 8.4 or 8.5
- PHP extensions: `pdo`, `curl`, `mbstring`, `intl`, `sockets`, `bcmath`, `zip`, `calendar`
- APCu extension (recommended for production caching)
- One of: MySQL, MariaDB, PostgreSQL, MSSQL, or SQLite
- A web server (nginx recommended) or RoadRunner binary

**Steps:**

```bash
# 1. Download a dist file from releases, or build from source:
git clone <repo-url> dhiarlink && cd dhiarlink
curl -sS https://getcomposer.org/download/composer.phar -o composer.phar
php composer.phar install --no-dev --prefer-dist --optimize-autoloader

# 2. Run the interactive installer
vendor/bin/shlink-installer install

# 3. Generate your first API key
bin/cli api-key:generate

# 4. (Optional) Set up RoadRunner as the app server
php vendor/bin/rr get --location bin/
bin/rr serve -c config/roadrunner/.rr.yml
```

### Environment Variables

See [.env.example](.env.example) for a complete reference of all available environment variables.

Key variables for production:

| Variable | Default | Description |
|----------|---------|-------------|
| `DEFAULT_DOMAIN` | — | Domain used for generated short URLs (e.g., `link.dhiarr.qzz.io`) |
| `IS_HTTPS_ENABLED` | `false` | Whether to generate `https://` short URLs |
| `DB_DRIVER` | `mysql` | Database driver: `mysql`, `maria`, `postgres`, `mssql`, `sqlite` |
| `DB_HOST` | — | Database hostname |
| `DB_NAME` | — | Database name |
| `DB_USER` | — | Database user |
| `DB_PASSWORD` | — | Database password |
| `REDIS_SERVERS` | — | Redis connection string(s) for caching and pub/sub |
| `MERCURE_ENABLED` | `false` | Enable real-time visit updates via Mercure |
| `GEOLITE_LICENSE_KEY` | — | MaxMind license key for IP geolocation |
| `DISABLE_TRACKING` | `false` | Disable all visit tracking for privacy |
| `WEB_WORKER_NUM` | `0` (auto) | Number of RoadRunner web workers (0 = CPU cores) |
| `TASK_WORKER_NUM` | `0` (auto) | Number of RoadRunner task workers for async jobs |

---

## Using Dhiarlink

Once Dhiarlink is installed, there are two main ways to interact with it:

* **The command line**: Run `bin/cli` to see all available commands. All commands support `--help`/`-h` for usage details. It's a good idea to symlink `bin/cli` to somewhere in your PATH.

* **The REST API**: Full API docs are available at [https://dhiarr.qzz.io/docs](https://dhiarr.qzz.io/docs). You can also browse the interactive Swagger UI at `http://localhost:8005` during development.

    The [shlink-web-client](https://github.com/shlinkio/shlink-web-client) is a React-based dashboard that connects to the REST API, available at [https://app.dhiarr.qzz.io](https://app.dhiarr.qzz.io) or self-hosted.

Both the API and CLI allow you to do mostly the same operations, except for API key management, which can only be done from the CLI.

---

## Project Structure

```
dhiarlink
├── bin/                  CLI entry point and worker scripts
├── config/
│   ├── autoload/         Merged application config (routes, middleware, cache, etc.)
│   ├── params/           Local dev config (git-ignored)
│   ├── roadrunner/       RoadRunner server configs (.rr.yml)
│   ├── config.php        Config aggregator root
│   └── container.php     DI container bootstrap
├── data/                 Runtime-writable: cache, logs, locks, proxies, migrations
├── docs/
│   ├── adr/              Architectural Decision Records
│   ├── async-api/        AsyncAPI spec
│   └── swagger/          OpenAPI/Swagger spec and custom UI theme
├── docker/               Production Docker entrypoint and PHP config
├── module/
│   ├── CLI/              Command-line interface commands and config
│   ├── Core/             Domain logic: redirects, visits, short URLs, templates
│   └── Rest/             REST API actions, middleware, and config
├── public/               Web entry point, favicon, .htaccess
├── Dockerfile            Production Docker image (multi-stage, PHP 8.5 + RoadRunner)
├── docker-compose.yml    Full dev environment (all services)
├── composer.json         PHP dependencies and scripts
└── .env.example          Environment variable reference
```

---

## Contributing

If you want to run the project in development mode or provide contributions, read the [DEVELOPING](DEVELOPING.md) doc for detailed instructions on testing, code style, and architecture.

---

> This product includes GeoLite2 data created by MaxMind, available from [https://www.maxmind.com](https://www.maxmind.com)

> Dhiarlink is a fork of [Shlink](https://shlink.io) by Alejandro Celaya Alastrué.
