<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application;

use Closure;
use Illuminate\Support\Facades\DB;

/** All owner-provided inputs share one read view; nested callers share their enclosing transaction. */
final class PayrollReadTransaction
{
    /** @template T
     * @param  Closure(): T  $read
     * @return T
     */
    public static function run(Closure $read): mixed
    {
        $connection = DB::connection();
        if ($connection->transactionLevel() === 0 && in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }

        return DB::transaction($read);
    }
}
