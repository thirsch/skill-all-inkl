<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
try {
    if ($argc !== 3 || !preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', $argv[2])) {
        throw new RuntimeException('Usage: php85 init-health-auth.php SHARED_DIRECTORY USERNAME');
    }
    umask(0077);
    $directory = realpath($argv[1]);
    if ($directory === false || !is_dir($directory)) {
        throw new RuntimeException('Existing private shared directory required');
    }
    $authFile = $directory . '/health-auth.json';
    $clientFile = $directory . '/health-client.json';
    $lockFile = $directory . '/.health-init.lock';
    $cacheDirectory = $directory . '/health-cache';
    foreach ([$authFile, $clientFile, $lockFile, $cacheDirectory] as $target) {
        if (is_link($target)) {
            throw new RuntimeException('Unexpected symlink in health credential setup');
        }
    }
    $lock = fopen($lockFile, 'c');
    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Another credential setup is running');
    }
    if (file_exists($authFile) || file_exists($clientFile)) {
        if (!is_file($authFile) || !is_file($clientFile)) {
            throw new RuntimeException('Incomplete existing credential pair; no changes made');
        }
        $auth = json_decode(file_get_contents($authFile), true, 8, JSON_THROW_ON_ERROR);
        $client = json_decode(file_get_contents($clientFile), true, 8, JSON_THROW_ON_ERROR);
        if (($auth['username'] ?? null) !== $argv[2] || ($client['username'] ?? null) !== $argv[2] ||
            !password_verify($client['password'], $auth['password_hash'])) {
            throw new RuntimeException('Existing credential pair differs; no changes made');
        }
    } else {
        $password = bin2hex(random_bytes(32));
        $auth = ['username' => $argv[2], 'password_hash' => password_hash($password, PASSWORD_DEFAULT)];
        $client = ['username' => $argv[2], 'password' => $password];
        foreach ([$authFile => $auth, $clientFile => $client] as $filename => $data) {
            $stream = fopen($filename, 'xb');
            $json = json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
            if (fwrite($stream, $json) !== strlen($json)) {
                throw new RuntimeException('Credential write failed');
            }
            fclose($stream);
        }
    }
    if (!chmod($authFile, 0600) || !chmod($clientFile, 0600)) {
        throw new RuntimeException('Cannot secure health credential files');
    }
    if (!is_dir($cacheDirectory) && !mkdir($cacheDirectory, 0700)) {
        throw new RuntimeException('Cannot create health cache directory');
    }
    if (!chmod($cacheDirectory, 0700)) {
        throw new RuntimeException('Cannot secure health cache directory');
    }
    echo "Health authentication and shared cache ready; credentials not printed.\n";
} catch (Throwable) {
    fwrite(STDERR, "Health credential setup failed; no secret details emitted.\n");
    exit(1);
}
