<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * DATA — the standard minutes-to-days conversion table as a PRESET
 * version of `leave_conversion_tables`: an 8-hour day, three decimals, a
 * half rounding DOWN (30 minutes = .0625, shown as .062), every minute
 * from 1 to 60 and every hour to 8, worked out here in whole numbers
 * exactly as MinuteConversion::fraction does. Seeded as a DRAFT dated
 * 2025-01-01 while the table is EMPTY, so a leave manager approves it
 * before it converts anything; an installation that already holds a
 * version is left alone.
 *
 * RETRY-SAFE: the seed runs only while the table is empty.
 *
 * ROLLBACK removes the preset only while it is still a draft nobody
 * approved; an approved version is leave history and stays.
 */
return new class extends Migration
{
    private const string REFERENCE = 'Civil Service Commission minutes-to-days conversion (preset)';

    public function up(): void
    {
        if (! Schema::hasTable('leave_conversion_tables') || DB::table('leave_conversion_tables')->exists()) {
            return;
        }
        DB::table('leave_conversion_tables')->insert([
            'id' => (string) Str::ulid(),
            'effective_from' => '2025-01-01',
            'hours_per_day' => 8,
            'decimals' => 3,
            'rounding' => 'HALF_DOWN',
            'rows' => json_encode($this->rows(), JSON_THROW_ON_ERROR),
            'reference' => self::REFERENCE,
            'status' => 'DRAFT',
            'created_by' => null,
            'created_by_name' => null,
            'approved_by' => null,
            'approved_by_name' => null,
            'approved_at' => null,
            'created_at' => now('UTC')->format('Y-m-d H:i:s.v'),
            'updated_at' => null,
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('leave_conversion_tables')) {
            return;
        }
        DB::table('leave_conversion_tables')->where('reference', self::REFERENCE)->where('status', 'DRAFT')->delete();
    }

    /**
     * Every minute to 60 and every hour to 8 as a fraction of a 480-minute
     * day at three decimals, a half rounding down.
     *
     * @return array{minutes: array<int, string>, hours: array<int, string>}
     */
    private function rows(): array
    {
        $fraction = static function (int $minutes): string {
            $numerator = $minutes * 1000;
            $quotient = intdiv($numerator, 480);
            if (($numerator % 480) * 2 > 480) {
                $quotient++;
            }

            return sprintf('%d.%03d', intdiv($quotient, 1000), $quotient % 1000);
        };
        $minutes = [];
        for ($m = 1; $m <= 60; $m++) {
            $minutes[$m] = $fraction($m);
        }
        $hours = [];
        for ($h = 1; $h <= 8; $h++) {
            $hours[$h] = $fraction($h * 60);
        }

        return ['minutes' => $minutes, 'hours' => $hours];
    }
};
