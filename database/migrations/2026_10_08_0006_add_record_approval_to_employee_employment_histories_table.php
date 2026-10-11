<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keeps approved employee-record changes as immutable timeline evidence.
 *
 * RETRY-SAFE: each column, index and foreign key has its own existence guard.
 * The request reference is unique so one approval cannot produce two entries.
 * ROLLBACK LOSES the approval details on history rows, but retains requests
 * and the current employee record. Existing employment snapshots stay intact.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['record_changes', 'request_id', 'requested_by', 'requested_by_name', 'requested_at', 'request_reason', 'review_note'] as $column) {
            if (Schema::hasColumn('employee_employment_histories', $column)) {
                continue;
            }
            Schema::table('employee_employment_histories', function (Blueprint $table) use ($column): void {
                match ($column) {
                    'record_changes' => $table->json($column)->nullable(),
                    'request_id' => $table->char($column, 26)->nullable(),
                    'requested_by' => $table->unsignedBigInteger($column)->nullable(),
                    'requested_by_name' => $table->string($column, 350)->nullable(),
                    'requested_at' => $table->dateTime($column, 3)->nullable(),
                    default => $table->text($column)->nullable(),
                };
            });
        }
        if (Schema::getColumnType('employee_employment_histories', 'recorded_by_name', true) !== 'varchar(350)') {
            Schema::table('employee_employment_histories', fn (Blueprint $table) => $table->string('recorded_by_name', 350)->nullable()->change());
        }
        if (! Schema::hasIndex('employee_employment_histories', 'eehist_request_unq')) {
            Schema::table('employee_employment_histories', fn (Blueprint $table) => $table->unique('request_id', 'eehist_request_unq'));
        }
        if (! Schema::hasForeignKey('employee_employment_histories', ['request_id'])) {
            Schema::table('employee_employment_histories', fn (Blueprint $table) => $table->foreign('request_id')->references('id')->on('employee_record_change_requests')->restrictOnDelete());
        }
    }

    public function down(): void
    {
        if (Schema::hasForeignKey('employee_employment_histories', ['request_id'])) {
            Schema::table('employee_employment_histories', fn (Blueprint $table) => $table->dropForeign(['request_id']));
        }
        if (Schema::hasIndex('employee_employment_histories', 'eehist_request_unq')) {
            Schema::table('employee_employment_histories', fn (Blueprint $table) => $table->dropUnique('eehist_request_unq'));
        }
        foreach (['record_changes', 'request_id', 'requested_by', 'requested_by_name', 'requested_at', 'request_reason', 'review_note'] as $column) {
            if (Schema::hasColumn('employee_employment_histories', $column)) {
                Schema::table('employee_employment_histories', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
};
