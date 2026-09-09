<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Port of `model PayrollFormula` (prisma/schema.prisma:741-752).
 *
 * A payroll formula and the numbers it is computed from. The SHAPE of each
 * calculation lives in code, keyed by `key`; everything adjustable is `params`,
 * a [{ key, label, value }] array. Payroll must never evaluate a string typed
 * by a user, which is why `expression` is DISPLAY TEXT ONLY — do not be tempted
 * to parse it.
 *
 * `params` is the only JSON column in the schema that maps a source `Json`
 * field. It carries NO database default: MySQL needs a parenthesised expression
 * default for JSON and only from 8.0.13, and MariaDB before 10.2.1 rejects one
 * outright. The empty [] belongs in the Eloquent model's $attributes, with a
 * 'params' => 'array' cast.
 *
 * TWO unique indexes (code and key), so upsert() is unsafe here for the same
 * reason as on holidays and the five reference lists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_formulas', function (Blueprint $t): void {
            $t->engine = 'InnoDB';

            $t->char('id', 26)->primary();
            $t->string('code', 40)->unique();

            // Which calculation this row configures — OT, SIL, NSD, REST_DAY.
            // `key` is a MySQL reserved word; Laravel quotes every identifier,
            // so it is safe as long as nothing hand-writes SQL against it.
            $t->string('key', 60)->unique();

            $t->string('name', 160);

            // Display text, e.g. "<Hourly Rate> * <Percentage> * <Max OT>".
            $t->string('expression', 300);

            $t->json('params');

            $t->dateTime('created_at', 3);
            $t->dateTime('updated_at', 3)->nullable();
        });

        $this->dynamicRowFormat('payroll_formulas');
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_formulas');
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
