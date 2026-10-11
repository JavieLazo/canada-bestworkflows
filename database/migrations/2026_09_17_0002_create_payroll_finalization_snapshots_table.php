<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Additive and retry-safe: no draft conversion or historical evidence reconstruction.
 * Rollback is allowed only while empty; dropping posted evidence requires a separately approved recovery policy.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payroll_finalization_snapshots')) {
            Schema::create('payroll_finalization_snapshots', function (Blueprint $t): void {
                $t->engine = 'InnoDB';
                $t->char('id', 26)->primary();
                $t->char('run_id', 26);
                $t->unsignedSmallInteger('schema_version');
                $t->string('calculator_version', 80);
                $t->char('input_hash', 64);
                $t->char('payload_hash', 64);
                // Canonical JSON bytes must survive storage unchanged on both MariaDB and MySQL.
                $t->longText('payload');
                $t->unsignedBigInteger('finalized_by');
                $t->dateTime('created_at', 3);
            });
        }
        if (! Schema::hasIndex('payroll_finalization_snapshots', 'pfs_run_unq')) {
            Schema::table('payroll_finalization_snapshots', static fn (Blueprint $t) => $t->unique('run_id', 'pfs_run_unq'));
        }
        if (! Schema::hasForeignKey('payroll_finalization_snapshots', ['run_id'])) {
            Schema::table('payroll_finalization_snapshots', static fn (Blueprint $t) => $t->foreign('run_id', 'pfs_run_fk')->references('id')->on('payroll_runs')->restrictOnDelete());
        }
        // No actor FK: immutable attribution must not be rewritten by account deletion.
        if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE payroll_finalization_snapshots ROW_FORMAT=DYNAMIC');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('payroll_finalization_snapshots')) {
            if (DB::table('payroll_finalization_snapshots')->exists()) {
                throw new RuntimeException('Payroll evidence exists. Restore an approved backup instead of dropping finalization snapshots.');
            }
            Schema::drop('payroll_finalization_snapshots');
        }
    }
};
