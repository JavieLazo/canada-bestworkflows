<?php

declare(strict_types=1);

namespace Tests\Support;

use PDO;
use RuntimeException;

/** Refuses operational database targets before PHPUnit can boot RefreshDatabase. */
final class TestDatabaseGuard
{
    /** @param array<string, string> $environment */
    public static function validate(array $environment, bool $cachedConfiguration): void
    {
        if ($cachedConfiguration || ($environment['APP_CONFIG_CACHE'] ?? '') !== '' || ($environment['APP_ENV'] ?? '') !== 'testing') {
            throw new RuntimeException('Tests require APP_ENV=testing and no cached application configuration.');
        }
        if (($environment['DB_URL'] ?? '') !== '' || ($environment['DB_SOCKET'] ?? '') !== '') {
            throw new RuntimeException('Tests cannot use a database URL or socket override.');
        }
        if (($environment['DB_CONNECTION'] ?? '') === 'sqlite' && ($environment['DB_DATABASE'] ?? '') === ':memory:') {
            return;
        }
        if (($environment['DB_CONNECTION'] ?? '') !== 'mysql'
            || ($environment['DB_HOST'] ?? '') !== '127.0.0.1'
            || ($environment['DB_DATABASE'] ?? '') !== 'ems_testing'
            || ($environment['DB_USERNAME'] ?? '') !== 'ems_testing'
            || ($environment['EMS_MARIADB_TESTS'] ?? '') !== 'disposable') {
            throw new RuntimeException('MariaDB tests require the dedicated loopback ems_testing database and account.');
        }
        if (! ctype_digit($environment['DB_PORT'] ?? '') || (int) $environment['DB_PORT'] < 1 || (int) $environment['DB_PORT'] > 65535) {
            throw new RuntimeException('Tests require an explicit valid database port.');
        }
    }

    /** @param array<string, string> $environment */
    public static function verifyMariaDb(array $environment): void
    {
        $pdo = new PDO('mysql:host=127.0.0.1;port='.$environment['DB_PORT'].';dbname=ems_testing', 'ems_testing', $environment['DB_PASSWORD'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $statement = $pdo->query('SELECT VERSION()');
        $version = $statement === false ? false : $statement->fetchColumn();
        if (! is_string($version) || ! str_starts_with($version, '11.4.') || ! str_contains($version, 'MariaDB')) {
            throw new RuntimeException('The MariaDB test suite requires MariaDB 11.4.');
        }
        // A test account with global or other-schema privileges is not isolated.
        $statement = $pdo->query('SHOW GRANTS');
        if ($statement === false) {
            throw new RuntimeException('Cannot verify test account privileges.');
        }
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $grant) {
            if (! is_string($grant) || (! str_starts_with($grant, 'GRANT USAGE ON *.* TO ')
                && ! str_starts_with($grant, 'GRANT ALL PRIVILEGES ON `ems_testing`.* TO '))) {
                throw new RuntimeException('The test account must have privileges only on ems_testing.');
            }
        }
    }
}
