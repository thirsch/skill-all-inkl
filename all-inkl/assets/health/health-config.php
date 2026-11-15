<?php
declare(strict_types=1);

// Place at <release>/health-config.php, outside public/. Adapt to actual app needs.
return [
    'php_minor' => '8.5',
    'auth_file' => __DIR__ . '/health-auth.json', // Shared file: username + password_hash.
    'cache_directory' => __DIR__ . '/health-cache', // Shared directory outside public/.
    'cache_ttl_seconds' => 10, // 1 or 10 seconds; failures are cached as well.
    'required_extensions' => ['json'], // This JSON health endpoint itself needs JSON.
    'optional_extensions' => [],
    'extension_alternatives' => [], // e.g. 'images' => ['gd', 'imagick'] if either works.
    'writable_directories' => [], // e.g. 'cache' => __DIR__ . '/var/cache'.
    'database' => null, // Optional closure returning the configured application PDO.
];
