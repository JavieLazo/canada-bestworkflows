<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application;

use App\Domain\Enums\ModuleCode;
use App\Support\Access;

/**
 * Who manages the company-wide leave credit settings — each leave type's
 * credit rules, the conversion tables and the earning presets: whoever
 * manages Leave Settings (all-data RECORDS) or leave itself (all-data
 * LEAVES). One employee's leave policy is leave data and stays all-data
 * LEAVES only.
 */
final class LeaveSettingsAccess
{
    private function __construct() {}

    public static function mayManage(Access $access): bool
    {
        return $access->mayReadAllData(ModuleCode::RECORDS) || $access->mayReadAllData(ModuleCode::LEAVES);
    }

    /** Who may open the settings: any holder of either module. */
    public static function mayView(Access $access): bool
    {
        return $access->hasModule(ModuleCode::RECORDS, ModuleCode::LEAVES);
    }
}
