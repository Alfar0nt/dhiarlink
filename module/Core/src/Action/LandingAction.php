<?php

declare(strict_types=1);

namespace Shlinkio\Shlink\Core\Action;

use Fig\Http\Message\StatusCodeInterface;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Shlinkio\Shlink\Core\Config\Options\AppOptions;

use function file_get_contents;
use function str_replace;

use const PHP_EOL;

/**
 * Serves the Dhiarlink landing page at the base URL (/).
 *
 * Reads the static landing.html template, injects runtime values
 * (app version), and returns it as an HTML response.
 */
readonly class LandingAction implements RequestHandlerInterface, StatusCodeInterface
{
    private const string TEMPLATE_PATH = __DIR__ . '/../../templates/landing.html';

    public function __construct(private AppOptions $options) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $html = file_get_contents(self::TEMPLATE_PATH);
        if ($html === false) {
            return new Response(
                self::STATUS_INTERNAL_SERVER_ERROR,
                ['Content-type' => 'text/plain'],
                'Landing page template not found.' . PHP_EOL,
            );
        }

        // Inject runtime values into the template
        $html = str_replace('{{version}}', $this->options->version, $html);

        return new Response(
            self::STATUS_OK,
            ['Content-type' => 'text/html; charset=utf-8'],
            $html,
        );
    }
}
