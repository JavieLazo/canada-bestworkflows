<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application;

use Illuminate\Support\Facades\Schema;

/**
 * Whether `overtime_approvals.category` (2026_11_02_0006) exists yet. The
 * host extracts a release's files before its cron migrates, so in that
 * window every decision reads as ordinary overtime and a seaport decision
 * is refused rather than failing on the missing column. Answered once per
 * request.
 */
final class OvertimeCategoryColumn
{
    private function __construct() {}

    public static function present(): bool
    {
        return once(static fn (): bool => Schema::hasColumn('overtime_approvals', 'category'));
    }
}
