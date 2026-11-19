<?php

declare(strict_types=1);

use App\Models\LeaveConversionTable;
use App\Modules\Timekeeping\Application\ConversionTables;
use App\Modules\Timekeeping\Domain\Credits\MinuteConversion;

/**
 * The standard minutes-to-days conversion table as a seeded PRESET (the
 * PPMC plan's Phase 20, D20): migration 2026_11_02_0018 writes one DRAFT
 * row — an 8-hour day, three decimals, a half rounding down — equal row
 * for row to the formula, only while the table is empty. The table in use
 * is FIXED in code (ConversionTables) with exactly those figures, so the
 * preset is history like every stored row: nothing reads it, and it never
 * changes what converts.
 */
function cvpMigration(): mixed
{
    return require base_path('database/migrations/2026_11_02_0018_seed_csc_conversion_table_preset.php');
}

it('seeds the preset once as a draft equal to the fixed table, and leaves a held table alone', function () {
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
        ->and(MinuteConversion::normalizeRows($preset->rows, 8.0, 3))->toBe(ConversionTables::entry()['rows'])
        ->and(MinuteConversion::differences($preset->rows, 8.0, 3, 'HALF_DOWN'))->toBe([]);

    // A held version keeps the seed out; rollback removes only a draft preset.
    $preset->forceFill(['reference' => 'Our own'])->save();
    $migration->down();
    expect(LeaveConversionTable::query()->count())->toBe(1);
    $migration->up();
    expect(LeaveConversionTable::query()->count())->toBe(1);

    $preset->forceFill(['reference' => 'Civil Service Commission minutes-to-days conversion (preset)', 'status' => 'APPROVED', 'approved_at' => now('UTC')])->save();
    $migration->down();
    expect(LeaveConversionTable::query()->count())->toBe(1);

    // The preset going away changes nothing that converts.
    $preset->delete();
    expect(ConversionTables::entry()['rows'])->toBe(MinuteConversion::rows(8.0, 3, 'HALF_DOWN'));
});
