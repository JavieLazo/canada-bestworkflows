<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Preserve BestWorkflows values before the imported EMS upgrade changes them.
 *
 * This new migration sorts before the imported September 17+ chain, including
 * its code conversion, leave-credit and account-name drops, draft override
 * normalization and SIL removal. No shipped migration is rewritten. The archive
 * is recovery evidence only: old leave balances have no recorded entitlement
 * year, and account names must not overwrite authoritative employee records.
 * No credential, secret or unrelated employee field is copied.
 *
 * Each source field keeps its first captured value, including NULL and exact
 * JSON text. A composite primary key makes concurrent retries converge without
 * replacing earlier evidence. Missing source columns are skipped for already
 * upgraded installations. Creation and row-format DDL are independent, followed
 * by transactional copies; interruption resumes from the existing archive.
 * No foreign keys: deleting an obsolete source must never delete its archive.
 * Rollback refuses to discard any populated archive; recovery requires a backup.
 */
return new class extends Migration
{
    private const TABLE = 'legacy_upgrade_values';

    /** @var array<string, list<string>> */
    private const FIELDS = [
        'users' => ['name'],
        'employee_profiles' => ['employee_code', 'vacation_credits', 'sick_credits'],
        'payroll_runs' => ['code'],
        'leave_travel_requests' => ['code'],
        'holidays' => ['code'],
        'shift_types' => ['code'],
        'payroll_formulas' => ['code'],
        'positions' => ['code'],
        'leave_types' => ['code'],
        'payroll_deductions' => ['code'],
        'document_types' => ['code'],
        'employment_statuses' => ['code'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, static function (Blueprint $table): void {
                $table->engine = 'InnoDB';
                $table->string('source_table', 64);
                $table->string('source_id', 64);
                $table->string('source_field', 64);
                $table->longText('original_value')->nullable();
                $table->dateTime('captured_at', 3);
                $table->primary(['source_table', 'source_id', 'source_field'], 'legacy_upgrade_values_pk');
            });
        }

        if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE `legacy_upgrade_values` ROW_FORMAT=DYNAMIC');
        }

        foreach (self::FIELDS as $table => $fields) {
            $this->preserve($table, $fields);
        }

        $this->preserve('payroll_formulas', ['key', 'name', 'expression', 'params', 'created_at', 'updated_at'],
            static fn (Builder $query): Builder => $query->where('key', 'SIL'));

        if (Schema::hasTable('payroll_runs')) {
            $this->preserve('payroll_run_members', [
                'deduction_sss', 'deduction_philhealth', 'deduction_pagibig', 'deduction_withholding_tax',
            ], static fn (Builder $query): Builder => $query->whereIn('run_id',
                DB::table('payroll_runs')->select('id')->where('status', 'DRAFT')));
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }
        if (DB::table(self::TABLE)->exists()) {
            throw new RuntimeException('Legacy upgrade evidence exists. Restore an approved backup instead of dropping its archive.');
        }
        Schema::drop(self::TABLE);
    }

    /**
     * @param  list<string>  $fields
     * @param  (Closure(Builder): Builder)|null  $scope
     */
    private function preserve(string $table, array $fields, ?Closure $scope = null): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }
        $fields = array_values(array_filter($fields, static fn (string $field): bool => Schema::hasColumn($table, $field)));
        if ($fields === []) {
            return;
        }

        DB::transaction(static function () use ($table, $fields, $scope): void {
            $query = DB::table($table)->select(['id', ...$fields]);
            if ($scope !== null) {
                $query = $scope($query);
            }
            // The source set does not shrink as archive entries land; keyset
            // paging avoids retaining all employee or payroll rows in memory.
            $query->orderBy('id')->chunkById(200, static function ($rows) use ($table, $fields): void {
                $capturedAt = now()->utc()->format('Y-m-d H:i:s.v');
                foreach ($rows as $row) {
                    foreach ($fields as $field) {
                        $key = ['source_table' => $table, 'source_id' => (string) $row->id, 'source_field' => $field];
                        // insertOrIgnore is safe here: every value fits its
                        // explicitly bounded key and the payload is LONGTEXT.
                        DB::table(self::TABLE)->insertOrIgnore($key + [
                            'original_value' => $row->{$field} === null ? null : (string) $row->{$field},
                            'captured_at' => $capturedAt,
                        ]);
                    }
                }
            });
        });
    }
};
