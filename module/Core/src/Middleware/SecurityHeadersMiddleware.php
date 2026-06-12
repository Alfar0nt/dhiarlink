<?php

declare(strict_types=1);

namespace Shlinkio\Shlink\Core\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Injects standard security headers on every HTTP response.
 *
 * Headers applied:
 *   - X-Content-Type-Options: nosniff       — Prevent MIME-type sniffing
 *   - X-Frame-Options: DENY                 — Prevent click-jacking via iframes
 *   - Referrer-Policy: strict-origin-when-cross-origin — Control referrer leakage
 *   - X-XSS-Protection: 0                   — Disable legacy XSS filter (modern CSP preferred)
 *   - Permissions-Policy: ...               — Restrict browser feature access
 *   - Strict-Transport-Security: ...        — Force HTTPS (only sent over HTTPS connections)
 *   - Content-Security-Policy: ...          — Restrict resource loading (API-safe policy)
 */
class SecurityHeadersMiddleware implements MiddlewareInterface
{
    /**
     * HSTS max-age: 1 year (31536000 seconds).
     * Only sent when the request came over HTTPS.
     */
    private const int HSTS_MAX_AGE = 31_536_000;

    /**
     * Restrictive permissions policy — disable features a URL-shortener API doesn't need.
     */
    private const string PERMISSIONS_POLICY = 'camera=(), microphone=(), geolocation=(), payment=()';

    /**
     * CSP that's safe for JSON API responses and the error/landing HTML pages.
     * - default-src 'none' blocks all resource loading by default
     * - style-src 'unsafe-inline' needed for inline CSS in error/landing pages
     * - script-src 'unsafe-inline' needed for landing page interactions (typing animation, hamburger menu, counters)
     * - img-src 'self' data: allows favicons and data URIs
     * - font-src 'self' allows self-hosted fonts if any
     * - frame-ancestors 'none' reinforces X-Frame-Options for modern browsers
     */
    private const string CSP = "default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:; "
        . "script-src 'unsafe-inline'; font-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'";

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        // Core security headers (always applied)
        $response = $response
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->withHeader('X-XSS-Protection', '0')
            ->withHeader('Permissions-Policy', self::PERMISSIONS_POLICY)
            ->withHeader('Content-Security-Policy', self::CSP);

        // HSTS — only add when the request arrived over HTTPS to avoid breaking plain HTTP dev setups
        if ($request->getUri()->getScheme() === 'https'
            || $request->getHeaderLine('X-Forwarded-Proto') === 'https'
        ) {
            $response = $response->withHeader(
                'Strict-Transport-Security',
                sprintf('max-age=%d; includeSubDomains', self::HSTS_MAX_AGE),
            );
        }

        return $response;
    }
}
