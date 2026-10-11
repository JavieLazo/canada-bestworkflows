<?php

declare(strict_types=1);

use App\Models\PayrollIncentiveType;
use Database\Seeders\IncentiveTypeSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retried MariaDB DDL commits its transaction. DatabaseMigrations isolates
 * these fixtures; RefreshDatabase's transaction cannot roll them back.
 */
it('can retry creation of the technical registry without touching business keys', function () {
    $migration = require database_path('migrations/2026_10_12_0005_create_seed_identities_table.php');
    $migration->up();
    $migration->up();
    expect(Schema::hasTable('seed_identities'))->toBeTrue();

    $this->seed(IncentiveTypeSeeder::class);
    $keys = PayrollIncentiveType::query()->orderBy('id')->pluck('id')->all();
    $mapping = DB::table('seed_identities')->orderBy('table_name')->orderBy('seed_key')->get()->toArray();
    $migration->up();
    $migration->up();
    expect(PayrollIncentiveType::query()->orderBy('id')->pluck('id')->all())->toBe($keys)
        ->and(DB::table('seed_identities')->orderBy('table_name')->orderBy('seed_key')->get()->toArray())->toEqual($mapping);
});
