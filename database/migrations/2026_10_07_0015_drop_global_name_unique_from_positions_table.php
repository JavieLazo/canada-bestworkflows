<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DESTRUCTIVE DDL — drops the unique on `positions.name` alone, so the same
 * title may be entered in more than one department and unit. A title stays
 * unique inside one department or unit through `positions_org_node_name_unq`
 * (2026_10_07_0012), and among the Not placed positions through the
 * Organization commands' check under the `copems:org-structure` mutex.
 *
 * It runs after the link backfill (2026_10_07_0014), whose "a title names at
 * most one position" premise holds only while this unique stands, and in a
 * migration of its own, so an operator can run the copy alone and check it
 * first.
 *
 * The unique is found by its COLUMNS, not by a name this file assumes: any
 * unique index over exactly `name` is dropped by the name the schema reports
 * (`positions_name_unique` from 2026_09_01_0018 on every known install). It
 * refuses with a readable sentence while `positions_org_node_name_unq` is
 * missing, because dropping it then would leave positions with no name unique
 * at all.
 *
 * RETRY-SAFE: once no unique over `name` remains, a re-run sends no DDL.
 *
 * ROLLBACK LOSES the ability to repeat a title. down() puts the unique back as
 * `positions_name_unique` only while it is absent, and REFUSES, naming the
 * titles, while any title is held by more than one position (compared by the
 * column's collation, as the unique would): rename those first.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('positions') || ! Schema::hasIndex('positions', ['name'], 'unique')) {
            return;
        }

        if (! Schema::hasIndex('positions', 'positions_org_node_name_unq')) {
            throw new RuntimeException('The unique on positions.name can only be dropped once positions_org_node_name_unq exists: run 2026_10_07_0012_add_org_node_to_positions_table first.');
        }

        foreach ($this->nameUniques() as $index) {
            Schema::table('positions', function (Blueprint $t) use ($index): void {
                $t->dropUnique($index);
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('positions') || Schema::hasIndex('positions', ['name'], 'unique')) {
            return;
        }

        $repeated = DB::table('positions')
            ->select('name')
            ->groupBy('name')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('name')
            ->pluck('name')
            ->map(static fn (mixed $name): string => '"'.(string) $name.'"')
            ->all();

        if ($repeated !== []) {
            throw new RuntimeException('A position title cannot be made unique again while more than one position holds it. Rename the repeated titles first: '.implode(', ', $repeated).'.');
        }

        Schema::table('positions', function (Blueprint $t): void {
            $t->unique('name', 'positions_name_unique');
        });
    }

    /**
     * The names of the unique indexes over exactly `name`, as the schema
     * reports them.
     *
     * @return list<string>
     */
    private function nameUniques(): array
    {
        $names = [];
        foreach (Schema::getIndexes('positions') as $index) {
            if ($index['unique'] && ! $index['primary'] && $index['columns'] === ['name']) {
                $names[] = (string) $index['name'];
            }
        }

        return $names;
    }
};
