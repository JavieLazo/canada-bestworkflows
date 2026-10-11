<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retains proposed employee record edits and independent review evidence.
 * Each DDL step has its own guard so interrupted cron runs resume safely.
 * ROW_FORMAT is inspected before alteration, avoiding a table rebuild on retries.
 * The ordinary nullable unique key permits many resolved requests and one
 * pending request per employee without MariaDB generated-column indexes.
 * RESTRICT preserves evidence when an employee deletion bypasses retention.
 * Actor names are snapshots; account retention protects their attribution.
 * Rollback loses requests and decisions, but never changes employee records.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employee_record_change_requests')) {
            Schema::create('employee_record_change_requests', function (Blueprint $t): void {
                $t->engine = 'InnoDB';
                $t->char('id', 26)->primary();
                $t->char('employee_id', 26);
                $t->char('pending_employee_id', 26)->nullable();
                $t->string('employee_name', 350);
                $t->string('kind', 20);
                $t->string('section', 40);
                $t->string('operation', 20);
                $t->char('row_id', 26)->nullable();
                $t->json('baseline');
                $t->json('proposed');
                $t->json('form');
                $t->json('changes');
                $t->string('reason', 1000);
                $t->string('status', 20)->default('PENDING');
                $t->unsignedBigInteger('requested_by')->nullable();
                $t->string('requested_by_name', 350);
                $t->unsignedBigInteger('reviewed_by')->nullable();
                $t->string('reviewed_by_name', 350)->nullable();
                $t->string('review_note', 1000)->nullable();
                $t->dateTime('reviewed_at', 3)->nullable();
                $t->dateTime('withdrawn_at', 3)->nullable();
                $t->unsignedBigInteger('withdrawn_by')->nullable();
                $t->string('withdrawn_by_name', 350)->nullable();
                $t->dateTime('created_at', 3);
                $t->dateTime('updated_at', 3)->nullable();
            });
        }
        if (! Schema::hasIndex('employee_record_change_requests', 'ercr_pending_unq')) {
            Schema::table('employee_record_change_requests', fn (Blueprint $t) => $t->unique('pending_employee_id', 'ercr_pending_unq'));
        }
        if (! Schema::hasIndex('employee_record_change_requests', 'ercr_employee_idx')) {
            Schema::table('employee_record_change_requests', fn (Blueprint $t) => $t->index(['employee_id', 'created_at'], 'ercr_employee_idx'));
        }
        if (! Schema::hasIndex('employee_record_change_requests', 'ercr_status_idx')) {
            Schema::table('employee_record_change_requests', fn (Blueprint $t) => $t->index(['status', 'created_at'], 'ercr_status_idx'));
        }
        if (! Schema::hasForeignKey('employee_record_change_requests', ['employee_id'])) {
            Schema::table('employee_record_change_requests', fn (Blueprint $t) => $t->foreign('employee_id', 'ercr_employee_fk')->references('id')->on('employee_profiles')->restrictOnDelete());
        }
        if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $format = DB::table('information_schema.TABLES')
                ->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
                ->where('TABLE_NAME', 'employee_record_change_requests')->value('ROW_FORMAT');
            if (strtoupper((string) $format) !== 'DYNAMIC') {
                DB::statement('ALTER TABLE employee_record_change_requests ROW_FORMAT=DYNAMIC');
            }
            if (! $this->hasCheck('ercr_state_chk')) {
                DB::statement("ALTER TABLE employee_record_change_requests ADD CONSTRAINT ercr_state_chk CHECK (status IN ('PENDING','APPROVED','REJECTED','WITHDRAWN') AND kind IN ('SECTION','PDS','PORTRAIT','DOCUMENT') AND operation IN ('CREATE','UPDATE','DELETE') AND ((status = 'PENDING' AND pending_employee_id IS NOT NULL AND pending_employee_id = employee_id) OR (status <> 'PENDING' AND pending_employee_id IS NULL)))");
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_record_change_requests');
    }

    private function hasCheck(string $name): bool
    {
        return DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::connection()->getDatabaseName())
            ->where('TABLE_NAME', 'employee_record_change_requests')
            ->where('CONSTRAINT_TYPE', 'CHECK')->where('CONSTRAINT_NAME', $name)->exists();
    }
};
