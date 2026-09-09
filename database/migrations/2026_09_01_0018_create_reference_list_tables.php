<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The five structurally identical reference lists, ported together because they
 * are one shape:
 *
 *   Position               -> positions            (prisma/schema.prisma:610-618)
 *   LeaveType              -> leave_types          (prisma/schema.prisma:620-628)
 *   PayrollDeduction       -> payroll_deductions   (prisma/schema.prisma:630-638)
 *   DocumentType           -> document_types       (prisma/schema.prisma:640-648)
 *   EmploymentStatusOption -> employment_statuses  (prisma/schema.prisma:654-662)
 *
 * They share ReferenceList in the UI and are five tables rather than one with a
 * `kind` column only because the demo store had no way to express a partial
 * unique index. That is preserved: merging them now would change what `code`
 * and `name` are unique ACROSS.
 *
 * TWO CAUTIONS, both from the collation:
 *
 *  1. Each table has TWO unique indexes (code and name). Laravel's
 *     upsert($values, $uniqueBy, $update) IGNORES $uniqueBy on MySQL — it
 *     compiles to INSERT ... ON DUPLICATE KEY UPDATE, which fires on ANY unique
 *     collision. An upsert meaning to key on `code` that collides on `name`
 *     silently rewrites that row's code. Seed these with updateOrCreate keyed on
 *     the intended natural unique, never upsert() and never insertOrIgnore
 *     (INSERT IGNORE downgrades truncation 1406, FK 1452 and NOT NULL to
 *     warnings).
 *  2. utf8mb4_unicode_ci is accent-insensitive as well as case-insensitive, and
 *     document_types is seeded with 'Résumé'. Inserting 'Resume' afterwards is a
 *     1062 duplicate-key error, not a new row — which will look like a bug the
 *     first time someone hits it. This is the deliberate trade that buys back
 *     the source's three `mode: "insensitive"` modifiers for free.
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $tables = [
        'positions',
        'leave_types',
        'payroll_deductions',
        'document_types',
        'employment_statuses',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            Schema::create($table, function (Blueprint $t): void {
                $t->engine = 'InnoDB';

                $t->char('id', 26)->primary();

                // Caps from ReferenceInput (src/lib/api/schemas.ts:95-101).
                $t->string('code', 40)->unique();
                $t->string('name', 160)->unique();
                $t->text('description')->nullable();

                $t->dateTime('created_at', 3);
                $t->dateTime('updated_at', 3)->nullable();
            });
        }

        $this->dynamicRowFormat(...$this->tables);
    }

    public function down(): void
    {
        foreach (array_reverse($this->tables) as $table) {
            Schema::dropIfExists($table);
        }
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
