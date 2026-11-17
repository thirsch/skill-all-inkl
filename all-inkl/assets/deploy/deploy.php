<?php
declare(strict_types=1);

// Keep this CLI-only tool and its configuration OUTSIDE every document root.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
set_error_handler(static function (int $level, string $message): bool {
    throw new RuntimeException($message);
});

function need(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function relativePath(string $path): string {
    need($path !== '' && !str_contains($path, '\\') && !preg_match('/[\x00-\x1f:]/', $path), 'Invalid relative path');
    foreach (explode('/', $path) as $part) {
        need($part !== '' && $part !== '.' && $part !== '..', 'Unsafe relative path: ' . $path);
    }
    return $path;
}
function releaseId(string $id): string {
    need((bool)preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,79}\z/', $id), 'Invalid release ID');
    return $id;
}
function directory(string $path): void {
    need(!is_link($path), 'Directory must not be a symlink: ' . $path);
    if (!is_dir($path)) {
        need(mkdir($path, 0755, true), 'Cannot create directory');
    }
}
function switchLink(string $root, string $name, string $target): void {
    $path = "$root/$name";
    need(!file_exists($path) || is_link($path), "$name must be absent or a symlink");
    $temporary = "$root/.switch-" . bin2hex(random_bytes(8));
    need(symlink($target, $temporary), 'Cannot create switch symlink');
    try {
        need(rename($temporary, $path), 'Cannot switch symlink');
    } finally {
        if (is_link($temporary)) {
            unlink($temporary);
        }
    }
}
function validatePointers(string $root): ?string {
    foreach (['current', 'previous'] as $name) {
        need(!file_exists("$root/$name") || is_link("$root/$name"), "$name is not a symlink");
        if (is_link("$root/$name")) {
            $target = readlink("$root/$name");
            need((bool)preg_match('~\Areleases/[A-Za-z0-9][A-Za-z0-9_-]{0,79}\z~', $target), "Unexpected $name target");
            need(!is_link("$root/$target") && is_file("$root/$target/.deploy-ready.json"), "$name release is not managed by this tool");
        }
    }
    return is_link("$root/current") ? readlink("$root/current") : null;
}
function checkHealth(array $config, string $releaseId): void {
    $consecutive = 0;
    $lastError = null;
    $lastCheckId = null;
    $requestHeaders = "Cache-Control: no-cache\r\n";
    if (($config['health_basic_auth_file'] ?? '') !== '') {
        $auth = json_decode(file_get_contents($config['health_basic_auth_file']), true, 8, JSON_THROW_ON_ERROR);
        need(is_string($auth['username'] ?? null) && $auth['username'] !== '' && !str_contains($auth['username'], ':') &&
            is_string($auth['password'] ?? null) && $auth['password'] !== '', 'Invalid health client credentials');
        $requestHeaders .= 'Authorization: Basic ' . base64_encode($auth['username'] . ':' . $auth['password']) . "\r\n";
    }
    for ($attempt = 1; $attempt <= $config['health_attempts']; $attempt++) {
        try {
            $context = stream_context_create(['http' => [
                'timeout' => 10, 'ignore_errors' => true, 'follow_location' => 0,
                'header' => $requestHeaders,
            ]]);
            $body = file_get_contents($config['health_url'], false, $context, 0, 1048576);
            $headers = http_get_last_response_headers();
            need(is_string($body) && (bool)preg_match('/^HTTP\/\S+ 2\d\d\b/', $headers[0] ?? ''), 'HTTP health check failed');
            $contains = $config['health_contains'] ?? '';
            need($contains === '' || str_contains($body, $contains), 'Health response does not contain expected text');
            if ($config['health_release_field'] !== '' || $config['health_check_id_field'] !== '') {
                $json = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
                need(is_array($json), 'Health response must be a JSON object');
            }
            if ($config['health_release_field'] !== '') {
                need(is_array($json) && ($json[$config['health_release_field']] ?? null) === $releaseId,
                    'Health response is not from expected release ' . $releaseId);
            }
            if ($config['health_check_id_field'] !== '') {
                $checkId = $json[$config['health_check_id_field']] ?? null;
                need(is_string($checkId) && $checkId !== '', 'Health check ID is missing');
                if ($checkId === $lastCheckId) {
                    // A cached reply is not another independent successful probe.
                    if ($attempt < $config['health_attempts']) {
                        sleep($config['health_interval_seconds']);
                    }
                    continue;
                }
                $lastCheckId = $checkId;
            }
            if (++$consecutive >= $config['health_successes']) {
                return;
            }
        } catch (Throwable $error) {
            $consecutive = 0;
            $lastCheckId = null;
            $lastError = $error;
        }
        if ($attempt < $config['health_attempts']) {
            echo "Waiting for stable health of $releaseId ($attempt/{$config['health_attempts']})\n";
            sleep($config['health_interval_seconds']);
        }
    }
    throw new RuntimeException('Health did not stabilize' . ($lastError ? ': ' . $lastError->getMessage() : ''));
}
function installRelease(array $config, string $zipPath, string $digest): string {
    need(extension_loaded('zip'), 'PHP ZipArchive extension is required');
    need((bool)preg_match('/\A[a-fA-F0-9]{64}\z/', $digest), 'Expected SHA-256 is required');
    need(is_file($zipPath) && hash_equals(strtolower($digest), hash_file('sha256', $zipPath)), 'ZIP checksum mismatch');
    $zip = new ZipArchive();
    need($zip->open($zipPath, ZipArchive::RDONLY | ZipArchive::CHECKCONS) === true, 'Cannot open ZIP');
    try {
        need($zip->numFiles > 0 && $zip->numFiles <= $config['max_files'], 'ZIP entry limit exceeded');
        $seen = [];
        $entries = [];
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            need(is_array($stat), 'Invalid ZIP entry');
            $raw = $stat['name'];
            $isDir = str_ends_with($raw, '/');
            $name = relativePath($isDir ? substr($raw, 0, -1) : $raw);
            need(!isset($seen[$name]), 'Duplicate ZIP path');
            $seen[$name] = true;
            foreach (explode('/', $name) as $part) {
                need(!in_array($part, ['.git', '.svn', '.ssh', 'node_modules'], true) &&
                    (!str_starts_with($part, '.env') || $part === '.env.example'), 'Forbidden ZIP content: ' . $name);
            }
            need(!in_array(explode('/', $name)[0], ['.deploy-ready.json', 'deployment.json'], true), 'Reserved ZIP path');
            need($zip->getExternalAttributesIndex($i, $system, $attributes), 'Cannot inspect ZIP attributes');
            $mode = ($attributes >> 16) & 0170000;
            need(in_array($mode, [0, 0100000, 0040000], true), 'ZIP symlinks and special files are forbidden');
            need($mode !== 0040000 || $isDir, 'Directory type mismatch');
            need(($stat['encryption_method'] ?? 0) === 0, 'Encrypted ZIP is unsupported');
            $total += $stat['size'];
            need($total <= $config['max_unpacked_bytes'], 'Unpacked size limit exceeded');
            $entries[] = [$i, $name, $isDir, $stat['size'], ($attributes >> 16) & 0111];
        }
        $manifestStat = $zip->statName('release.json');
        need(is_array($manifestStat) && $manifestStat['size'] <= 4096, 'Missing or oversized release.json');
        $manifest = json_decode($zip->getFromName('release.json'), true, 8, JSON_THROW_ON_ERROR);
        need(($manifest['format'] ?? null) === 1, 'Unsupported release format');
        $version = $manifest['version'] ?? null;
        need($version === null || (is_string($version) &&
            preg_match('/\A[A-Za-z0-9][A-Za-z0-9._+-]{0,127}\z/', $version)), 'Invalid application version');
        $id = releaseId($manifest['id']);
        $release = $config['root'] . '/releases/' . $id;
        need(!file_exists($release) && !is_link($release), 'Release already exists; use activate or a new ID');
        directory($release);
        // Extract individually only after every name and type has been checked.
        // Failed installs remain for diagnosis and are never marked ready.
        foreach ($entries as [$index, $name, $isDir, $size, $executable]) {
            $target = "$release/$name";
            if ($isDir) {
                directory($target);
                continue;
            }
            directory(dirname($target));
            $in = $zip->getStreamIndex($index);
            need(is_resource($in), 'Cannot read ZIP member');
            $out = fopen($target, 'xb');
            try {
                $copied = stream_copy_to_stream($in, $out, $size + 1);
                need($copied === $size, 'ZIP member size mismatch');
            } finally {
                fclose($in);
                fclose($out);
            }
            chmod($target, $executable ? 0755 : 0644);
        }
        need(is_file($release . '/' . $config['entrypoint']), 'Public entrypoint is missing');
        foreach ($config['shared'] as $destination => $source) {
            $sourcePath = $config['root'] . '/shared/' . relativePath($source);
            $realSource = realpath($sourcePath);
            need($realSource !== false && str_starts_with($realSource, $config['root'] . '/shared/'), 'Shared source missing or outside shared/');
            need(!in_array(explode('/', $destination)[0], ['release.json', '.deploy-ready.json', 'deployment.json'], true),
                'Release metadata must not be shared');
            $target = "$release/" . relativePath($destination);
            need(!file_exists($target) && !is_link($target), 'Shared destination is present in ZIP');
            // Forbid nested mappings that could traverse a previously attached symlink.
            $parent = dirname($target);
            while ($parent !== $release) {
                need(!is_link($parent), 'Nested shared targets are unsupported');
                $parent = dirname($parent);
            }
            directory(dirname($target));
            need(symlink($realSource, $target), 'Cannot attach shared path');
        }
        foreach ($config['prepare'] as $command) {
            need(is_array($command) && array_is_list($command) && count($command) > 0, 'prepare commands must be argv arrays');
            $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, $release);
            need(is_resource($process) && proc_close($process) === 0, 'Prepare command failed; release is inactive');
        }
        file_put_contents("$release/.deploy-ready.json", json_encode(['id' => $id, 'sha256' => strtolower($digest)], JSON_THROW_ON_ERROR));
        echo "Installed: $id\n";
        return $id;
    } finally {
        $zip->close();
    }
}
/** Atomic replacement outside public/; metadata never comes from a release ZIP. */
function writeDeploymentMetadata(string $path, string $json): void {
    $temporary = $path . '.' . bin2hex(random_bytes(8));
    $stream = fopen($temporary, 'xb');
    try {
        need(fwrite($stream, $json) === strlen($json), 'Cannot write deployment metadata');
        fclose($stream);
        $stream = null;
        need(chmod($temporary, 0644), 'Cannot set deployment metadata permissions');
        need(rename($temporary, $path), 'Cannot replace deployment metadata');
    } finally {
        if (is_resource($stream)) {
            fclose($stream);
        }
        if (file_exists($temporary)) {
            unlink($temporary);
        }
    }
}
function activate(array $config, string $id): void {
    $id = releaseId($id);
    $root = $config['root'];
    $release = "$root/releases/$id";
    need(!is_link($release) && is_file("$release/.deploy-ready.json"), 'Release is not ready');
    need(is_file($release . '/' . $config['entrypoint']), 'Public entrypoint is missing');
    $old = validatePointers($root);
    if ($old !== null && $old !== "releases/$id") {
        switchLink($root, 'previous', $old);
    }
    $metadataPath = "$release/deployment.json";
    need(!is_link($metadataPath) && (!file_exists($metadataPath) || is_file($metadataPath)),
        'Deployment metadata must be a regular file');
    $previousMetadata = is_file($metadataPath) ? file_get_contents($metadataPath) : null;
    // Stamp at activation, never at build/install/check time. Even same-release activations
    // receive a unique identity, so health caches cannot reuse the previous activation.
    $metadata = json_encode([
        'release' => $id,
        'deployed_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z'),
        'activation_id' => bin2hex(random_bytes(16)),
    ], JSON_THROW_ON_ERROR);
    writeDeploymentMetadata($metadataPath, $metadata);
    try {
        switchLink($root, 'current', "releases/$id");
    } catch (Throwable $error) {
        if ($previousMetadata === null) {
            unlink($metadataPath);
        } else {
            writeDeploymentMetadata($metadataPath, $previousMetadata);
        }
        throw $error;
    }
    echo "Activated: $id; previous: " . ($old ?? 'none') . "\n";
    // Never pretend a DB migration can be undone by changing a symlink.
    try {
        checkHealth($config, $id);
    } catch (Throwable $error) {
        throw new RuntimeException('Health check failed AFTER activation; current remains ' . $id .
            '. Inspect and use explicit code rollback only if schema-compatible. ' . $error->getMessage());
    }
    echo "Health check passed\n";
}

try {
    $action = $argv[1] ?? '';
    need(in_array($action, ['install', 'deploy', 'activate', 'rollback'], true),
        'Usage: php deploy.php install|deploy CONFIG ZIP SHA256; activate|rollback CONFIG RELEASE_ID');
    need(count($argv) === (in_array($action, ['install', 'deploy'], true) ? 5 : 4), 'Wrong number of arguments');
    $config = json_decode(file_get_contents($argv[2]), true, 32, JSON_THROW_ON_ERROR);
    need(($config['php_minor'] ?? '') === PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION, 'CLI PHP minor version differs from config');
    $root = realpath($config['root']);
    need($root !== false && $root !== '/' && is_dir($root), 'Existing project root required');
    $config['root'] = $root;
    // Backward-compatible single check for old configs; examples use release-aware checks.
    $config += ['health_release_field' => '', 'health_check_id_field' => '', 'health_basic_auth_file' => '', 'health_attempts' => 1,
        'health_successes' => 1, 'health_interval_seconds' => 0];
    need(is_string($config['health_release_field']), 'health_release_field must be a string');
    need(is_string($config['health_check_id_field']) && is_string($config['health_basic_auth_file']), 'Invalid health client settings');
    need(is_int($config['health_attempts']) && $config['health_attempts'] >= 1 && $config['health_attempts'] <= 60 &&
        is_int($config['health_successes']) && $config['health_successes'] >= 1 && $config['health_successes'] <= $config['health_attempts'] &&
        is_int($config['health_interval_seconds']) && $config['health_interval_seconds'] >= 0 && $config['health_interval_seconds'] <= 10,
        'Invalid bounded health check settings');
    relativePath($config['entrypoint']);
    need(str_starts_with($config['entrypoint'], 'public/'), 'Entrypoint must be inside public/');
    need(is_array($config['shared']) && is_array($config['prepare']), 'shared and prepare must be configured');
    need(is_int($config['max_files']) && $config['max_files'] > 0 &&
        is_int($config['max_unpacked_bytes']) && $config['max_unpacked_bytes'] > 0, 'Positive archive limits required');
    $health = parse_url($config['health_url']);
    need(is_array($health) && !isset($health['user']) && !isset($health['pass']) &&
        (($health['scheme'] ?? '') === 'https' || (($health['scheme'] ?? '') === 'http' &&
        in_array($health['host'] ?? '', ['127.0.0.1', 'localhost'], true))), 'HTTPS health URL required (HTTP allowed only on localhost)');
    directory("$root/releases");
    directory("$root/shared");
    need(!is_link("$root/.deploy.lock"), 'Lock must not be a symlink');
    $lock = fopen("$root/.deploy.lock", 'c');
    need(flock($lock, LOCK_EX | LOCK_NB), 'Another deployment is running');
    // Reject an incompatible existing layout before any preparation/migration hook.
    validatePointers($root);
    if ($action === 'install' || $action === 'deploy') {
        $id = installRelease($config, $argv[3], $argv[4]);
        if ($action === 'deploy') {
            activate($config, $id);
        }
    } else {
        if ($action === 'rollback') {
            echo "Code rollback only; database remains unchanged.\n";
        }
        activate($config, $argv[3]);
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Deployment failed: ' . $error->getMessage() . "\n");
    exit(1);
}
