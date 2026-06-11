<?php

declare(strict_types=1);

namespace Shlinkio\Shlink\Core\Util;

use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Shlinkio\Shlink\Core\Config\Options\RedirectOptions;

use function parse_url;
use function sprintf;

readonly class RedirectResponseHelper implements RedirectResponseHelperInterface
{
    public function __construct(private RedirectOptions $options) {}

    public function buildRedirectResponse(string $location): ResponseInterface
    {
        $statusCode = $this->options->redirectStatusCode;
        $headers = !$statusCode->allowsCache()
            ? []
            : [
                'Cache-Control' => sprintf(
                    '%s,max-age=%s',
                    $this->options->redirectCacheVisibility,
                    $this->options->redirectCacheLifetime,
                ),
            ];

        // Add DNS prefetch hint for the redirect target domain.
        // This tells the browser to start resolving the target domain's DNS
        // while still processing the redirect response, reducing the
        // overall redirect latency for end users.
        $targetHost = parse_url($location, PHP_URL_HOST);
        if ($targetHost !== null && $targetHost !== false && $targetHost !== '') {
            $scheme = parse_url($location, PHP_URL_SCHEME) ?: 'https';
            $headers['Link'] = sprintf('<%s://%s>; rel=dns-prefetch', $scheme, $targetHost);
        }

        return new RedirectResponse($location, $statusCode->value, $headers);
    }
}
