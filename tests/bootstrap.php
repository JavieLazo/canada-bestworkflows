<?php

declare(strict_types=1);

use Tests\Support\TestDatabaseGuard;

/** Runs before Laravel can resolve a database or invoke destructive test setup. */
require_once __DIR__.'/Support/TestDatabaseGuard.php';

$environment = [];
foreach (['APP_ENV', 'APP_CONFIG_CACHE', 'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'DB_URL', 'DB_SOCKET', 'EMS_MARIADB_TESTS'] as $key) {
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    $environment[$key] = is_string($value) ? $value : '';
}
TestDatabaseGuard::validate($environment, is_file(__DIR__.'/../bootstrap/cache/config.php'));
if ($environment['DB_CONNECTION'] === 'mysql') {
    TestDatabaseGuard::verifyMariaDb($environment);
}

require_once __DIR__.'/../vendor/autoload.php';
