# Dhiarlink Deployment Q&A — How It All Works

---

## Q1: Will `www.dhiarr.qzz.io` show a landing page like shlink.io?

**Yes.** The Dhiarlink backend currently does NOT have a built-in landing page — visiting the root URL (`/`) returns a "base URL not found" response. We're building one in Section 3 of the to-do list.

Once done:
- Visiting **`www.dhiarr.qzz.io`** in a browser will show the Dhiarlink landing page (hero section, features, stats, etc.)
- The **REST API** still works at `www.dhiarr.qzz.io/rest/v3/...`
- **Short URLs** also work at `www.dhiarr.qzz.io/abc123` (they redirect to the long URL)

So the landing page, the API, and the short URL redirects all live on the **same domain and same server**.

---

## Q2: Is `app.dhiarr.qzz.io` the dashboard for managing links?

**Yes, but there's an important distinction to understand:**

Dhiarlink (the project in this repo) is **only the backend**. It provides:
- The REST API (create links, get stats, manage tags, etc.)
- The redirect engine (short URL → long URL)
- Error pages and health checks

It does **NOT** include a web UI / dashboard. The dashboard is a **separate project** called [dhiarlink-web-client](https://github.com/dhiar/dhiarlink-web-client) — a React app that connects to the Dhiarlink REST API.

So your deployment would look like:

```
www.dhiarr.qzz.io      → Dhiarlink backend (landing page + API + redirect engine)
app.dhiarr.qzz.io      → dhiarlink-web-client (React dashboard, deployed separately)
```

The web client just needs to know where the API lives. You configure it with:
```
API URL: https://www.dhiarr.qzz.io/rest
API Key: <your-generated-api-key>
```

---

## Q3: Can I use `link.dhiarr.qzz.io` for my short URLs?

**Yes, absolutely!** And this is actually the recommended approach for a clean setup.

Here's how the domain split would work:

| Domain | Purpose | What runs here |
|--------|---------|----------------|
| `www.dhiarr.qzz.io` | Landing page + API | Dhiarlink backend |
| `app.dhiarr.qzz.io` | Dashboard | dhiarlink-web-client (React) |
| `link.dhiarr.qzz.io` | Short URLs | **Also Dhiarlink backend** (same server) |

### How to set this up:

You point **both** `www.dhiarr.qzz.io` and `link.dhiarr.qzz.io` to the same Dhiarlink server (via CNAME records for Cloudflare Tunnel). Then:

1. Set `DEFAULT_DOMAIN=link.dhiarr.qzz.io` in your environment config
2. Dhiarlink generates short URLs like: `https://link.dhiarr.qzz.io/spotify`
3. When someone visits `link.dhiarr.qzz.io/spotify`, Dhiarlink:
   - Looks up the short code `spotify` in the database
   - Tracks the visit (IP, referrer, user agent, geolocation)
   - Returns a `302 Redirect` to your long Spotify URL
   - The browser follows the redirect

You can also add `dhiarr.qzz.io` as an additional domain in the dashboard, so you could have short URLs on both domains if you wanted.

### Example short URLs:

```
link.dhiarr.qzz.io/spotify    → https://open.spotify.com/user/your-long-profile-id?si=...
link.dhiarr.qzz.io/gh         → https://github.com/yourusername
link.dhiarr.qzz.io/resume     → https://docs.google.com/document/d/your-long-doc-id
```

---

## Q4: How does the whole URL shortener flow work?

Here's the complete flow in plain English:

### Creating a Short URL

```
You (in the dashboard)                    Dhiarlink Server                    Database
       |                                        |                               |
       |  1. "Create short URL for              |                               |
       |     spotify.com/my-long-url            |                               |
       |     with custom slug 'spotify'"        |                               |
       |--------------------------------------->|                               |
       |                                        |  2. Check if 'spotify'        |
       |                                        |     slug already exists       |
       |                                        |------------------------------>|
       |                                        |                               |
       |                                        |  3. Nope, it's free!          |
       |                                        |<------------------------------|
       |                                        |                               |
       |                                        |  4. Save the mapping:         |
       |                                        |     "spotify" → long URL      |
       |                                        |------------------------------>|
       |                                        |                               |
       |  5. "Here's your short URL:            |                               |
       |     link.dhiarr.qzz.io/spotify"        |                               |
       |<---------------------------------------|                               |
```

### Someone Clicks Your Short Link

```
Visitor's Browser          Dhiarlink Server          Database          Matomo (optional)
       |                         |                      |                    |
       |  1. GET /spotify        |                      |                    |
       |------------------------>|                      |                    |
       |                         |  2. Look up "spotify"|                    |
       |                         |--------------------->|                    |
       |                         |                      |                    |
       |                         |  3. Found it!        |                    |
       |                         |     Long URL =       |                    |
       |                         |     open.spotify.com |                    |
       |                         |<---------------------|                    |
       |                         |                      |                    |
       |                         |  4. Record visit:    |                    |
       |                         |     IP, country,     |                    |
       |                         |     browser, device  |                    |
       |                         |--------------------->|                    |
       |                         |                      |                    |
       |  5. HTTP 302 Redirect   |                      |                    |
       |     Location:           |                      |                    |
       |     open.spotify.com/.. |                      |  6. (async) Send   |
       |<------------------------|                      |     visit event     |
       |                         |                      |------------------->|
       |                         |                      |                    |
       |  7. Browser follows     |                      |                    |
       |     redirect → loads    |                      |                    |
       |     Spotify page        |                      |                    |
```

### What Happens Behind the Scenes (Technical Flow)

When a request hits the Dhiarlink server, it goes through this **middleware pipeline**:

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
    │       └── If /{shortCode}  → Redirect flow:
    │               │
    │               ├── IP Address extraction
    │               ├── Geolocation lookup
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

### Key Components

| Component | What it does |
|-----------|-------------|
| **RoadRunner** | High-performance PHP application server. Keeps PHP workers alive (no cold starts like traditional PHP-FPM). Handles HTTP and async job processing. |
| **Doctrine ORM** | Database layer. Talks to MySQL/PostgreSQL/MariaDB/MSSQL/SQLite. Manages short URL records, visit records, tags, API keys, domains. |
| **Redis** (optional) | Used for: (1) pub/sub to notify the web client about new visits in real-time, (2) caching frequently accessed data. |
| **Mercure** (optional) | Server-Sent Events (SSE) hub. Pushes real-time visit updates to the dashboard without polling. |
| **Matomo** (optional) | External analytics. Dhiarlink can forward visit data to Matomo for deeper analytics. |
| **RabbitMQ** (optional) | Message queue for async job processing (visit geolocation, webhooks, etc.) as an alternative to RoadRunner's built-in memory queue. |

### Visit Tracking (Async)

Visit tracking happens **asynchronously** so the redirect is blazing fast:

1. User clicks `link.dhiarr.qzz.io/spotify`
2. Dhiarlink immediately returns the redirect (fast!)
3. The visit data (IP, geolocation, user agent, referrer) is pushed to a **job queue**
4. A background worker picks up the job and writes the visit to the database
5. Dashboard shows the visit stats in real-time (via Mercure SSE)

This means the visitor gets redirected instantly without waiting for the database write to complete.

---

## Summary: Your Full Deployment

```
┌─────────────────────────────────────────────────────────────┐
│                    DNS Records                               │
│                                                             │
│  www.dhiarr.qzz.io  → Your server (Dhiarlink backend)       │
│  app.dhiarr.qzz.io  → Your server (dhiarlink-web-client)    │
│  link.dhiarr.qzz.io → Your server (Dhiarlink backend)       │
└─────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────┐
│                    Your Server                               │
│                                                             │
│  ┌─ Reverse Proxy (nginx/Caddy/Traefik) ──────────────────┐ │
│  │                                                        │ │
│  │  www.dhiarr.qzz.io                                   │ │
│  │    /          → Landing page (Section 3)               │ │
│  │    /rest/*    → REST API                               │ │
│  │    /{code}    → Redirect engine                        │ │
│  │                                                        │ │
│  │  link.dhiarr.qzz.io                                    │ │
│  │    /{code}    → Redirect engine (same backend)         │ │
│  │                                                        │ │
│  │  app.dhiarr.qzz.io                                     │ │
│  │    /*         → dhiarlink-web-client (React SPA)        │ │
│  │                                                        │ │
│  └────────────────────────────────────────────────────────┘ │
│                                                             │
│  ┌─ Dhiarlink Docker containers ──────────────────────────┐ │
│  │  • RoadRunner (PHP app server)                         │ │
│  │  • MySQL / PostgreSQL (database)                       │ │
│  │  • Redis (caching + pub/sub)                           │ │
│  │  • Mercure (real-time updates)                         │ │
│  │  • RabbitMQ (async jobs) — optional                    │ │
│  │  • Matomo (analytics) — optional                       │ │
│  └────────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────┘
```

---

## Quick Reference: Environment Variables for This Setup

```env
DEFAULT_DOMAIN=link.dhiarr.qzz.io    # Short URLs use this domain
IS_HTTPS_ENABLED=true                # Generate https:// links
DB_DRIVER=mysql                      # Or postgres
DB_HOST=dhiarlink_db
DB_NAME=dhiarlink
REDIS_SERVERS=tcp://dhiarlink_redis:6379
MERCURE_ENABLED=true
MERCURE_PUBLIC_HUB_URL=https://app.dhiarr.qzz.io/mercure
```
