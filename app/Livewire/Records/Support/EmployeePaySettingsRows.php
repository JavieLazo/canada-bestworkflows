<?php

declare(strict_types=1);

namespace App\Livewire\Records\Support;

use App\Modules\Payroll\Application\GovDeductionPolicyStore;
use App\Modules\Payroll\Application\PayRulesStore;
use App\Modules\Payroll\Application\Queries\GovDeductionPolicyRecords;
use App\Modules\Payroll\Application\Queries\PayRuleRecords;
use App\Modules\Payroll\Application\Queries\RecurringLineRecords;
use App\Modules\Payroll\Contracts\Views\GovDeductionPolicyView;
use App\Modules\Payroll\Contracts\Views\PayRuleView;
use App\Modules\Payroll\Domain\Deductions\GovDeductionSettings;
use App\Modules\Workforce\Application\EmployeePaySettingsInput;
use App\Modules\Workforce\Application\Queries\EmployeePaySettingsRecords;
use App\Modules\Workforce\Contracts\Views\EmployeePaySettingsView;
use App\Support\Access;
use App\Support\EmployeeName;
use App\Support\LocalClock;

/**
 * The Employee Pay Settings rows as the table lists them: searched on name,
 * ID and Employee ID, narrowed to active employees and to those paid apart
 * from the standard computation — a rate of their own on a percentage
 * deduction (the approved settings version in force today), an hourly
 * rate of their own, Pay Basis & Rates of their own
 * now or from a coming date, or recurring lines — then sorted. Each row
 * carries the settings in force, which differ, what prices the employee
 * today (EmployeeRates), their recurring lines and whether a version waits
 * for approval, so the table can mark them.
 */
final class EmployeePaySettingsRows
{
    /** @return list<array{settings: EmployeePaySettingsView, gov: GovDeductionSettings, govVersion: ?GovDeductionPolicyView, customized: list<string>, rates: array{source: string, version: ?PayRuleView, next: ?PayRuleView, drafts: int}, recurring: ?array{count: int, left: ?float}, pendingGov: bool}> */
    public static function list(Access $access, string $search, bool $onlyCustomized, bool $activeOnly, string $sortKey, string $sortDir): array
    {
        $needle = mb_strtolower(trim($search));
        $today = LocalClock::todayKey();
        $records = app(PayRuleRecords::class);
        $own = $records->employeeVersions($access);
        $company = $records->versions($access, PayRulesStore::COMPANY)->values()->all();
        $recurring = app(RecurringLineRecords::class)->summaries($access, $today);
        $policies = app(GovDeductionPolicyRecords::class);
        $all = app(EmployeePaySettingsRecords::class)->list($access);
        $inForce = $policies->inForce($access, array_values($all->map(static fn (EmployeePaySettingsView $s): string => $s->id)->all()), $today);
        $pending = array_flip($policies->employeesWithDrafts($access));
        /** @var array<string, array<int, PayRuleView>> $statuses */
        $statuses = [];
        $rows = [];
        foreach ($all as $settings) {
            if ($activeOnly && ! $settings->is_active) {
                continue;
            }
            $haystack = mb_strtolower(EmployeeName::full($settings).' '.$settings->employee_code.' '.$settings->employee_id_no);
            if ($needle !== '' && ! str_contains($haystack, $needle)) {
                continue;
            }
            $statusId = $settings->employment_status_id;
            if ($statusId !== null && ! array_key_exists($statusId, $statuses)) {
                $statuses[$statusId] = $records->versions($access, $statusId)->values()->all();
            }
            $rates = EmployeeRates::today($own[$settings->id] ?? [], $statusId === null ? [] : $statuses[$statusId], $company, $today);
            $version = $inForce[$settings->id] ?? null;
            $gov = self::effective($settings, $version);
            // Of the settings version, only a rate of their own on a
            // percentage deduction sets an employee apart; its other keys
            // are stored shape that prices nothing.
            $customized = [...array_values(array_intersect($gov->customized(), PaySettingsText::summaryKeys())), ...EmployeePaySettingsInput::customized($settings)];
            $lines = $recurring[$settings->id] ?? null;
            $row = ['settings' => $settings, 'gov' => $gov, 'govVersion' => $version, 'customized' => $customized, 'rates' => $rates, 'recurring' => $lines, 'pendingGov' => isset($pending[$settings->id])];
            if ($onlyCustomized && ! self::differs($row)) {
                continue;
            }
            $rows[] = $row;
        }

        usort($rows, static function (array $a, array $b) use ($sortKey): int {
            $byName = Hris::compareText($a['settings']->last_name.' '.$a['settings']->first_name, $b['settings']->last_name.' '.$b['settings']->first_name);

            return match ($sortKey) {
                'status' => Hris::compareText($a['settings']->employment_status, $b['settings']->employment_status) ?: $byName,
                default => $byName,
            };
        });

        return $sortDir === 'desc' ? array_reverse($rows) : $rows;
    }

    /**
     * Whether a row is paid apart from the standard computation — the ONE
     * test behind the "Only customized" filter and the list's count of them.
     *
     * @param  array{customized: list<string>, rates: array{source: string, version: ?PayRuleView, next: ?PayRuleView, drafts: int}, recurring: ?array{count: int, left: ?float}, pendingGov: bool}  $row
     */
    public static function differs(array $row): bool
    {
        return $row['customized'] !== [] || EmployeeRates::customized($row['rates']) || $row['recurring'] !== null || self::awaiting($row);
    }

    /**
     * Whether something of the row's still waits for approval.
     *
     * @param  array{rates: array{source: string, version: ?PayRuleView, next: ?PayRuleView, drafts: int}, pendingGov: bool}  $row
     */
    public static function awaiting(array $row): bool
    {
        return $row['pendingGov'] || $row['rates']['drafts'] > 0;
    }

    /**
     * What the rows add up to, for the figures above the table: how many are
     * listed, how many are paid apart from the standard computation and, by
     * the table's own columns, how many have a percentage deduction rate
     * of their own, rates of their own (the hourly rate or Pay Basis & Rates) and a
     * loan or an allowance.
     *
     * @param  list<array{customized: list<string>, rates: array{source: string, version: ?PayRuleView, next: ?PayRuleView, drafts: int}, recurring: ?array{count: int, left: ?float}, pendingGov: bool}>  $rows
     * @return array{listed: int, customized: int, gov: int, rates: int, recurring: int}
     */
    public static function summary(array $rows): array
    {
        $summary = ['listed' => count($rows), 'customized' => 0, 'gov' => 0, 'rates' => 0, 'recurring' => 0];
        $govKeys = PaySettingsText::summaryKeys();
        foreach ($rows as $row) {
            $summary['customized'] += self::differs($row) ? 1 : 0;
            $summary['gov'] += array_intersect($row['customized'], $govKeys) !== [] ? 1 : 0;
            $summary['rates'] += in_array('hourly_rate', $row['customized'], true) || EmployeeRates::customized($row['rates']) ? 1 : 0;
            $summary['recurring'] += $row['recurring'] !== null ? 1 : 0;
        }

        return $summary;
    }

    /** The employee's settings in force: the approved version, else the record's baseline. */
    public static function effective(EmployeePaySettingsView $settings, ?GovDeductionPolicyView $version): GovDeductionSettings
    {
        if ($version === null) {
            return GovDeductionSettings::fromColumns($settings);
        }
        try {
            return GovDeductionSettings::fromArray($version->params);
        } catch (\InvalidArgumentException) {
            return GovDeductionSettings::fromColumns($settings);
        }
    }

    /** Whether versions exist on this installation at all (the window before the cron migrates). */
    public static function versionsReady(): bool
    {
        return GovDeductionPolicyStore::ready();
    }
}
