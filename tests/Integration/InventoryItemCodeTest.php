<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Models\AuditLog;
use App\Models\InventoryItem;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Inventory\Application\Commands\SaveItem;
use App\Support\Access;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** MariaDB's existing item-code unique index backs up serialized manual-code validation. */
function invitemcodeDbActor(): Access
{
    $role = Role::query()->create(['name' => 'Manual code DB '.Str::random(8), 'all_data' => true, 'is_system' => false]);
    $grant = new RoleModuleGrant;
    $grant->forceFill(['role_id' => $role->id, 'module' => ModuleCode::INVENTORY])->save();

    return Access::forActor(User::factory()->create(['role_id' => $role->id]));
}

/** @return array<string,mixed> */
function invitemcodeDbDraft(string $code): array
{
    return ['code' => $code, 'name' => 'Synthetic item', 'acquired_on' => '2026-01-01', 'cost' => 100];
}

it('rejects case-insensitive manual code collisions on MariaDB without partial edits or audit rows', function () {
    expect(in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true))->toBeTrue();
    $actor = invitemcodeDbActor();
    $first = app(SaveItem::class)->handle($actor, null, invitemcodeDbDraft('Property-0007'));
    $second = app(SaveItem::class)->handle($actor, null, invitemcodeDbDraft('Property-0008'));
    $before = InventoryItem::query()->orderBy('id')->get()->toArray();
    $audit = AuditLog::query()->count();
    foreach ([null, $second] as $id) {
        expect(fn () => app(SaveItem::class)->handle($actor, $id, array_replace(invitemcodeDbDraft(' property-0007 '), ['name' => 'Rejected change'])))
            ->toThrow(RuntimeException::class, 'That item code is already in use. Enter a different code.');
    }
    expect(InventoryItem::query()->orderBy('id')->get()->toArray())->toBe($before)
        ->and(AuditLog::query()->count())->toBe($audit);
    app(SaveItem::class)->handle($actor, $first, invitemcodeDbDraft('PROPERTY-0007'));
    expect(InventoryItem::query()->findOrFail($first)->code)->toBe('PROPERTY-0007');
});

it('keeps the existing database unique index as a backstop while preserving leading zeroes', function () {
    expect(Schema::hasIndex('inventory_items', 'invi_code_unq'))->toBeTrue();
    $actor = invitemcodeDbActor();
    $first = app(SaveItem::class)->handle($actor, null, invitemcodeDbDraft('Asset-0007'));
    app(SaveItem::class)->handle($actor, null, invitemcodeDbDraft('0007'));
    app(SaveItem::class)->handle($actor, null, invitemcodeDbDraft('7'));
    app(SaveItem::class)->handle($actor, null, invitemcodeDbDraft('0'));
    expect(fn () => InventoryItem::query()->create(invitemcodeDbDraft('asset-0007') + ['condition' => 'SERVICEABLE', 'status' => 'IN_STOCK']))
        ->toThrow(UniqueConstraintViolationException::class)
        ->and(InventoryItem::query()->count())->toBe(4)
        ->and(InventoryItem::query()->findOrFail($first)->code)->toBe('Asset-0007')
        ->and(InventoryItem::query()->where('code', '0007')->exists())->toBeTrue()
        ->and(InventoryItem::query()->where('code', '7')->exists())->toBeTrue()
        ->and(InventoryItem::query()->where('code', '0')->exists())->toBeTrue();
});
