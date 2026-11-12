<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application\Commands;

use App\Domain\Payroll\PayrollPeriod;
use App\Domain\Payroll\StatutorySchedule;
use App\Models\PayrollStatutoryTable;
use App\Modules\Payroll\Application\PayrollSettingsAccess;
use App\Modules\Payroll\Application\StatutoryTables;
use App\Support\Access;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Records that every table's figures were checked and still hold through one
 * date: the yearly check HR makes on all four at once. Each table goes through
 * ConfirmStatutoryTable, so each keeps its own rules and its own audit row, and
 * ONE transaction holds them, so a refusal or a failed audit confirms none. A
 * table already confirmed through that date or later is left as it is.
 */
final class ConfirmAllStatutoryTables
{
    public function handle(Access $access, string $coveredThrough): string
    {
        if (! PayrollSettingsAccess::mayManage($access)) {
            throw new RuntimeException('Your role cannot manage organization-wide data.');
        }
        if (! PayrollPeriod::isPayrollDate($coveredThrough)) {
            throw new RuntimeException('Enter the date the figures are confirmed through.');
        }

        return DB::transaction(static function () use ($access, $coveredThrough): string {
            $confirm = app(ConfirmStatutoryTable::class);
            $confirmed = [];
            foreach (StatutorySchedule::KINDS as $kind) {
                $latest = PayrollStatutoryTable::query()->where('kind', $kind)->orderByDesc('effective_from')->lockForUpdate()->first();
                if ($latest === null || $latest->covered_through->format('Y-m-d') >= $coveredThrough) {
                    continue;
                }
                $confirm->handle($access, (string) $latest->id, $coveredThrough);
                $confirmed[] = StatutoryTables::label($kind);
            }
            if ($confirmed === []) {
                throw new RuntimeException('Every table is already confirmed through that date. Choose a later one.');
            }

            return implode(', ', $confirmed).(count($confirmed) === 1 ? ' table' : ' tables').' confirmed through '.StatutoryTables::day($coveredThrough).'.';
        });
    }
}
