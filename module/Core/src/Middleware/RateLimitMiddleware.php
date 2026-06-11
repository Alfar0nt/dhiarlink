<?php

declare(strict_types=1);

namespace Shlinkio\Shlink\Core\Middleware;

use Fig\Http\Message\StatusCodeInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function apcu_add;
use function apcu_cas;
use function apcu_fetch;
use function apcu_inc;
use function apcu_store;
use function function_exists;
use function microtime;
use function sprintf;
use function substr;

/**
 * Simple sliding-window rate limiter keyed by API key or client IP.
 *
 * Defaults: 30 requests per 60-second window.
 * Uses APCu in production (shared across workers) or an in-memory
 * array fallback for dev environments without APCu.
 */
class RateLimitMiddleware implements MiddlewareInterface, StatusCodeInterface
{
    private const int DEFAULT_MAX_REQUESTS = 30;
    private const int DEFAULT_WINDOW_SECONDS = 60;
    private const string KEY_PREFIX = 'dhiarlink_rl:';

    /** @var array<string, array{count: int, window_start: float}> In-memory fallback when APCu is unavailable */
    private static array $memoryStore = [];

    private readonly bool $apcuAvailable;

    public function __construct(
        private readonly int $maxRequests = self::DEFAULT_MAX_REQUESTS,
        private readonly int $windowSeconds = self::DEFAULT_WINDOW_SECONDS,
    ) {
        $this->apcuAvailable = function_exists('apcu_fetch') && apcu_enabled();
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $key = $this->resolveKey($request);
        $result = $this->increment($key);

        if ($result['exceeded']) {
            return new JsonResponse(
                [
                    'type' => 'RATE_LIMIT_EXCEEDED',
                    'title' => 'Too Many Requests',
                    'status' => self::STATUS_TOO_MANY_REQUESTS,
                    'detail' => sprintf(
                        'Rate limit of %d requests per %d seconds exceeded. Retry after %d seconds.',
                        $this->maxRequests,
                        $this->windowSeconds,
                        $result['retry_after'],
                    ),
                ],
                self::STATUS_TOO_MANY_REQUESTS,
                [
                    'Retry-After' => (string) $result['retry_after'],
                    'X-RateLimit-Limit' => (string) $this->maxRequests,
                    'X-RateLimit-Remaining' => '0',
                    'X-RateLimit-Reset' => (string) $result['reset_at'],
                ],
            );
        }

        // Pass through with rate limit info headers
        $response = $handler->handle($request);
        return $response
            ->withHeader('X-RateLimit-Limit', (string) $this->maxRequests)
            ->withHeader('X-RateLimit-Remaining', (string) ($this->maxRequests - $result['count']))
            ->withHeader('X-RateLimit-Reset', (string) $result['reset_at']);
    }

    private function resolveKey(ServerRequestInterface $request): string
    {
        // Prefer API key if present (set by AuthenticationMiddleware upstream)
        $apiKey = $request->getHeaderLine('X-Api-Key');
        if ($apiKey !== '') {
            return self::KEY_PREFIX . 'key:' . substr($apiKey, 0, 16);
        }

        // Fall back to IP address (set by IpAddress middleware or X-Forwarded-For)
        $ip = $request->getAttribute('ip_address')
            ?? $request->getServerParams()['remote_addr']
            ?? 'unknown';

        return self::KEY_PREFIX . 'ip:' . $ip;
    }

    /**
     * @return array{count: int, exceeded: bool, retry_after: int, reset_at: int}
     */
    private function increment(string $key): array
    {
        $now = microtime(true);

        if ($this->apcuAvailable) {
            return $this->incrementApcu($key, $now);
        }

        return $this->incrementMemory($key, $now);
    }

    /**
     * @return array{count: int, exceeded: bool, retry_after: int, reset_at: int}
     */
    private function incrementApcu(string $key, float $now): array
    {
        $windowKey = $key . ':w';
        $countKey = $key . ':c';

        $windowStart = apcu_fetch($windowKey);
        if ($windowStart === false || ($now - $windowStart) >= $this->windowSeconds) {
            // Start a new window
            apcu_store($windowKey, $now, $this->windowSeconds);
            apcu_store($countKey, 1, $this->windowSeconds);

            return [
                'count' => 1,
                'exceeded' => false,
                'retry_after' => 0,
                'reset_at' => (int) ($now + $this->windowSeconds),
            ];
        }

        $count = apcu_inc($countKey);
        $resetAt = (int) ($windowStart + $this->windowSeconds);
        $retryAfter = (int) ($resetAt - $now);

        return [
            'count' => $count,
            'exceeded' => $count > $this->maxRequests,
            'retry_after' => max(0, $retryAfter),
            'reset_at' => $resetAt,
        ];
    }

    /**
     * @return array{count: int, exceeded: bool, retry_after: int, reset_at: int}
     */
    private function incrementMemory(string $key, float $now): array
    {
        $entry = self::$memoryStore[$key] ?? null;

        if ($entry === null || ($now - $entry['window_start']) >= $this->windowSeconds) {
            self::$memoryStore[$key] = ['count' => 1, 'window_start' => $now];
            return [
                'count' => 1,
                'exceeded' => false,
                'retry_after' => 0,
                'reset_at' => (int) ($now + $this->windowSeconds),
            ];
        }

        self::$memoryStore[$key]['count']++;
        $count = self::$memoryStore[$key]['count'];
        $resetAt = (int) ($entry['window_start'] + $this->windowSeconds);
        $retryAfter = (int) ($resetAt - $now);

        return [
            'count' => $count,
            'exceeded' => $count > $this->maxRequests,
            'retry_after' => max(0, $retryAfter),
            'reset_at' => $resetAt,
        ];
    }
}
