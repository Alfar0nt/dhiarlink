# Dhiarlink Rebrand & Improvement Plan

> Rebranding Shlink → Dhiarlink. Cosmetic and user-facing changes only. Core code structure (namespaces, Composer dependencies, package names) stays untouched.

---

## 1. Core Branding Files to Change ✅ DONE

These are all the files that contain user-facing or cosmetic "Shlink" branding that should be updated to "Dhiarlink".

### App Identity & Configuration

| # | File | Status |
|---|------|--------|
| 1 | `module/Core/src/Config/Options/AppOptions.php` | ✅ `name = 'Dhiarlink'`, placeholder → `%DHIARLINK_VERSION%` |
| 2 | `composer.json` | ✅ Homepage → `dhiarr.qzz.io`, description updated |
| 3 | `Dockerfile` | ✅ Env vars → `DHIARLINK_*`, maintainer, paths → `/etc/dhiarlink` |
| 4 | `docker/docker-entrypoint.sh` | ✅ Path, comments, env var → `DHIARLINK_RUNTIME` |
| 5 | `build.sh` | ✅ `distId="dhiarlink${version}..."`, sed placeholder updated |

### HTML Templates (Error Pages)

| # | File | Status |
|---|------|--------|
| 6 | `module/Core/templates/404.html` | ✅ Rewritten with terminal-dark-green theme, Dhiarlink branding |
| 7 | `module/Core/templates/invalid-short-code.html` | ✅ Rewritten with terminal-dark-green theme, Dhiarlink branding |

### REST API Branding

| # | File | Status |
|---|------|--------|
| 8 | `module/Rest/src/Action/HealthAction.php` | ✅ `about` → `https://dhiarr.qzz.io` |

### API Documentation

| # | File | Status |
|---|------|--------|
| 9 | `docs/swagger/swagger.json` | ✅ Title → `Dhiarlink`, description updated |
| 10 | `docs/swagger/swagger.json` | ✅ `externalDocs.url` → `https://dhiarr.qzz.io/docs` |

### Docker & Infrastructure (Cosmetic)

| # | File | Status |
|---|------|--------|
| 11 | `docker-compose.yml` + `docker-compose.ci.yml` | ✅ All containers → `dhiarlink_*`, DB names, paths, Mercure CORS |
| 12 | `config/roadrunner/.rr.yml` | ✅ Pipeline → `dhiarlink` |
| 13 | `config/roadrunner/.rr.dev.yml` + `.rr.test.yml` | ✅ Pipeline → `dhiarlink` |
| 14 | `config/autoload/dependencies.global.php` | ✅ `DhiarlinkProxy` |
| — | `config/autoload/logger.global.php` | ✅ `DHIARLINK_RUNTIME` env var reference |
| — | `data/infra/*.Dockerfile` + `vhost.conf` + `mercure_proxy_vhost.conf` | ✅ All paths, maintainer, container refs |
| — | `indocker` script | ✅ Container name → `dhiarlink_roadrunner` |
| — | `config/test/test_config.global.php` | ✅ Container hostnames → `dhiarlink_db_*` |
| — | `.github/workflows/*.yml` | ✅ Container names and dist patterns |
| — | `config/params/dhiarlink_dev_env.php.dist` | ✅ Renamed from `shlink_dev_env.php.dist` |

### Static Assets

| # | File | Status |
|---|------|--------|
| 15 | `public/favicon.svg` | ✅ Created terminal-prompt SVG favicon (`>_` in teal on dark bg) |

### Documentation (Cosmetic)

| # | File | Status |
|---|------|--------|
| 16 | `README.md` | ✅ Rewritten with Dhiarlink branding, URLs, fork attribution |
| 17 | `DEVELOPING.md` | ✅ All Shlink references → Dhiarlink, config filenames updated |
| 18 | `CHANGELOG.md` | ✅ Added `[Unreleased] — Dhiarlink Fork` entry |

### DO NOT Change (Core Internals)

- PHP namespaces (`Shlinkio\Shlink\*`) — breaking autoloading
- Composer package dependencies (`shlinkio/shlink-common`, etc.) — these are upstream packages
- Composer autoload PSR-4 mappings
- Internal class names, method names, variable names
- Test namespaces and test fixtures (internal only)

---

## 2. Performance, Speed & UX Improvements

### Performance & Speed

| # | Improvement | Details |
|---|-------------|---------|
| 1 | **Add OPcache configuration** | The production `docker/config/php.ini` has zero performance tuning. Add: `opcache.enable=1`, `opcache.memory_consumption=256`, `opcache.max_accelerated_files=20000`, `opcache.validate_timestamps=0` for production. Create a separate `php.ini` for dev vs prod. |
| 2 | **Enable HTTP compression in RoadRunner** | `.rr.yml` doesn't enable gzip/brotli. Add `'gzip'` to the middleware array: `middleware: ['static', 'gzip']` to reduce API response sizes. |
| 3 | **Tune RoadRunner worker pool** | `max_jobs: 250` is conservative for a URL shortener. Increase to 500+ and set `num_workers` to match CPU cores. Add `supervisor` config for automatic worker restart on memory thresholds. |
| 4 | **Optimize Dockerfile layers** | The Dockerfile installs all DB drivers (MySQL, Postgres, SQLite, MSSQL) in every image. Consider multi-stage builds or build-arg driven driver selection to reduce image size. Batch `docker-php-ext-install` calls. |
| 5 | **Add Doctrine query caching** | `entity-manager.global.php` doesn't configure second-level cache or query result cache. Enable Doctrine's result cache backed by Redis for frequently resolved short URLs. |
| 6 | **Add DNS prefetch/preconnect headers** | For short URLs that redirect to external domains, add `Link: <https://target-domain>; rel=dns-prefetch` headers to speed up the redirect chain for end users. |
| 7 | **Batch dev Dockerfile extension installs** | `data/infra/roadrunner.Dockerfile` calls `docker-php-ext-install` multiple times. Batch into a single call for faster builds. |

### Security Hardening

| # | Improvement | Details |
|---|-------------|---------|
| 8 | **Add security headers middleware** | No middleware adds `X-Content-Type-Options`, `X-Frame-Options`, `Strict-Transport-Security`, or `Content-Security-Policy`. Create a custom middleware to inject these on all responses. |
| 9 | **Add rate limiting** | No rate limiter exists in the middleware pipeline. Add rate limiting on `/rest/v*/short-urls` (create) and `/rest/v*/short-urls/shorten` endpoints to prevent abuse. Use Redis-backed token bucket. |
| 10 | **Fix production PHP assertions** | `docker/config/php.ini` has `zend.assertions=1` and `assert.exception=1` which is dev-level verbosity. Set `zend.assertions=-1` in production to prevent internal detail leakage. |

### User Experience

| # | Improvement | Details |
|---|-------------|---------|
| 11 | **Add a base URL landing page** | Currently visiting the root domain triggers a "base URL" not-found. Create a proper landing page with Dhiarlink branding, terminal-dark-green theme, explaining what the service is. Include stats, social links, and a responsive mobile menu. |
| 12 | **Redesign error pages** | Both `404.html` and `invalid-short-code.html` are extremely plain. Redesign with the terminal/hacker aesthetic, include helpful navigation, and make them visually appealing. |
| 13 | **Enhance health endpoint** | `HealthAction.php` only checks DB connectivity. Add Redis availability check, uptime counter, memory usage, and version info for better monitoring. |
| 14 | **Add a custom robots.txt action** | `RobotsAction.php` exists but the default behavior could be improved to explicitly allow/disallow based on Dhiarlink's SEO strategy. |
| 15 | **Swagger UI theming** | The Swagger UI container uses the default theme. Inject custom CSS via a wrapper HTML file for the terminal-dark-green theme. |
| 16 | **Add `.env.example` file** | Configuration is done through a complex PHP installer and scattered env vars. A clean `.env.example` with documented variables would make setup much faster for new users. |

---

## 3. Theme: Terminal / Hacker Aesthetic — Deep Ocean Palette

### Design Tokens

```
Background:    #0f1419  (very dark teal-gray)
Card Surface:  #192028  (dark card/panel)
Accent:        #4a9a8e  (muted teal)
Accent Hover:  #5cc4b3  (brighter teal on hover)
Text Primary:  #a8b2c1  (silver)
Text Muted:    #6b7a8d  (dimmed silver)
Success:       #4ade80  (terminal green)
Error:         #f87171  (soft red)
Border:        #2a3440  (subtle border)
Font:          "JetBrains Mono", "Fira Code", "SF Mono", monospace
```

### Files to Create / Modify

#### A. Landing Page (NEW)

Create `module/Core/templates/landing.html` — a full landing page with:

- **Navigation bar** with Dhiarlink logo/name, nav links (API Docs, GitHub, Status), and a responsive hamburger menu for mobile
- **Hero section** with a terminal-style typing animation showing `curl -X POST https://dhiar.link/rest/v3/short-urls/shorten`
- **Animated statistics counter** section showing: URLs shortened, redirects served, uptime percentage (pulled from server or placeholder)
- **Features grid** with cards: Lightning Fast Redirects, Detailed Analytics, REST API, Self-Hosted & Private
- **Social media icon links** section (GitHub, Twitter/X, Mastodon — with placeholder hrefs)
- **Footer** with "Powered by Dhiarlink" and version info

**Responsive requirements:**
- Desktop: full grid layout, side-by-side sections
- Tablet: 2-column grid, stacked hero
- Mobile: single column, hamburger nav, full-width cards

**Accessibility (WCAG 2.1 AA):**
- Color contrast ratio ≥ 4.5:1 for all text (silver `#a8b2c1` on `#0f1419` = ~8.2:1 ✓)
- All interactive elements keyboard-accessible with visible focus rings
- Semantic HTML: `<nav>`, `<main>`, `<section>`, `<footer>`, proper heading hierarchy
- `aria-label` on icon-only links, `aria-expanded` on hamburger toggle
- `prefers-reduced-motion` media query to disable animations

**Interactive features:**
- Smooth scroll between sections
- Counter animation using `IntersectionObserver` (counts up when scrolled into view)
- CSS transitions on hover/focus states (0.2s ease)
- Hamburger menu toggle with CSS + minimal JS

#### B. Error Pages (MODIFY)

Modify both `module/Core/templates/404.html` and `module/Core/templates/invalid-short-code.html`:

- Apply the Deep Ocean palette (dark background, teal accents, monospace font)
- Add a terminal-style header: `> error: page_not_found` with blinking cursor
- Include a "Go Home" button linking to `/`
- Add subtle ASCII art or terminal prompt styling
- Keep it lightweight (no external dependencies)
- Ensure WCAG contrast ratios

#### C. Health Endpoint Branding (MODIFY)

`module/Rest/src/Action/HealthAction.php`:
- Change `links.about` and `links.project` to Dhiarlink URLs
- Consider adding: `service_name`, `uptime_seconds`, `checks.redis` if Redis is configured

#### D. Swagger Docs Branding (MODIFY)

`docs/swagger/swagger.json`:
- Update `info.title` → `"Dhiarlink"`
- Update `info.description` → `"Dhiarlink — the self-hosted URL shortener"`
- Update `externalDocs` URL

#### E. Favicon (REPLACE)

`public/favicon.ico`:
- Replace with a terminal-green themed icon (e.g., a `>_` prompt symbol or a link/chain icon in teal `#4a9a8e` on dark `#0f1419`)

### Template Code Style Guide

All HTML templates should follow these conventions:

```
- Self-contained: inline CSS only, no external CDN dependencies
- Monospace font stack with system fallbacks
- CSS custom properties for the color palette
- Minified-friendly but readable structure
- Mobile-first responsive design
- No JavaScript frameworks — vanilla JS only for interactions
- Semantic HTML5 elements throughout
```

### Implementation Order

| # | Task | Status |
|---|------|--------|
| 1 | Define CSS custom properties (design tokens) as a shared snippet | ✅ Done — `module/Core/templates/_theme.css` |
| 2 | Create the landing page template (`landing.html`) | ⬜ Pending (Section 3) |
| 3 | Redesign the 404 error page | ✅ Done (Section 1, item #6) |
| 4 | Redesign the invalid-short-code error page | ✅ Done (Section 1, item #7) |
| 5 | Create a new `LandingAction` handler to serve the landing page at base URL | ⬜ Pending (Section 3) |
| 6 | Update `AppOptions.php` name to Dhiarlink | ✅ Done (Section 1, item #1) |
| 7 | Update `HealthAction.php` links | ✅ Done (Section 1, item #8) |
| 8 | Update `swagger.json` branding | ✅ Done (Section 1, items #9–10) |
| 9 | Replace favicon | ✅ Done (Section 1, item #15) |
| 10 | Update `docker-compose.yml` container names | ✅ Done (Section 1, item #11) |
| 11 | Update `README.md` and `DEVELOPING.md` | ✅ Done (Section 1, items #16–17) |
| 12 | Update `build.sh` dist naming | ✅ Done (Section 1, item #5) |
| 13 | Add security headers middleware | ⬜ Pending (Section 2) |
| 14 | Add rate limiting middleware | ⬜ Pending (Section 2) |
| 15 | Tune PHP/RoadRunner performance configs | ⬜ Pending (Section 2) |
| 16 | Add `.env.example` | ⬜ Pending (Section 2) |
