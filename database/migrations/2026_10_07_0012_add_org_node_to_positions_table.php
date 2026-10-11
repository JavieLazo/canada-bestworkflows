<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NEW COLUMNS — where each position is placed, and its place in HR's order.
 *
 *  - `org_node_id` names the department, or the unit, the position sits in
 *    (`org_nodes`, 2026_10_07_0011). NULL is "Not placed", which is where
 *    every existing position starts: nothing here guesses a placement.
 *  - `sort_order` is HR's order among the positions of one department or
 *    unit. Every existing row reads 0, and the Position page breaks ties by
 *    name until HR moves one.
 *
 * `positions_org_node_name_unq` (org_node_id, name) keeps a title once inside
 * a department or unit. It cannot fail here, because the global
 * `positions_name_unique` still stands; 2026_10_07_0015 drops that one, so
 * the same title may then be entered in several departments and units.
 * MariaDB treats NULLs in a unique as distinct, so titles among the Not
 * placed positions stay unique by the Organization commands' check under the
 * `copems:org-structure` mutex. `positions_org_node_order_idx` serves each
 * department's or unit's list in `sort_order`.
 *
 * The key (`positions_org_node_id_foreign`, Laravel's own name, so down() can
 * drop it by column on sqlite too) RESTRICTS deletion, the exception the
 * database guide asks a reason for: SET NULL would silently unplace the
 * positions of a deleted department or unit, and CASCADE would delete
 * positions that employees hold. `DeleteOrgNode` refuses first while a row
 * has positions; the key is the backstop.
 *
 * RETRY-SAFE, STEP BY STEP. Production migrates from a temporary cPanel cron
 * that can die between statements, and MariaDB DDL does not roll back, so
 * each column, the unique, the index and the foreign key is its own ALTER
 * behind its own guard (`hasColumn`, `hasIndex` by name, `hasForeignKey` on
 * `org_node_id`). The columns are appended without `->after()`, which keeps
 * the ADDs instant on MariaDB 11.4. The unique and the index come BEFORE the
 * foreign key, so MySQL/MariaDB reuse one for the constraint. down() mirrors
 * up() in reverse with the same guards: the key, then the indexes, then the
 * columns.
 *
 * ROLLBACK LOSES every placement and HR's position order. Positions, their
 * ids, codes, names and descriptions stay. Roll back 2026_10_07_0015 first:
 * it puts back the global unique on `name`, without which this rollback
 * would leave positions with no name unique at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('positions', 'org_node_id')) {
            Schema::table('positions', function (Blueprint $t): void {
                $t->char('org_node_id', 26)->nullable();
            });
        }

        if (! Schema::hasColumn('positions', 'sort_order')) {
            Schema::table('positions', function (Blueprint $t): void {
                $t->unsignedInteger('sort_order')->default(0);
            });
        }

        if (! Schema::hasIndex('positions', 'positions_org_node_name_unq')) {
            Schema::table('positions', function (Blueprint $t): void {
                $t->unique(['org_node_id', 'name'], 'positions_org_node_name_unq');
            });
        }

        if (! Schema::hasIndex('positions', 'positions_org_node_order_idx')) {
            Schema::table('positions', function (Blueprint $t): void {
                $t->index(['org_node_id', 'sort_order'], 'positions_org_node_order_idx');
            });
        }

        if (! Schema::hasForeignKey('positions', ['org_node_id'])) {
            Schema::table('positions', function (Blueprint $t): void {
                $t->foreign('org_node_id')->references('id')->on('org_nodes')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasForeignKey('positions', ['org_node_id'])) {
            Schema::table('positions', function (Blueprint $t): void {
                $t->dropForeign(['org_node_id']);
            });
        }

        if (Schema::hasIndex('positions', 'positions_org_node_order_idx')) {
            Schema::table('positions', function (Blueprint $t): void {
                $t->dropIndex('positions_org_node_order_idx');
            });
        }

        if (Schema::hasIndex('positions', 'positions_org_node_name_unq')) {
            Schema::table('positions', function (Blueprint $t): void {
                $t->dropUnique('positions_org_node_name_unq');
            });
        }

        if (Schema::hasColumn('positions', 'sort_order')) {
            Schema::table('positions', function (Blueprint $t): void {
                $t->dropColumn('sort_order');
            });
        }

        if (Schema::hasColumn('positions', 'org_node_id')) {
            Schema::table('positions', function (Blueprint $t): void {
                $t->dropColumn('org_node_id');
            });
        }
    }
};
