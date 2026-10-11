<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * NEW TABLE — it has no counterpart in prisma/schema.prisma.
 *
 * Pay Basis & Rates: how a day, an overtime hour and a night hour are
 * priced, kept as dated VERSIONS. One row is one version for one `scope` —
 * `COMPANY`, or the id of an Employment list entry whose employees it
 * overrides — from `effective_from`, with the figures `params` the pure
 * `App\Domain\Payroll\PayRules` reads (basis, daily-rate method, rates and
 * caps, the night window, the rates-by-day-type matrix), a `reason` the
 * officer typed, and who added it (`created_by`, SET NULL on account
 * deletion, with `created_by_name` kept like `audit_logs.actor_name`). A
 * payroll period is priced by the version in force on its first day, the
 * status override before the company rule, and refused when a version
 * changes inside it; a finalized payroll keeps the figures in its evidence.
 *
 * DATA: one COMPANY version is INSERTED, effective 2025-01-01, carrying the
 * Formulas rows' values as they stand on this installation (OT, NSD and
 * Rest Day percentages and caps; the seeded defaults when a row is absent)
 * and the engine's former behaviour for everything else — days-worked basis,
 * the month's working days, the 22:00–06:00 night window, no night pay on
 * overtime, every matrix cell "as derived" — so every payroll keeps
 * computing exactly what it computed before. A migration must never change
 * payroll money on its own. Inserted only while the company has no version.
 *
 * RETRY-SAFE, STEP BY STEP: the create, the unique index, the foreign key,
 * the row format and the seeded row each stand behind their own guard. On
 * SQLite (tests) the foreign key is a table rebuild.
 *
 * ROLLBACK LOSES every version, including the seeded one: payroll then
 * prices from the Formulas rows and the former behaviour, which is what the
 * seeded version holds.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payroll_pay_rules')) {
            Schema::create('payroll_pay_rules', function (Blueprint $t): void {
                $t->engine = 'InnoDB';

                $t->char('id', 26)->primary();

                // COMPANY, or an employment_statuses id (no foreign key: another owner's table).
                $t->string('scope', 26);

                $t->date('effective_from');
                $t->string('reason', 160)->nullable();
                $t->json('params');

                $t->unsignedBigInteger('created_by')->nullable();
                $t->string('created_by_name', 200)->nullable();

                $t->dateTime('created_at', 3);
                $t->dateTime('updated_at', 3)->nullable();
            });
        }

        if (! Schema::hasIndex('payroll_pay_rules', 'ppr_scope_from_unq')) {
            Schema::table('payroll_pay_rules', function (Blueprint $t): void {
                $t->unique(['scope', 'effective_from'], 'ppr_scope_from_unq');
            });
        }

        if (! Schema::hasForeignKey('payroll_pay_rules', ['created_by'])) {
            Schema::table('payroll_pay_rules', function (Blueprint $t): void {
                $t->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            });
        }

        $this->dynamicRowFormat('payroll_pay_rules');

        $this->seedCompanyVersion();
    }

    public function down(): void
    {
        if (Schema::hasTable('payroll_pay_rules')) {
            Schema::drop('payroll_pay_rules');
        }
    }

    /** The company's first version: the Formulas rows' values and the engine's former behaviour. */
    private function seedCompanyVersion(): void
    {
        if (DB::table('payroll_pay_rules')->where('scope', 'COMPANY')->exists()) {
            return;
        }

        $formula = function (string $key, string $rateKey, string $capKey, float $rate, float $cap): array {
            if (! Schema::hasTable('payroll_formulas')) {
                return [$rate, $cap];
            }
            $row = DB::table('payroll_formulas')->where('key', $key)->first(['params']);
            $params = $row === null ? null : json_decode((string) $row->params, true);
            if (! is_array($params)) {
                return [$rate, $cap];
            }
            foreach ($params as $param) {
                if (! is_array($param)) {
                    continue;
                }
                $value = $param['value'] ?? null;
                if (($param['key'] ?? null) === $rateKey && is_numeric($value) && is_finite((float) $value) && (float) $value >= 0) {
                    $rate = (float) $value;
                }
                if (($param['key'] ?? null) === $capKey && is_numeric($value) && is_finite((float) $value) && (float) $value >= 0) {
                    $cap = (float) $value;
                }
            }

            return [$rate, $cap];
        };
        [$otRate, $otCap] = $formula('OT', 'percentage', 'max_ot', 1.25, 8.0);
        [$nsdRate, $nsdCap] = $formula('NSD', 'percentage', 'max_nsd', 0.1, 8.0);
        [$restRate, $restCap] = $formula('REST_DAY', 'percentage', 'max_hours', 1.3, 8.0);

        $matrix = [];
        foreach (['ORDINARY', 'REST', 'SPECIAL', 'SPECIAL_REST', 'REGULAR', 'REGULAR_REST', 'DOUBLE', 'DOUBLE_REST'] as $type) {
            $matrix[$type] = ['worked' => null, 'overtime' => null, 'night' => null, 'unworked' => 0.0];
        }

        DB::table('payroll_pay_rules')->insert([
            'id' => (string) Str::ulid(),
            'scope' => 'COMPANY',
            'effective_from' => '2025-01-01',
            'reason' => null,
            'params' => json_encode([
                'basis' => 'DAILY',
                'daily_rate' => ['method' => 'MONTH_WORKING_DAYS', 'value' => 0.0],
                'holiday_needs_attendance' => false,
                'overtime' => ['rate' => $otRate, 'max_hours' => $otCap],
                'night' => ['rate' => $nsdRate, 'max_hours' => $nsdCap, 'start' => 1320, 'end' => 1800, 'on_overtime' => false],
                'rest_day' => ['rate' => $restRate, 'max_hours' => $restCap],
                'holiday_overtime_factor' => 1.3,
                'matrix' => $matrix,
            ], JSON_THROW_ON_ERROR),
            'created_by' => null,
            'created_by_name' => null,
            'created_at' => now('UTC')->format('Y-m-d H:i:s.v'),
            'updated_at' => null,
        ]);
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
