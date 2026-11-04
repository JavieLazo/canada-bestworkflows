<?php

declare(strict_types=1);

use App\Models\LeaveConversionTable;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Timekeeping\Application\Commands\ApproveConversionTable;
use App\Modules\Timekeeping\Application\ConversionTables;
use App\Modules\Timekeeping\Domain\Credits\MinuteConversion;
use App\Support\Access;
use Illuminate\Support\Str;

/**
 * The standard minutes-to-days conversion table as a seeded PRESET (the
 * PPMC plan's Phase 20, D20): migration 2026_11_02_0018 writes one DRAFT
 * version — an 8-hour day, three decimals, a half rounding down — equal
 * row for row to the formula, only while the table is empty; a leave
 * manager approves it before it converts anything.
 */
function cvpMigration(): mixed
{
    return require base_path('database/migrations/2026_11_02_0018_seed_csc_conversion_table_preset.php');
}

function cvpActor(): Access
{
    $role = new Role;
    $role->forceFill(['name' => 'Preset '.Str::random(6), 'all_data' => true, 'is_system' => false])->save();
    $grant = new RoleModuleGrant;
    $grant->forceFill(['role_id' => $role->getKey(), 'module' => 'LEAVES'])->save();
    $user = User::factory()->create(['role_id' => $role->getKey()]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return app(Access::class);
}

it('seeds the preset once as a draft equal to the formula, leaves a held table alone, and approves into force', function () {
    LeaveConversionTable::query()->delete();
    $migration = cvpMigration();
    $migration->up();
    $migration->up();
    $preset = LeaveConversionTable::query()->sole();
    expect($preset->status)->toBe('DRAFT')
        ->and($preset->effective_from->format('Y-m-d'))->toBe('2025-01-01')
        ->and($preset->decimals)->toBe(3)->and($preset->rounding)->toBe('HALF_DOWN')->and((float) $preset->hours_per_day)->toBe(8.0)
        ->and($preset->rows['minutes']['30'] ?? $preset->rows['minutes'][30])->toBe('0.062')
        ->and($preset->rows['minutes']['60'] ?? $preset->rows['minutes'][60])->toBe('0.125')
        ->and($preset->rows['hours']['8'] ?? $preset->rows['hours'][8])->toBe('1.000')
        ->and(MinuteConversion::normalizeRows($preset->rows, 8.0, 3))->toBe(MinuteConversion::rows(8.0, 3, 'HALF_DOWN'))
        ->and(MinuteConversion::differences($preset->rows, 8.0, 3, 'HALF_DOWN'))->toBe([])
        ->and(ConversionTables::inForce('2026-01-01'))->toBeNull();

    // A held version keeps the seed out; rollback removes only a draft preset.
    $preset->forceFill(['reference' => 'Our own'])->save();
    $migration->down();
    expect(LeaveConversionTable::query()->count())->toBe(1);
    $migration->up();
    expect(LeaveConversionTable::query()->count())->toBe(1);

    $preset->forceFill(['reference' => 'Civil Service Commission minutes-to-days conversion (preset)'])->save();
    app(ApproveConversionTable::class)->handle(cvpActor(), $preset->id);
    expect($preset->refresh()->status)->toBe('APPROVED')
        ->and(ConversionTables::inForce('2026-01-01')?->id)->toBe($preset->id);
    $migration->down();
    expect(LeaveConversionTable::query()->count())->toBe(1);
});
