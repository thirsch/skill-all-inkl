<?php
declare(strict_types=1);

/** Run project-selected checks. Returned data never contains paths, DSNs or exceptions. */
function allInklHealth(array $config, string $releaseRoot): array
{
    $checks = [];
    $healthy = true;
    $record = static function (string $name, bool $ok, bool $required = true) use (&$checks, &$healthy): void {
        $checks[$name] = $ok ? 'ok' : ($required ? 'failed' : 'warning');
        if ($required && !$ok) {
            $healthy = false;
        }
    };
    $extensionList = static function (mixed $items): array {
        if (!is_array($items) || !array_is_list($items)) {
            throw new InvalidArgumentException('Invalid extension list');
        }
        foreach ($items as $item) {
            if (!is_string($item) || !preg_match('/\A[a-zA-Z][a-zA-Z0-9_ ]*\z/', $item)) {
                throw new InvalidArgumentException('Invalid extension name');
            }
        }
        return $items;
    };
    $label = static function (mixed $value): string {
        if (!is_string($value) || !preg_match('/\A[a-zA-Z][a-zA-Z0-9_-]*\z/', $value)) {
            throw new InvalidArgumentException('Invalid check label');
        }
        return $value;
    };

    $manifest = json_decode(file_get_contents($releaseRoot . '/release.json'), true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($manifest) || ($manifest['format'] ?? null) !== 1 ||
        !is_string($manifest['id'] ?? null) || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,79}\z/', $manifest['id'])) {
        throw new RuntimeException('Invalid release manifest');
    }
    $minor = $config['php_minor'] ?? null;
    if (!is_string($minor) || !preg_match('/\A[0-9]+\.[0-9]+\z/', $minor)) {
        throw new InvalidArgumentException('Invalid PHP target');
    }
    $record('php', PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION === $minor);

    $required = $extensionList($config['required_extensions'] ?? []);
    $optional = $extensionList($config['optional_extensions'] ?? []);
    // Required wins if the same extension accidentally appears in both lists.
    $requiredNames = array_map('strtolower', $required);
    foreach ($required as $extension) {
        $record('extension:' . strtolower($extension), extension_loaded($extension));
    }
    foreach ($optional as $extension) {
        if (!in_array(strtolower($extension), $requiredNames, true)) {
            $record('extension:' . strtolower($extension), extension_loaded($extension), false);
        }
    }
    $alternatives = $config['extension_alternatives'] ?? [];
    if (!is_array($alternatives)) {
        throw new InvalidArgumentException('Invalid extension alternatives');
    }
    foreach ($alternatives as $name => $items) {
        $items = $extensionList($items);
        if ($items === []) {
            throw new InvalidArgumentException('Empty extension alternative');
        }
        $record('extension-group:' . $label($name), count(array_filter($items, 'extension_loaded')) > 0);
    }

    $directories = $config['writable_directories'] ?? [];
    if (!is_array($directories)) {
        throw new InvalidArgumentException('Invalid writable directories');
    }
    foreach ($directories as $name => $directory) {
        $name = $label($name);
        if (!is_string($directory) || !str_starts_with($directory, '/')) {
            throw new InvalidArgumentException('Directory must be absolute');
        }
        $record('directory:' . $name, is_dir($directory) && is_writable($directory));
    }

    $database = $config['database'] ?? null;
    if ($database !== null) {
        if (!is_callable($database)) {
            throw new InvalidArgumentException('Database adapter must be callable or null');
        }
        try {
            // Adapter supplies the existing application connection with a short timeout.
            $pdo = $database();
            if (!$pdo instanceof PDO) {
                throw new RuntimeException('Database adapter did not return PDO');
            }
            $statement = $pdo->query('SELECT 1');
            $ok = $statement !== false && (string)$statement->fetchColumn() === '1';
            if ($statement !== false) {
                $statement->closeCursor();
            }
            $record('database', $ok);
        } catch (Throwable) {
            $record('database', false);
        }
    }

    return [
        'status' => $healthy ? 'ok' : 'failed',
        'release' => $manifest['id'],
        'php' => PHP_VERSION,
        'sapi' => PHP_SAPI,
        'checked_at' => gmdate('c'),
        'checks' => $checks,
    ];
}

function allInklHealthAuthorized(array $config): bool
{
    $auth = json_decode(file_get_contents($config['auth_file']), true, 8, JSON_THROW_ON_ERROR);
    if (!is_string($auth['username'] ?? null) || $auth['username'] === '' ||
        !is_string($auth['password_hash'] ?? null) || password_get_info($auth['password_hash'])['algo'] === null) {
        throw new RuntimeException('Invalid health authentication configuration');
    }
    $user = $_SERVER['PHP_AUTH_USER'] ?? null;
    $password = $_SERVER['PHP_AUTH_PW'] ?? null;
    if (!is_string($user) || !is_string($password)) {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (!preg_match('/\ABasic ([A-Za-z0-9+\/=]+)\z/i', $header, $match)) {
            return false;
        }
        $decoded = base64_decode($match[1], true);
        if (!is_string($decoded) || !str_contains($decoded, ':')) {
            return false;
        }
        [$user, $password] = explode(':', $decoded, 2);
    }
    $passwordMatches = password_verify($password, $auth['password_hash']);
    return hash_equals($auth['username'], $user) && $passwordMatches;
}

function allInklWriteHealthState(string $filename, array $state): void
{
    $temporary = $filename . '.' . bin2hex(random_bytes(8));
    $stream = fopen($temporary, 'xb');
    try {
        chmod($temporary, 0600);
        $json = json_encode($state, JSON_THROW_ON_ERROR);
        if (fwrite($stream, $json) !== strlen($json)) {
            throw new RuntimeException('Health cache write failed');
        }
        fclose($stream);
        $stream = null;
        if (!rename($temporary, $filename)) {
            throw new RuntimeException('Health cache replace failed');
        }
    } finally {
        if (is_resource($stream)) {
            fclose($stream);
        }
        if (file_exists($temporary)) {
            unlink($temporary);
        }
    }
}

/** One shared lock/interval across workers AND releases; never reuse another release's result. */
function allInklCachedHealth(array $config, string $releaseRoot): array
{
    $ttl = $config['cache_ttl_seconds'] ?? 10;
    if (!in_array($ttl, [1, 10], true)) {
        throw new InvalidArgumentException('Health interval must be 1 or 10 seconds');
    }
    $directory = realpath($config['cache_directory']);
    if ($directory === false || !is_dir($directory) || !is_writable($directory) ||
        str_starts_with($directory . '/', $releaseRoot . '/public/')) {
        throw new RuntimeException('Private shared cache directory required');
    }
    $manifest = json_decode(file_get_contents($releaseRoot . '/release.json'), true, 8, JSON_THROW_ON_ERROR);
    $id = $manifest['id'] ?? null;
    if (!is_string($id) || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,79}\z/', $id)) {
        throw new RuntimeException('Invalid release manifest');
    }
    $filename = $directory . '/health-state.json';
    $lockfile = $directory . '/health.lock';
    if (is_link($filename) || is_link($lockfile)) {
        throw new RuntimeException('Invalid health cache files');
    }
    $lock = fopen($lockfile, 'c');
    chmod($lockfile, 0600);
    $locked = flock($lock, LOCK_EX | LOCK_NB);
    try {
        $state = is_file($filename) ? json_decode(file_get_contents($filename), true, 32, JSON_THROW_ON_ERROR) : [];
        $remaining = ($state['not_before'] ?? 0) - microtime(true);
        if ($remaining > 0 && ($state['release'] ?? null) === $id && is_array($state['report'] ?? null)) {
            return [$state['report'], 'HIT', (int)ceil($remaining)];
        }
        if (!$locked || $remaining > 0) {
            return [['status' => 'failed', 'release' => $id, 'checks' => ['health_refresh' => 'pending']],
                'WAIT', max(1, (int)ceil($remaining))];
        }
        // Reserve the interval BEFORE probing, so even a failed worker cannot cause a probe storm.
        allInklWriteHealthState($filename, ['release' => $id, 'not_before' => microtime(true) + $ttl, 'report' => null]);
        try {
            $report = allInklHealth($config, $releaseRoot);
        } catch (Throwable) {
            $report = ['status' => 'failed', 'release' => $id, 'checks' => ['health_configuration' => 'failed']];
        }
        $report['check_id'] = bin2hex(random_bytes(16));
        allInklWriteHealthState($filename, ['release' => $id, 'not_before' => microtime(true) + $ttl, 'report' => $report]);
        return [$report, 'MISS', $ttl];
    } finally {
        if ($locked) {
            flock($lock, LOCK_UN);
        }
        fclose($lock);
    }
}

/** Authenticate before all probes/cache reads; cache only on the server, never in browsers. */
function allInklHealthResponse(callable $loadConfig, string $releaseRoot): void
{
    ini_set('display_errors', '0');
    set_error_handler(static function (int $severity, string $message): never {
        throw new RuntimeException('Healthcheck runtime error');
    });
    $status = 503;
    try {
        $config = $loadConfig();
        if (PHP_SAPI !== 'cli-server' && ($_SERVER['HTTPS'] ?? '') !== 'on' && ($_SERVER['SERVER_PORT'] ?? '') !== '443') {
            $report = ['status' => 'https_required'];
            $status = 403;
        } elseif (!allInklHealthAuthorized($config)) {
            $report = ['status' => 'unauthorized'];
            $status = 401;
            header('WWW-Authenticate: Basic realm="Healthcheck", charset="UTF-8"');
        } else {
            [$report, $cacheStatus, $retryAfter] = allInklCachedHealth($config, $releaseRoot);
            $status = $report['status'] === 'ok' ? 200 : 503;
            header('X-Health-Cache: ' . $cacheStatus);
            if ($status === 503) {
                header('Retry-After: ' . $retryAfter);
            }
        }
    } catch (Throwable) {
        $report = ['status' => 'failed', 'checks' => ['health_configuration' => 'failed']];
    } finally {
        restore_error_handler();
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    echo json_encode($report, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}
