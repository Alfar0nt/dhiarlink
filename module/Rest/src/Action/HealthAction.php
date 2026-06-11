<?php

declare(strict_types=1);

namespace Shlinkio\Shlink\Rest\Action;

use Doctrine\ORM\EntityManagerInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Shlinkio\Shlink\Core\Config\Options\AppOptions;
use Throwable;

use function function_exists;
use function memory_get_peak_usage;
use function memory_get_usage;
use function opcache_get_status;
use function round;

class HealthAction extends AbstractRestAction
{
    private const string HEALTH_CONTENT_TYPE = 'application/health+json';
    private const string STATUS_PASS = 'pass';
    private const string STATUS_FAIL = 'fail';
    private const string STATUS_WARN = 'warn';

    public const string ROUTE_PATH = '/health';
    protected const array ROUTE_ALLOWED_METHODS = [self::METHOD_GET];

    public function __construct(private readonly EntityManagerInterface $em, private readonly AppOptions $options) {}

    /**
     * Handles a request and produces a response.
     *
     * May call other collaborating code to generate the response.
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // Check database connectivity
        try {
            $connection = $this->em->getConnection();
            $connection->executeQuery($connection->getDatabasePlatform()->getDummySelectSQL());
            $dbConnected = true;
        } catch (Throwable) {
            $dbConnected = false;
        }

        // Check OPcache status (available in production Docker images)
        $opcacheStatus = $this->getOpcacheStatus();

        // Determine overall health status
        $overallStatus = $dbConnected ? self::STATUS_PASS : self::STATUS_FAIL;
        if ($dbConnected && $opcacheStatus !== null && !$opcacheStatus['enabled']) {
            $overallStatus = self::STATUS_WARN;
        }

        $statusCode = $overallStatus === self::STATUS_FAIL
            ? self::STATUS_SERVICE_UNAVAILABLE
            : self::STATUS_OK;

        // Memory diagnostics (in MB)
        $memoryUsed = round(memory_get_usage(true) / 1024 / 1024, 2);
        $memoryPeak = round(memory_get_peak_usage(true) / 1024 / 1024, 2);

        $response = [
            'status' => $overallStatus,
            'version' => $this->options->version,
            'description' => $this->options->name . ' URL Shortener',
            'links' => [
                'about' => 'https://dhiarr.qzz.io',
                'project' => 'https://github.com/shlinkio/shlink',
            ],
            'checks' => [
                'database' => [
                    'status' => $dbConnected ? self::STATUS_PASS : self::STATUS_FAIL,
                ],
                'memory' => [
                    'used_mb' => $memoryUsed,
                    'peak_mb' => $memoryPeak,
                    'limit' => \ini_get('memory_limit'),
                ],
                'php' => [
                    'version' => \PHP_VERSION,
                ],
            ],
        ];

        // Add OPcache info if available
        if ($opcacheStatus !== null) {
            $response['checks']['opcache'] = $opcacheStatus;
        }

        return new JsonResponse($response, $statusCode, ['Content-type' => self::HEALTH_CONTENT_TYPE]);
    }

    /**
     * @return array{enabled: bool, hit_rate: float, memory_used_mb: float, cached_scripts: int}|null
     */
    private function getOpcacheStatus(): array|null
    {
        if (!function_exists('opcache_get_status')) {
            return null;
        }

        $status = @opcache_get_status(false);
        if ($status === false) {
            return ['enabled' => false];
        }

        return [
            'enabled' => true,
            'hit_rate' => round($status['opcache_statistics']['hits'] / max(1, $status['opcache_statistics']['hits'] + $status['opcache_statistics']['misses']) * 100, 1),
            'memory_used_mb' => round($status['memory_usage']['used_memory'] / 1024 / 1024, 2),
            'cached_scripts' => $status['opcache_statistics']['num_cached_scripts'] ?? 0,
        ];
    }
}
