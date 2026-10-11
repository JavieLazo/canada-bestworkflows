<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * NEW TABLE — it has no counterpart in prisma/schema.prisma (DELIBERATE
 * DIVERGENCE: the reference system has a flat position list).
 *
 * The company's departments and the units inside each one, in the order HR
 * sets: one table with a `kind`, because both levels carry the same columns
 * and one bare-number `code` series numbers them both. `kind` is DEPARTMENT |
 * UNIT (varchar plus `orgn_kind_chk`). A department has no `parent_id`; a unit
 * always names its department (`orgn_parent_chk`). That a unit's parent is a
 * DEPARTMENT, and not another unit, is a rule over another row, which a CHECK
 * cannot read: the Organization commands enforce it under the
 * `copems:org-structure` mutex (2026_10_07_0010). Positions point here from
 * `positions.org_node_id` (2026_10_07_0012).
 *
 * `orgn_parent_name_unq` (parent_id, name) keeps a name once among a
 * department's units. MariaDB treats NULLs in a unique as distinct, so it does
 * not hold among the departments themselves (all NULL parents): that one is
 * the commands' check under the mutex too. The collation is case- and
 * accent-insensitive, so "Finance" and "finance" collide inside a department.
 * `orgn_parent_order_idx` serves each sibling list in `sort_order`.
 *
 * `orgn_parent_fk` RESTRICTS deletion, the exception the database guide asks a
 * reason for: CASCADE would silently delete a department's units, and SET
 * NULL would silently turn its units into departments (which
 * `orgn_parent_chk` refuses anyway). `DeleteOrgNode` refuses first with a
 * readable sentence while a row has units or positions; the key is the
 * backstop for a delete that bypasses it.
 *
 * RETRY-SAFE, STEP BY STEP. Production migrates from a temporary cPanel cron
 * that can die between statements, and MariaDB DDL does not roll back, so the
 * create carries only the columns and the primary key, and every other piece
 * is its own ALTER behind its own guard: each unique and index while
 * hasIndex() does not list its name, the foreign key while hasForeignKey()
 * finds none on `parent_id`, each CHECK while information_schema lists no
 * constraint of that name. The uniques and the index come BEFORE the foreign
 * key, so MySQL/MariaDB reuse one for the constraint. ROW_FORMAT=DYNAMIC
 * restates the same format on a retry. On sqlite (tests) the foreign key is a
 * table rebuild and no CHECK is created. down() drops the table only if it
 * exists.
 *
 * ROLLBACK LOSES every department and unit. It refuses on MySQL/MariaDB while
 * a position still points at a row (`positions_org_node_id_foreign`), so roll back
 * 2026_10_07_0012 first, which loses the placements.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('org_nodes')) {
            Schema::create('org_nodes', function (Blueprint $t): void {
                $t->engine = 'InnoDB';

                $t->char('id', 26)->primary();

                // A bare number, one series for both kinds (codes-and-locks guide).
                $t->string('code', 40);

                // DEPARTMENT | UNIT — free text plus orgn_kind_chk.
                $t->string('kind', 12);

                $t->string('name', 160);
                $t->text('description')->nullable();

                // NULL for a department; its department for a unit.
                $t->char('parent_id', 26)->nullable();

                // HR's order among the row's siblings.
                $t->unsignedInteger('sort_order')->default(0);

                $t->dateTime('created_at', 3);
                $t->dateTime('updated_at', 3)->nullable();
            });
        }

        // Each step below is checked on its own, not under the table check: a
        // run that stopped after the create has the table and none of them.
        if (! Schema::hasIndex('org_nodes', 'orgn_code_unq')) {
            Schema::table('org_nodes', function (Blueprint $t): void {
                $t->unique('code', 'orgn_code_unq');
            });
        }

        if (! Schema::hasIndex('org_nodes', 'orgn_parent_name_unq')) {
            Schema::table('org_nodes', function (Blueprint $t): void {
                $t->unique(['parent_id', 'name'], 'orgn_parent_name_unq');
            });
        }

        if (! Schema::hasIndex('org_nodes', 'orgn_parent_order_idx')) {
            Schema::table('org_nodes', function (Blueprint $t): void {
                $t->index(['parent_id', 'sort_order'], 'orgn_parent_order_idx');
            });
        }

        if (! Schema::hasForeignKey('org_nodes', ['parent_id'])) {
            Schema::table('org_nodes', function (Blueprint $t): void {
                $t->foreign('parent_id', 'orgn_parent_fk')->references('id')->on('org_nodes')->restrictOnDelete();
            });
        }

        if ($this->isMySql()) {
            foreach ([
                'orgn_kind_chk' => "`kind` IN ('DEPARTMENT','UNIT')",
                'orgn_parent_chk' => "(`kind` = 'DEPARTMENT' AND `parent_id` IS NULL) OR (`kind` = 'UNIT' AND `parent_id` IS NOT NULL)",
            ] as $name => $expression) {
                if (! $this->hasCheck('org_nodes', $name)) {
                    DB::statement("ALTER TABLE `org_nodes` ADD CONSTRAINT `{$name}` CHECK ({$expression})");
                }
            }
        }

        // Idempotent: a retry restates the same row format.
        $this->dynamicRowFormat('org_nodes');
    }

    public function down(): void
    {
        // The indexes, the key and the CHECKs go with the table.
        if (Schema::hasTable('org_nodes')) {
            Schema::drop('org_nodes');
        }
    }

    /** Whether the named CHECK exists — information_schema, so MySQL/MariaDB only. */
    private function hasCheck(string $table, string $name): bool
    {
        $row = DB::selectOne(
            "SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'CHECK'",
            [$table, $name],
        );

        return (int) ($row->n ?? 0) > 0;
    }

    private function dynamicRowFormat(string ...$tables): void
    {
        if (! $this->isMySql()) {
            return;
        }

        foreach ($tables as $table) {
            DB::statement("ALTER TABLE `{$table}` ROW_FORMAT=DYNAMIC");
        }
    }

    private function isMySql(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
