<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application\Queries;

use App\Models\LeaveConversionTable;
use App\Modules\Timekeeping\Application\ConversionTables;
use App\Modules\Timekeeping\Application\LeaveSettingsAccess;
use App\Modules\Timekeeping\Contracts\Views\LeaveConversionTableView;
use App\Platform\ReadModels\Snapshot;
use App\Support\Access;
use Illuminate\Support\Collection;

/** The conversion table versions, newest effective date first, for whoever may open Leave Credits settings. */
final class ConversionTableRecords
{
    /** @return Collection<int, LeaveConversionTableView> */
    public function list(Access $access): Collection
    {
        if (! LeaveSettingsAccess::mayView($access) || ! ConversionTables::ready()) {
            return new Collection;
        }

        return LeaveConversionTable::query()->orderByDesc('effective_from')->orderByDesc('id')->get()
            ->map(static fn (LeaveConversionTable $row): LeaveConversionTableView => Snapshot::of($row, LeaveConversionTableView::class));
    }

    public function find(Access $access, string $id): ?LeaveConversionTableView
    {
        if (! LeaveSettingsAccess::mayView($access) || ! ConversionTables::ready()) {
            return null;
        }
        $row = LeaveConversionTable::query()->whereKey($id)->first();

        return $row === null ? null : Snapshot::of($row, LeaveConversionTableView::class);
    }

    /** The approved version in force on `$date`, or null. */
    public function inForce(Access $access, string $date): ?LeaveConversionTableView
    {
        if (! LeaveSettingsAccess::mayView($access)) {
            return null;
        }
        $row = ConversionTables::inForce($date);

        return $row === null ? null : Snapshot::of($row, LeaveConversionTableView::class);
    }
}
