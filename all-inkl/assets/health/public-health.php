<?php
declare(strict_types=1);

// Copy to <release>/public/health.php; library and config stay outside public/.
ini_set('display_errors', '0');
try {
    require dirname(__DIR__) . '/healthcheck.php';
    allInklHealthResponse(
        static fn(): array => require dirname(__DIR__) . '/health-config.php',
        dirname(__DIR__)
    );
} catch (Throwable) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    echo '{"status":"failed","checks":{"health_bootstrap":"failed"}}';
}
