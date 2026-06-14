<?php

/**
 * Dhiarlink — OPcache Preload Script
 *
 * Preloads frequently used PHP files into OPcache at server startup,
 * eliminating first-request compilation overhead. This provides a
 * significant performance boost for bare metal deployments and an
 * additional gain for Docker deployments.
 *
 * Usage (in php.ini):
 *   opcache.preload=/path/to/config/opcache-preload.php
 *   opcache.preload_user=www-data
 *
 * This file is loaded once when the PHP process starts (RoadRunner workers).
 * It uses Composer's optimized autoloader to discover and cache all classes.
 */

declare(strict_types=1);

// Only preload in production
if (getenv('APP_ENV') === 'dev') {
    return;
}

// Find the Composer autoloader (works for both Docker and bare metal)
$autoloadPaths = [
    __DIR__ . '/../vendor/autoload.php',           // bare metal: config/../vendor
    __DIR__ . '/../../vendor/autoload.php',         // alternative path
    '/etc/dhiarlink/vendor/autoload.php',           // Docker: WORKDIR /etc/dhiarlink
];

$autoloader = null;
foreach ($autoloadPaths as $path) {
    if (file_exists($path)) {
        $autoloader = $path;
        break;
    }
}

if ($autoloader === null) {
    // Autoloader not found — skip preloading silently
    return;
}

// Load Composer's class map to discover all classes
$classMapFile = dirname($autoloader) . '/composer/autoload_classmap.php';
if (! file_exists($classMapFile)) {
    // Class map not found (composer dump-autoload -o may not have been run)
    // Fall back to just loading the autoloader
    require $autoloader;
    return;
}

$classMap = require $classMapFile;

// Preload each class file into OPcache
$preloaded = 0;
foreach ($classMap as $class => $file) {
    if (file_exists($file)) {
        opcache_compile_file($file);
        $preloaded++;
    }
}

// Also preload the Composer autoloader files (constants, functions)
$autoloadFiles = dirname($autoloader) . '/composer/autoload_files.php';
if (file_exists($autoloadFiles)) {
    $files = require $autoloadFiles;
    foreach ($files as $file) {
        if (file_exists($file)) {
            opcache_compile_file($file);
            $preloaded++;
        }
    }
}

// Log preload stats to stderr (visible in RoadRunner logs)
file_put_contents(
    'php://stderr',
    sprintf("[opcache-preload] Preloaded %d files into OPcache\n", $preloaded),
);
