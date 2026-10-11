<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application;

use Illuminate\Support\Facades\Schema;

/**
 * Whether `employee_profiles.position_id` exists yet. Files reach the host
 * before the cron runs their migration, so every reader of the link asks
 * first and reads "nobody is linked" until it does. Asked once per request
 * through `once()`, never remembered longer; a test that adds or drops the
 * column and then runs app code in the same test calls `Once::flush()`.
 */
final class PositionLink
{
    public static function ready(): bool
    {
        return once(static fn (): bool => Schema::hasColumn('employee_profiles', 'position_id'));
    }
}
