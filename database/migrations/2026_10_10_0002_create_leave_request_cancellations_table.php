<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retains one cancellation per approved leave date, preserving the original
 * request and approval. RESTRICT keys retain the request and the deciding
 * account: deleting either would discard evidence for restored leave credits.
 *
 * RETRY-SAFE: create only the columns and primary key together; guard each
 * unique, supporting index and foreign key separately. Indexes precede their
 * keys so MariaDB reuses them. Restating DYNAMIC is idempotent. No request or
 * annual credit is rewritten. Rollback loses all date-cancellation evidence
 * and must therefore only be used before any real cancellations are recorded.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('leave_request_cancellations')) {
            Schema::create('leave_request_cancellations', function (Blueprint $t): void {
                $t->engine = 'InnoDB';
                $t->char('id', 26)->primary();
                $t->char('leave_travel_request_id', 26);
                $t->date('date');
                $t->unsignedBigInteger('cancelled_by');
                $t->dateTime('cancelled_at', 3);
                $t->string('reason', 1000);
                $t->dateTime('created_at', 3);
            });
        }

        if (! Schema::hasIndex('leave_request_cancellations', 'lrc_request_date_unq')) {
            Schema::table('leave_request_cancellations', function (Blueprint $t): void {
                $t->unique(['leave_travel_request_id', 'date'], 'lrc_request_date_unq');
            });
        }
        if (! Schema::hasIndex('leave_request_cancellations', 'lrc_cancelled_by_idx')) {
            Schema::table('leave_request_cancellations', function (Blueprint $t): void {
                $t->index('cancelled_by', 'lrc_cancelled_by_idx');
            });
        }
        if (! Schema::hasForeignKey('leave_request_cancellations', ['leave_travel_request_id'])) {
            Schema::table('leave_request_cancellations', function (Blueprint $t): void {
                $t->foreign('leave_travel_request_id', 'lrc_request_fk')->references('id')->on('leave_travel_requests')->restrictOnDelete();
            });
        }
        if (! Schema::hasForeignKey('leave_request_cancellations', ['cancelled_by'])) {
            Schema::table('leave_request_cancellations', function (Blueprint $t): void {
                $t->foreign('cancelled_by', 'lrc_cancelled_by_fk')->references('id')->on('users')->restrictOnDelete();
            });
        }

        $this->dynamicRowFormat();
    }

    public function down(): void
    {
        if (Schema::hasTable('leave_request_cancellations')) {
            Schema::drop('leave_request_cancellations');
        }
    }

    private function dynamicRowFormat(): void
    {
        if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE `leave_request_cancellations` ROW_FORMAT=DYNAMIC');
        }
    }
};
