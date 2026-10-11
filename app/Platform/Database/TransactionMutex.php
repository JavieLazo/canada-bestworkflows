<?php

declare(strict_types=1);

namespace App\Platform\Database;

use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;

/**
 * Acquires a migration-created database sentinel for the caller's existing
 * transaction. Owners choose their own keys and take this BEFORE entity
 * locks; the platform knows no business tables or allocation rules.
 */
final class TransactionMutex
{
    public static function acquire(string $name): void
    {
        $connection = DB::connection();
        if ($connection->transactionLevel() < 1) {
            throw new LogicException('A transaction mutex requires an active database transaction.');
        }
        if ($connection->table('locks')->where('name', $name)->lockForUpdate()->first() === null) {
            throw new RuntimeException('The database lock is not ready. Ask an administrator to complete the database update.');
        }
    }
}
