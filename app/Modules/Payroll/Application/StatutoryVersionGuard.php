<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application;

use App\Domain\Enums\PayrollRunStatus;
use App\Models\PayrollRun;
use App\Models\PayrollStatutoryTable;
use RuntimeException;

/**
 * When a Contribution & Tax Table version may still be corrected or
 * withdrawn: only while it has NOT taken effect — its `effective_from` is
 * after today — and no finalized payroll reaches a date it prices. A
 * version in force, or one a finalized run was computed from, is history
 * and stays as recorded; the way to change its figures is a new version.
 * Draft payrolls never pin a version (they recompute on every view), so
 * they never hold one back.
 */
final class StatutoryVersionGuard
{
    /** Throws when the version (locked by the caller) is no longer editable; `$from` is the earliest date the edit would price from. */
    public static function assertEditable(PayrollStatutoryTable $row, string $today, ?string $from = null): void
    {
        $label = StatutoryTables::label($row->kind);
        $effective = $row->effective_from->format('Y-m-d');
        if ($effective <= $today) {
            throw new RuntimeException("The {$label} table version from ".StatutoryTables::day($effective).' has taken effect and stays on record. Add a new version to change the figures.');
        }
        $earliest = $from === null || $from > $effective ? $effective : $from;
        $finalized = PayrollRun::query()->where('status', PayrollRunStatus::FINALIZED)->where('period_end', '>=', $earliest)->exists();
        if ($finalized) {
            throw new RuntimeException('A finalized payroll already reaches '.StatutoryTables::day($earliest).", so the {$label} table version from ".StatutoryTables::day($effective).' stays on record.');
        }
    }
}
