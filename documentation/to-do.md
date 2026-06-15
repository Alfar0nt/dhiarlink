# Dhiarlink Rebrand & Improvement Plan

> Rebranding Shlink → Dhiarlink. Cosmetic and user-facing changes only. Core code structure (namespaces, Composer dependencies, package names) stays untouched.

---

1. ~~Update the landing page terminal animation, because currently in android/mobile device, it will extend far to the right, making it has 2 scrolls horizontal and vertical.~~ ✅ Done — Added `overflow-x: hidden` to html/body, mobile breakpoint wraps terminal text with `word-break: break-all`, smaller font size.
2. ~~Fix deployment and source code overall~~ ✅ DONE
3. ~~Update branding and links like github in the landing page, because currently it still shows the shlink.~~ ✅ Done — GitHub links point to `github.com/Alfar0nt/dhiarlink`
4. ~~Change social links: X/Twitter → Instagram (`instagram.com/dhiarharianto`), Mastodon → Saweria donate (`saweria.co/dhiarharianto`)~~ ✅ Done — Updated SVG icons and links in landing page footer.
5. ~~Optimize backend/API performance and add bare metal deployment~~ ✅ Done — Created missing production configs (RoadRunner `.rr.yml`, Caddyfile, docker-compose.prod.yml, .env.example), added OPcache preloading, created bare metal deployment guide with systemd services, Docker uninstall instructions.

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

## 2. Performance, Speed & UX Improvements ✅ DONE

### Performance & Speed

| # | Improvement | Status | Details |
|---|-------------|--------|--------|
| 1 | **Add OPcache configuration** | ✅ Done | Production `docker/config/php.ini` fully tuned (opcache.enable=1, memory=256MB, validate_timestamps=0, interned_strings_buffer=16). Dev `data/infra/php.ini` gets OPcache with validate_timestamps=1. APCu added to production Dockerfile for metadata caching. Session security hardened. |
| 2 | **Enable HTTP compression in RoadRunner** | ✅ Done | `gzip` added to middleware array in all three RoadRunner configs (`.rr.yml`, `.rr.dev.yml`, `.rr.test.yml`). |
| 3 | **Tune RoadRunner worker pool** | ✅ Done | Production `max_jobs` increased from 250 → 500. Added `supervisor` config for both HTTP and jobs pools: memory limits (128MB HTTP / 256MB jobs), idle TTL, exec TTL, watch tick. |
| 4 | **Optimize Dockerfile layers** | ✅ Done | All `docker-php-ext-install` calls batched into a single parallel compilation. APCu added as a separate layer for metadata caching. Production image has sqlite-libs combined with other runtime deps. |
| 5 | **Add Doctrine query caching** | ✅ Done | APCu installed in production Docker image for fast in-process metadata caching. Entity-manager config documented. Redis-backed result cache available via shlink-common when `REDIS_SERVERS` is set. |
| 6 | **Add DNS prefetch/preconnect headers** | ✅ Done | `RedirectResponseHelper.php` now adds `Link: <scheme://host>; rel=dns-prefetch` header to all redirect responses, reducing redirect chain latency. |
| 7 | **Batch dev Dockerfile extension installs** | ✅ Done | Both `roadrunner.Dockerfile` and `frankenphp.Dockerfile` consolidated: ~12 separate `RUN` commands → 2 (one for all extensions + one for PIE/Xdebug). |
| 8 | **Add OPcache preloading** | ✅ Done | Created `config/opcache-preload.php` that reads Composer's `autoload_classmap.php` and preloads all classes via `opcache_compile_file()`. Added `opcache.preload` directive to production `php.ini`. |
| 9 | **Create production RoadRunner config** | ✅ Done | Created `config/roadrunner/.rr.yml` with worker pool tuning: `num_workers: 0` (auto), `max_jobs: 500`, supervisor (128MB HTTP / 256MB jobs), `prefetch: 100`. Env var overrides supported. |
| 10 | **Remove double compression** | ✅ Done | Removed `gzip` from RoadRunner production middleware — Caddy handles all compression with `encode gzip zstd`. |
| 11 | **Bare metal deployment guide** | ✅ Done | Complete guide in `deployment.md`: PHP 8.4, MariaDB, Redis 7.4, Caddy 2, RoadRunner, systemd services, nginx dashboard. Debian 13 (Trixie) target. Includes Docker uninstall/migration instructions. |

### Security Hardening

| # | Improvement | Status | Details |
|---|-------------|--------|--------|
| 8 | **Add security headers middleware** | ✅ Done | Created `SecurityHeadersMiddleware` at `module/Core/src/Middleware/`. Adds: `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy`, `Permissions-Policy`, `Content-Security-Policy`, and conditional `Strict-Transport-Security` (HTTPS only). Registered in middleware pipeline. |
| 9 | **Add rate limiting** | ✅ Done | Created `RateLimitMiddleware` at `module/Core/src/Middleware/`. APCu-backed sliding window (30 req/60s), keyed by API key or IP. Returns `429` with `Retry-After`, `X-RateLimit-*` headers. Applied to `CreateShortUrlAction` and `SingleStepCreateShortUrlAction` routes. |
| 10 | **Fix production PHP assertions** | ✅ Done | `zend.assertions=-1` and `assert.exception=0` in production `docker/config/php.ini`. Zero overhead, no internal detail leakage. Dev keeps `zend.assertions=1` for debugging. |

### User Experience

| # | Improvement | Status | Details |
|---|-------------|--------|--------|
| 11 | **Add a base URL landing page** | ⏭️ Deferred | Belongs to Section 3 (Theme). Will be implemented with the full landing page template. |
| 12 | **Redesign error pages** | ✅ Done | Completed in Section 1 (items #6, #7). Terminal-dark-green theme with window chrome, blinking cursor, navigation. |
| 13 | **Enhance health endpoint** | ✅ Done | `HealthAction.php` now returns: DB check status, memory usage (current/peak/limit), PHP version, OPcache stats (hit rate, cached scripts, memory). Overall status uses pass/fail/warn. Added `description` field with app name. |
| 14 | **Add a custom robots.txt action** | ✅ Done | Added Dhiarlink branding header to `RobotsAction.php`. Existing crawl control logic preserved. |
| 15 | **Swagger UI theming** | ✅ Done | Created `docs/swagger/swagger-ui-custom.css` with full Deep Ocean theme override. Mounted into swagger-ui container via docker-compose with `SWAGGER_UI_CONFIG` for deep-linking and auth persistence. |
| 16 | **Add `.env.example` file** | ✅ Done | Created `.env.example` with 104 lines of documented env vars covering: app config, URL shortener, all 5 DB drivers, Redis, Mercure, RabbitMQ, Matomo, GeoLite2, CORS, tracking, redirects, worker tuning, logs. |

---

## 3. Theme: Terminal / Hacker Aesthetic — Deep Ocean Palette ✅ DONE

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

#### A. Landing Page (NEW) ✅ DONE

Created `module/Core/templates/landing.html` — a full landing page with:

- **Navigation bar** with Dhiarlink logo/name, nav links (API Docs, GitHub, Status), and a responsive hamburger menu for mobile
- **Hero section** with a terminal-style typing animation showing `curl -X POST https://dhiar.link/rest/v3/short-urls/shorten`
- **Animated statistics counter** section showing: URLs shortened, redirects served, uptime percentage (pulled from server or placeholder)
- **Features grid** with cards: Lightning Fast Redirects, Detailed Analytics, REST API, Self-Hosted & Private
- **Social media icon links** section (GitHub, Instagram, Saweria donate)
- **Footer** with "Powered by Dhiarlink" and version info

**Responsive requirements:**
- Desktop: full grid layout, side-by-side sections
- Tablet: 2-column grid, stacked hero
- Mobile: single column, hamburger nav, full-width cards, terminal text wraps with `word-break: break-all`, smaller font, `overflow-x: hidden` on html/body to prevent horizontal scroll

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

#### B. Error Pages (MODIFY) ✅ DONE

Modify both `module/Core/templates/404.html` and `module/Core/templates/invalid-short-code.html`:

- Apply the Deep Ocean palette (dark background, teal accents, monospace font)
- Add a terminal-style header: `> error: page_not_found` with blinking cursor
- Include a "Go Home" button linking to `/`
- Add subtle ASCII art or terminal prompt styling
- Keep it lightweight (no external dependencies)
- Ensure WCAG contrast ratios

#### C. Health Endpoint Branding (MODIFY) ✅ DONE

`module/Rest/src/Action/HealthAction.php`:
- Change `links.about` and `links.project` to Dhiarlink URLs
- Consider adding: `service_name`, `uptime_seconds`, `checks.redis` if Redis is configured

#### D. Swagger Docs Branding (MODIFY) ✅ DONE

`docs/swagger/swagger.json`:
- Update `info.title` → `"Dhiarlink"`
- Update `info.description` → `"Dhiarlink — the self-hosted URL shortener"`
- Update `externalDocs` URL

#### E. Favicon (REPLACE) ✅ DONE

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
| 2 | Create the landing page template (`landing.html`) | ✅ Done — Full page with nav, hero typing animation, stats counters, features grid, social links, footer. Responsive + WCAG AA. Mobile overflow fixed: `overflow-x: hidden`, terminal `word-break: break-all`. |
| 3 | Redesign the 404 error page | ✅ Done (Section 1, item #6) |
| 4 | Redesign the invalid-short-code error page | ✅ Done (Section 1, item #7) |
| 5 | Create a new `LandingAction` handler to serve the landing page at base URL | ✅ Done — `LandingAction.php` reads template, injects version, serves at `/`. Registered in routes + dependencies. |
| 6 | Update `AppOptions.php` name to Dhiarlink | ✅ Done (Section 1, item #1) |
| 7 | Update `HealthAction.php` links | ✅ Done (Section 1, item #8) |
| 8 | Update `swagger.json` branding | ✅ Done (Section 1, items #9–10) |
| 9 | Replace favicon | ✅ Done (Section 1, item #15) |
| 10 | Update `docker-compose.yml` container names | ✅ Done (Section 1, item #11) |
| 11 | Update `README.md` and `DEVELOPING.md` | ✅ Done (Section 1, items #16–17) |
| 12 | Update `build.sh` dist naming | ✅ Done (Section 1, item #5) |
| 13 | Add security headers middleware | ✅ Done (Section 2, item #8) |
| 14 | Add rate limiting middleware | ✅ Done (Section 2, item #9) |
| 15 | Tune PHP/RoadRunner performance configs | ✅ Done (Section 2, items #1–7, #10) |
| 16 | Add `.env.example` | ✅ Done (Section 2, item #16) |
