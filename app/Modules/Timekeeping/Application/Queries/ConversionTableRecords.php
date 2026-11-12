<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application\Queries;

use App\Modules\Timekeeping\Application\ConversionTables;
use App\Modules\Timekeeping\Application\LeaveSettingsAccess;
use App\Support\Access;

/**
 * THE minutes-to-days conversion table as Leave Settings shows it, for
 * whoever may open those settings. The table is fixed (ConversionTables),
 * so its rows are all there is to show.
 */
final class ConversionTableRecords
{
    /**
     * @return array{minutes: array<int, string>, hours: array<int, string>}|null
     */
    public function table(Access $access): ?array
    {
        return LeaveSettingsAccess::mayView($access) ? ConversionTables::entry()['rows'] : null;
    }
}
