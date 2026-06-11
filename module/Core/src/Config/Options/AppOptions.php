<?php

declare(strict_types=1);

namespace Shlinkio\Shlink\Core\Config\Options;

use Shlinkio\Shlink\Core\Config\EnvVars;

final class AppOptions
{
    public function __construct(public string $name = 'Dhiarlink', public string $version = '4.0.0') {}

    public static function fromEnv(): self
    {
        $version = EnvVars::isDevEnv() ? 'latest' : '%DHIARLINK_VERSION%';
        return new self(version: $version);
    }
}
