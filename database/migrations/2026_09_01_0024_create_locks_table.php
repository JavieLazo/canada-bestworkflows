<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * NEW TABLE — it has no counterpart in prisma/schema.prisma.
 *
 * Replaces the six `pg_advisory_xact_lock` call sites. A row here, taken with
 * lockForUpdate() inside a transaction, is an InnoDB row lock: genuinely
 * transaction-scoped, released on COMMIT *and* on ROLLBACK, which is exactly
 * what the advisory lock did.
 *
 * NOT MySQL's GET_LOCK(): that is SESSION-scoped and is released by neither
 * COMMIT nor ROLLBACK, so under a persistent connection a leaked lock outlives
 * the request and deadlocks every subsequent write behind it. Cache::lock() is
 * not a substitute either without Redis.
 *
 * The sentinel rows are inserted here rather than seeded because App\Support\
 * NamedLock SELECTs them: a missing row is a silently absent mutex, not an
 * error.
 *
 * Note this table is unrelated to Laravel's `cache_locks`, which the starter
 * kit already created for the cache driver.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locks', function (Blueprint $t): void {
            $t->engine = 'InnoDB';

            $t->string('name', 120)->primary();
            $t->dateTime('created_at', 3);
        });

        $this->dynamicRowFormat('locks');

        // Explicit millisecond format: these columns are DATETIME(3) and the
        // rest of the system writes 'Y-m-d H:i:s.v' through BaseModel.
        $now = now()->format('Y-m-d H:i:s.v');

        DB::table('locks')->insert([
            ['name' => 'copems:payroll-membership', 'created_at' => $now],
            ['name' => 'copems:employee-code', 'created_at' => $now],
            ['name' => 'copems:project-code', 'created_at' => $now],
            ['name' => 'copems:task-code', 'created_at' => $now],
            ['name' => 'copems:leave-code', 'created_at' => $now],
            ['name' => 'copems:setup', 'created_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('locks');
    }

    private function dynamicRowFormat(string ...$tables): void
    {
        if (! in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        foreach ($tables as $table) {
            DB::statement("ALTER TABLE `{$table}` ROW_FORMAT=DYNAMIC");
        }
    }
};
