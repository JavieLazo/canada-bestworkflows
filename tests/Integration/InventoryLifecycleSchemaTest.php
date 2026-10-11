<?php

declare(strict_types=1);

use App\Models\InventoryAttachment;
use App\Models\InventoryCount;
use App\Models\InventoryCountLine;
use App\Models\InventoryIncident;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** The production engine must enforce lifecycle evidence constraints and resume every guarded DDL step. */
function invlifeschemaMigration(): mixed
{
    return require base_path('database/migrations/2026_10_08_0002_create_inventory_lifecycle_tables.php');
}

function invlifeschemaChecks(string $table, string $name): int
{
    $row = DB::selectOne("SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'CHECK'", [$table, $name]);

    return (int) ($row->n ?? 0);
}

it('retries lifecycle tables and each constraint without duplicating schema', function () {
    expect(in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true))->toBeTrue();
    $migration = invlifeschemaMigration();
    $migration->up();
    $migration->up();
    foreach ([
        ['inventory_incidents', 'inv_incident_kind_chk'],
        ['inventory_incidents', 'inv_incident_status_chk'],
        ['inventory_counts', 'inv_count_status_chk'],
        ['inventory_count_lines', 'inv_count_result_chk'],
        ['inventory_attachments', 'inv_attachment_owner_chk'],
    ] as [$table, $name]) {
        expect(invlifeschemaChecks($table, $name))->toBe(1);
        DB::statement("ALTER TABLE `{$table}` DROP CONSTRAINT `{$name}`");
        $migration->up();
        expect(invlifeschemaChecks($table, $name))->toBe(1);
    }
    DB::statement('ALTER TABLE `inventory_attachments` DROP FOREIGN KEY `attachments_uploaded_by_fk`');
    DB::statement('ALTER TABLE `inventory_attachments` DROP INDEX `attachments_uploaded_by_idx`');
    expect(Schema::hasForeignKey('inventory_attachments', ['uploaded_by']))->toBeFalse();
    $migration->up();
    $migration->up();
    expect(Schema::hasForeignKey('inventory_attachments', ['uploaded_by']))->toBeTrue()->and(Schema::hasIndex('inventory_attachments', 'attachments_uploaded_by_idx'))->toBeTrue();
});

it('enforces lifecycle statuses and attachment ownership on MariaDB and retains evidence parents', function () {
    $user = User::factory()->create();
    $location = InventoryLocation::query()->create(['name' => 'Schema count location']);
    $item = InventoryItem::query()->create(['code' => 'INV-SCHEMA', 'name' => 'Schema item', 'acquired_on' => '2026-01-01', 'cost' => 100, 'condition' => 'SERVICEABLE', 'status' => 'IN_STOCK', 'location_id' => $location->id]);
    $incident = new InventoryIncident;
    $incident->forceFill(['item_id' => $item->id, 'kind' => 'DAMAGE', 'reported_on' => '2026-10-01', 'reference' => 'SCHEMA-INC', 'details' => 'Schema report', 'item_snapshot' => ['code' => $item->code], 'status' => 'OPEN', 'actor_id' => $user->id, 'actor_name' => 'Schema actor'])->save();
    expect(fn () => DB::table('inventory_incidents')->where('id', $incident->id)->update(['kind' => 'OTHER']))->toThrow(QueryException::class, 'inv_incident_kind_chk');
    expect(fn () => DB::table('inventory_incidents')->where('id', $incident->id)->update(['status' => 'APPROVED']))->toThrow(QueryException::class, 'inv_incident_status_chk');
    $count = new InventoryCount;
    $count->forceFill(['location_id' => $location->id, 'location_name' => $location->name, 'counted_on' => '2026-10-01', 'reference' => 'SCHEMA-COUNT', 'status' => 'OPEN', 'actor_id' => $user->id, 'actor_name' => 'Schema actor'])->save();
    expect(fn () => DB::table('inventory_counts')->where('id', $count->id)->update(['status' => 'MISSING']))->toThrow(QueryException::class, 'inv_count_status_chk');
    $line = InventoryCountLine::query()->create(['count_id' => $count->id, 'item_id' => $item->id, 'item_snapshot' => ['code' => $item->code], 'result' => 'FOUND']);
    expect(fn () => DB::table('inventory_count_lines')->where('id', $line->id)->update(['result' => 'LOST']))->toThrow(QueryException::class, 'inv_count_result_chk');
    expect(fn () => InventoryCountLine::query()->create(['count_id' => $count->id, 'item_id' => $item->id, 'item_snapshot' => [], 'result' => 'FOUND']))->toThrow(QueryException::class, 'inv_count_item_unq');
    $attachment = new InventoryAttachment;
    $attachment->forceFill(['receipt_id' => null, 'disposal_id' => null, 'file_name' => 'test.pdf', 'mime' => 'application/pdf', 'size' => 1, 'path' => 'inventory-evidence/schema', 'uploaded_by' => $user->id]);
    expect(fn () => $attachment->save())->toThrow(QueryException::class, 'inv_attachment_owner_chk');
    expect(fn () => $item->delete())->toThrow(QueryException::class);
    expect(fn () => $count->delete())->toThrow(QueryException::class);
    expect(fn () => $user->delete())->toThrow(QueryException::class);
    expect(InventoryIncident::query()->count())->toBe(1)->and(InventoryCountLine::query()->count())->toBe(1);
});

it('rolls lifecycle schema down twice and restores every guarded table', function () {
    $migration = invlifeschemaMigration();
    $migration->down();
    $migration->down();
    foreach (['inventory_attachments', 'inventory_count_lines', 'inventory_counts', 'inventory_disposals', 'inventory_incidents'] as $table) {
        expect(Schema::hasTable($table))->toBeFalse();
    }
    $migration->up();
    $migration->up();
    foreach (['inventory_attachments', 'inventory_count_lines', 'inventory_counts', 'inventory_disposals', 'inventory_incidents'] as $table) {
        expect(Schema::hasTable($table))->toBeTrue();
    }
});
