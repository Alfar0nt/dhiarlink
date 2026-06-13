# Dhiarlink

> A self-hosted URL shortener with CLI and REST interfaces, forked from [Shlink](https://shlink.io).

Create short URLs under your own domain with full visit tracking, analytics, and a REST API. Built with PHP 8.5, RoadRunner, and Docker.

**Landing page:** [https://www.dhiarr.qzz.io](https://www.dhiarr.qzz.io)
**Dashboard:** [https://app.dhiarr.qzz.io](https://app.dhiarr.qzz.io)
**API docs:** [https://www.dhiarr.qzz.io/docs](https://www.dhiarr.qzz.io/docs)

For detailed technical reference (tech stack, dependencies, environment variables, architecture), see [documentation/docs.md](documentation/docs.md).

---

## Quick Start

The only system dependency is [Docker](https://docs.docker.com/get-docker/) (with Docker Compose v2+).

### Local Development

```bash
# 1. Clone the repository
git clone <your-repo-url> dhiarlink
cd dhiarlink

# 2. Create your local dev config
cp config/params/dhiarlink_dev_env.php.dist config/params/dhiarlink_dev_env.php

# 3. Start all containers
docker compose up

# 4. In another terminal, create the database and generate an API key
./indocker bin/cli db:create
./indocker bin/cli db:migrate
./indocker bin/cli api-key:generate
```

The app is now running at **http://localhost:8800** (RoadRunner). Swagger UI is at **http://localhost:8005**.

See [DEVELOPING.md](documentation/DEVELOPING.md) for full development instructions, ports, testing, and code quality.

### Production Deployment

For a complete production setup (Proxmox LXC + Cloudflare Tunnel), follow the step-by-step guide:

**[deployment.md](documentation/deployment.md)** — Full guide covering Docker Compose, Caddy reverse proxy, cloudflared system service, MySQL, Redis, and the dhiarlink-web-client dashboard.

Quick summary:

```bash
# 1. Clone both repos as siblings
git clone <dhiarlink-repo> dhiarlink
git clone <dhiarlink-web-client-repo> dhiarlink-web-client

# 2. Create .env from the example
cp dhiarlink/.env.example dhiarlink/.env
# Edit .env with your secrets

# 3. Build and start
cd dhiarlink
docker compose -f docker-compose.prod.yml build
docker compose -f docker-compose.prod.yml up -d

# 4. Generate your first API key
docker exec -it dhiarlink bin/cli api-key:generate
```

---

## Using Dhiarlink

Once installed, there are two ways to interact with Dhiarlink:

**Command Line** — Run `bin/cli` (or `./indocker bin/cli` in Docker) to see all available commands:

```bash
bin/cli api-key:generate      # Generate an API key
bin/cli short-url:create       # Create a short URL interactively
bin/cli db:migrate             # Run database migrations
bin/cli visit:list             # List recent visits
```

**REST API** — Full API docs at `/docs` (Swagger UI). Example:

```bash
# Create a short URL
curl -X POST https://www.dhiarr.qzz.io/rest/v3/short-urls/shorten \
  -H "X-Api-Key: YOUR_KEY" \
  -H "Content-Type: application/json" \
  -d '{"longUrl": "https://example.com/very-long-url", "customSlug": "mylink"}'
```

**Dashboard** — The [dhiarlink-web-client](https://github.com/dhiar/dhiarlink-web-client) is a React-based UI that connects to the REST API. Deploy it alongside Dhiarlink and configure it with your API URL and key.

> Both the API and CLI support the same operations, except API key management which is CLI-only.

---

## Contributing

If you want to contribute to Dhiarlink:

1. Fork the repository
2. Follow the [development setup](documentation/DEVELOPING.md)
3. Make your changes
4. Run the test suite: `./indocker composer ci`
5. Submit a pull request

See [DEVELOPING.md](documentation/DEVELOPING.md) for testing, code style, and architecture details.

---

## Documentation

| Document | Description |
|----------|-------------|
| [docs.md](documentation/docs.md) | Comprehensive project reference: tech stack, dependencies, architecture, environment variables |
| [DEVELOPING.md](documentation/DEVELOPING.md) | Development guide: setup, testing, code quality, project structure |
| [deployment.md](documentation/deployment.md) | Production deployment: Proxmox LXC, Cloudflare Tunnel, Docker Compose |
| [qna.md](documentation/qna.md) | Q&A: how the URL shortener works, deployment patterns, technical flow |
| [to-do.md](documentation/to-do.md) | Rebranding checklist and improvement roadmap |
| [prompt-history.md](documentation/prompt-history.md) | Development session history: prompts, decisions, and outcomes |

---

> This product includes GeoLite2 data created by MaxMind, available from [https://www.maxmind.com](https://www.maxmind.com)

> Dhiarlink is a fork of [Shlink](https://shlink.io) by Alejandro Celaya Alastrue.
