<?php

declare(strict_types=1);

use Tests\Support\TestDatabaseGuard;

/** Test isolation is validated before Laravel's database-reset traits can run. */
it('allows only an in-memory sqlite target without database overrides', function () {
    expect(fn () => TestDatabaseGuard::validate(['APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:'], false))->not->toThrow(RuntimeException::class);
});

it('refuses operational or ambiguous test database targets', function (array $patch, bool $cached) {
    $environment = array_replace(['APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:'], $patch);
    expect(fn () => TestDatabaseGuard::validate($environment, $cached))->toThrow(RuntimeException::class);
})->with([
    'cached configuration' => [[], true],
    'alternate configuration cache' => [['APP_CONFIG_CACHE' => '/tmp/operational-config.php'], false],
    'production environment' => [['APP_ENV' => 'production'], false],
    'persistent sqlite' => [['DB_DATABASE' => '/tmp/operational.sqlite'], false],
    'connection URL' => [['DB_URL' => 'mysql://example.test/private'], false],
    'socket' => [['DB_SOCKET' => '/tmp/mysql.sock'], false],
    'ordinary mysql' => [['DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'ems'], false],
]);

it('requires an explicit dedicated MariaDB test identity', function () {
    $environment = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'ems_testing', 'DB_USERNAME' => 'ems_testing', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306', 'EMS_MARIADB_TESTS' => 'disposable'];
    TestDatabaseGuard::validate($environment, false);
    foreach (['DB_DATABASE' => 'ems_rehearsal', 'DB_USERNAME' => 'root', 'DB_HOST' => 'production.example', 'DB_PORT' => '0', 'EMS_MARIADB_TESTS' => ''] as $key => $value) {
        expect(fn () => TestDatabaseGuard::validate(array_replace($environment, [$key => $value]), false))->toThrow(RuntimeException::class);
    }
});
