<?php

declare(strict_types=1);

namespace App\Livewire\Payroll;

use App\Domain\Enums\ModuleCode;
use App\Domain\Payroll\StatutorySchedule;
use App\Livewire\Records\ReferenceListPage;
use App\Livewire\Records\StatutoryTablesPage;
use App\Livewire\Records\Support\BenefitText;
use App\Livewire\Records\Support\DeductionText;
use App\Livewire\Records\Support\EmployeePaySettingsRows;
use App\Livewire\Records\Support\EmployeeRates;
use App\Livewire\Records\Support\Hris;
use App\Livewire\Records\Support\PaySettingsText;
use App\Modules\Payroll\Application\PayrollPeriodText;
use App\Modules\Payroll\Application\PayRulesStore;
use App\Modules\Payroll\Application\Queries\GovDeductionPolicyRecords;
use App\Modules\Payroll\Application\Queries\IncentiveTypeRecords;
use App\Modules\Payroll\Application\Queries\PayRuleRecords;
use App\Modules\Payroll\Application\Queries\PayslipRecords;
use App\Modules\Payroll\Application\Queries\RecurringLineRecords;
use App\Modules\Payroll\Application\Queries\StatutoryTableRecords;
use App\Modules\Payroll\Application\StatutoryTables;
use App\Modules\Payroll\Contracts\Views\PayRuleView;
use App\Modules\Payroll\Contracts\Views\RecurringLineView;
use App\Modules\Payroll\Contracts\Views\StatutoryTableView;
use App\Modules\Payroll\Domain\Benefits\BenefitRule;
use App\Modules\Payroll\Domain\Deductions\GovDeductionSettings;
use App\Modules\Payroll\Domain\Recurring\RecurringLine;
use App\Modules\Workforce\Application\Queries\EmployeePaySettingsRecords;
use App\Modules\Workforce\Contracts\Views\EmployeePaySettingsView;
use App\Platform\Records\ReferenceCatalogs;
use App\Support\Access;
use App\Support\EmployeeName;
use App\Support\ManilaClock;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Payroll → Payroll Settings: what payroll uses today, and where to change
 * it. One read-only page over the settings' own queries — the Contribution
 * & Tax Tables in force and how far their figures are confirmed, who each Pay Basis & Rates
 * version prices, the Payroll Deductions and Incentives & Bonuses by how
 * their amounts are found, the employees paid apart from the company
 * settings — and, at the top, what would stop or spoil the next payroll,
 * each item linking to the page that fixes it. Nothing here writes.
 *
 * The catalogs show to every PAYROLL holder; anything that names an
 * employee or their pay (the employees paid differently, an employee with
 * no rate, a loan balance, a loan nearly paid off, the employee search) is
 * pay data and shows only to an all-data PAYROLL holder, as on Employee Pay
 * Settings.
 */
#[Title('Payroll Settings')]
final class SettingsOverview extends Component
{
    /** How close the end of a table's confirmation is before it is flagged, in days. */
    public const int TABLE_WARNING_DAYS = 90;

    /** How many employees paid differently the overview lists before "All employees". */
    public const int EMPLOYEES_SHOWN = 8;

    /** A pick in the employee search opens that employee's pay settings. */
    #[On('kit-employee-picked')]
    public function employeePicked(string $name, string $id): void
    {
        $access = app(Access::class);
        if ($name !== 'settings-employee' || $id === '' || ! $access->mayReadAllData(ModuleCode::PAYROLL)) {
            return;
        }
        if (app(EmployeePaySettingsRecords::class)->find($access, $id) === null) {
            return;
        }
        $this->redirectRoute('records.employee-pay-settings.show', ['employee' => $id], navigate: true);
    }

    public function render(): View
    {
        $access = app(Access::class);
        $today = ManilaClock::todayKey();
        $payData = $access->mayReadAllData(ModuleCode::PAYROLL);

        $tables = $this->tables($access, $today);
        $rates = $this->rates($access, $today, $payData);
        $employees = $payData ? app(EmployeePaySettingsRecords::class)->list($access)->keyBy('id') : collect();
        $lines = $payData ? app(RecurringLineRecords::class)->open($access, $today) : collect();
        // Days paid in advance on a posted payroll that nothing has settled yet.
        $unsettled = $payData ? app(PayslipRecords::class)->unsettledAdvances($access) : [];

        // Versions still waiting for approval: an employee's own
        // rates and Contributions & tax drafts.
        $pending = $payData ? $this->pendingVersions($access) : 0;

        return view('livewire.payroll.settings-overview', [
            'payData' => $payData,
            'attention' => $this->attention($tables, $rates, $employees, $lines, $today, $unsettled, $pending),
            'tables' => $tables,
            'tablesReady' => StatutoryTables::ready(),
            'rates' => $rates,
            'deductions' => $this->deductions($access, $lines),
            'benefits' => $this->benefits($access, $lines),
            'differs' => $payData ? $this->employeesPaidDifferently($access) : ['rows' => [], 'total' => 0],
        ]);
    }

    /**
     * Each table's version in force today, how far its latest version's
     * figures are confirmed and the version coming after it, if one is saved.
     *
     * @return list<array{kind: string, label: string, current: ?StatutoryTableView, through: ?CarbonImmutable, daysLeft: ?int, next: ?StatutoryTableView}>
     */
    private function tables(Access $access, string $today): array
    {
        $records = app(StatutoryTableRecords::class);
        $tables = [];
        foreach (StatutorySchedule::KINDS as $kind) {
            $versions = $records->versions($access, $kind);
            $current = $versions->first(static fn (StatutoryTableView $v): bool => $v->effective_from->format('Y-m-d') <= $today);
            $next = $versions->filter(static fn (StatutoryTableView $v): bool => $v->effective_from->format('Y-m-d') > $today)->last();
            $through = $versions->first()?->covered_through;
            $tables[] = [
                'kind' => $kind,
                'label' => StatutoryTablesPage::TABS[$kind],
                'current' => $current,
                'through' => $through,
                'daysLeft' => $through === null ? null : (int) CarbonImmutable::parse($today, 'UTC')->diffInDays($through->startOfDay(), false),
                'next' => $next,
            ];
        }

        return $tables;
    }

    /**
     * The company rule in force, each Employment status override, and the
     * version due next for each; with pay data, how many employees have
     * rates of their own.
     *
     * @return array{company: ?PayRuleView, companyNext: ?PayRuleView, statuses: list<array{id: string, name: string, current: ?PayRuleView, next: ?PayRuleView}>, own: ?int, changes: list<array{what: string, from: CarbonImmutable}>}
     */
    private function rates(Access $access, string $today, bool $payData): array
    {
        $records = app(PayRuleRecords::class);
        [$company, $companyNext] = self::inForceAndNext($records->versions($access, PayRulesStore::COMPANY)->all(), $today);
        $names = [];
        foreach (app(ReferenceCatalogs::class)->for('employment')->options($access) as $option) {
            $names[$option['id']] = $option['name'];
        }
        $statuses = [];
        foreach ($records->overriddenScopes($access) as $statusId) {
            [$current, $next] = self::inForceAndNext($records->versions($access, $statusId)->all(), $today);
            $statuses[] = ['id' => $statusId, 'name' => $names[$statusId] ?? 'A removed status', 'current' => $current, 'next' => $next];
        }
        usort($statuses, static fn (array $a, array $b): int => Hris::compareText($a['name'], $b['name']));

        $own = null;
        if ($payData) {
            $own = 0;
            foreach ($records->employeeVersions($access) as $versions) {
                if (EmployeeRates::customized(EmployeeRates::today($versions, [], [], $today))) {
                    $own++;
                }
            }
        }

        // Every version still to take effect, soonest first.
        $changes = $companyNext !== null ? [['what' => 'Company rule', 'from' => $companyNext->effective_from]] : [];
        foreach ($statuses as $status) {
            if ($status['next'] !== null) {
                $changes[] = ['what' => $status['name'].' override', 'from' => $status['next']->effective_from];
            }
        }
        usort($changes, static fn (array $a, array $b): int => $a['from'] <=> $b['from']);

        return ['company' => $company, 'companyNext' => $companyNext, 'statuses' => $statuses, 'own' => $own, 'changes' => $changes];
    }

    /** How many employee versions — own rates and Contributions & tax — wait for approval. */
    private function pendingVersions(Access $access): int
    {
        $drafts = 0;
        foreach (app(PayRuleRecords::class)->employeeVersions($access) as $versions) {
            foreach ($versions as $version) {
                if ($version->status === PayRulesStore::DRAFT) {
                    $drafts++;
                }
            }
        }

        return $drafts + app(GovDeductionPolicyRecords::class)->draftCount($access);
    }

    /**
     * The version in force on a day and the first one after it, from a
     * scope's versions newest first.
     *
     * @param  array<int, PayRuleView>  $versions
     * @return array{0: ?PayRuleView, 1: ?PayRuleView}
     */
    private static function inForceAndNext(array $versions, string $today): array
    {
        $current = null;
        $next = null;
        foreach ($versions as $version) {
            if ($version->effective_from->format('Y-m-d') > $today) {
                $next = $version;
            } elseif ($current === null) {
                $current = $version;
            }
        }

        return [$current, $next];
    }

    /**
     * The Payroll Deductions by how their amount is found, and how many
     * employees a recurring line charges each typed row.
     *
     * @param  iterable<RecurringLineView>  $lines
     * @return array{computed: list<string>, percent: list<array{name: string, detail: string}>, typed: list<array{name: string, employees: int}>, running: int}
     */
    private function deductions(Access $access, iterable $lines): array
    {
        $paying = [];
        foreach ($lines as $line) {
            if ($line->kind === RecurringLine::KIND_DEDUCTION) {
                $paying[(string) $line->deduction_id][$line->employee_id] = true;
            }
        }
        $groups = ['computed' => [], 'percent' => [], 'typed' => [], 'running' => array_sum(array_map('count', $paying))];
        $rows = app(ReferenceCatalogs::class)->for(ReferenceListPage::PAYROLL_LIST)->page($access, '', 'name', 'asc', 1, 500);
        foreach ($rows->items() as $row) {
            if (($row->calculation ?? null) !== null) {
                $groups['computed'][] = (string) $row->name;
            } elseif (is_array($row->percent ?? null)) {
                $groups['percent'][] = ['name' => (string) $row->name, 'detail' => DeductionText::percent($row->percent)];
            } else {
                $groups['typed'][] = ['name' => (string) $row->name, 'employees' => count($paying[(string) $row->id] ?? [])];
            }
        }

        return $groups;
    }

    /**
     * The Incentives & Bonuses: the ones payroll proposes on its own, and
     * the ones typed per payroll or set per employee as a recurring line.
     *
     * @param  iterable<RecurringLineView>  $lines
     * @return array{scheduled: list<array{name: string, detail: string}>, typed: list<array{name: string, detail: string}>}
     */
    private function benefits(Access $access, iterable $lines): array
    {
        $recurring = [];
        foreach ($lines as $line) {
            if ($line->kind === RecurringLine::KIND_INCENTIVE) {
                $recurring[(string) $line->incentive_type_id][$line->employee_id] = true;
            }
        }
        $names = [];
        foreach (app(ReferenceCatalogs::class)->for('employment')->options($access) as $option) {
            $names[$option['id']] = $option['name'];
        }
        $groups = ['scheduled' => [], 'typed' => []];
        foreach (app(IncentiveTypeRecords::class)->list($access) as $type) {
            $scheduled = $type->amount_type !== null && $type->amount_type !== BenefitRule::AMOUNT_TYPED
                && $type->frequency !== null && $type->frequency !== BenefitRule::FREQUENCY_ONCE;
            if ($scheduled) {
                $groups['scheduled'][] = ['name' => $type->name, 'detail' => implode(' · ', [BenefitText::amount($type), BenefitText::frequency($type), BenefitText::tax($type), BenefitText::who($type, $names)])];
            } else {
                $count = count($recurring[$type->id] ?? []);
                $groups['typed'][] = ['name' => $type->name, 'detail' => BenefitText::tax($type).($count > 0 ? ' · '.$count.' '.($count === 1 ? 'employee has' : 'employees have').' it as a recurring line' : '')];
            }
        }

        return $groups;
    }

    /**
     * The active employees paid apart from the company settings, each with
     * what differs in words, the first few of them.
     *
     * @return array{rows: list<array{id: string, name: string, status: string, differs: list<string>}>, total: int}
     */
    private function employeesPaidDifferently(Access $access): array
    {
        $rows = EmployeePaySettingsRows::list($access, '', true, true, 'name', 'asc');
        $shown = [];
        foreach (array_slice($rows, 0, self::EMPLOYEES_SHOWN) as $row) {
            $settings = $row['settings'];
            $customized = $row['customized'];
            $differs = [];
            foreach (GovDeductionSettings::CONTRIBUTIONS as $key => $name) {
                if (array_intersect($customized, ["allow_{$key}", "fixed_{$key}_ee", "fixed_{$key}_er", "{$key}_mode"]) !== []) {
                    $differs[] = $name.': '.PaySettingsText::contribution($row['gov'], $key);
                }
            }
            if (array_intersect($customized, ['allow_withholding_tax', 'annualize_tax', 'fixed_tax', 'percent_tax']) !== []) {
                $differs[] = 'Withholding tax: '.PaySettingsText::withholding($row['gov']);
            }
            if (in_array('hourly_rate', $customized, true)) {
                $differs[] = 'Hourly rate: '.PaySettingsText::hourly($settings);
            }
            if (EmployeeRates::customized($row['rates'])) {
                $differs[] = 'Pay Basis & Rates of their own';
            }
            if ($row['pendingGov'] || $row['rates']['drafts'] > 0) {
                $differs[] = 'A version awaiting approval';
            }
            if ($row['recurring'] !== null) {
                $count = $row['recurring']['count'];
                $differs[] = $count.' recurring '.($count === 1 ? 'line' : 'lines')
                    .($row['recurring']['left'] !== null ? ', '.Hris::peso($row['recurring']['left']).' left to pay' : '');
            }
            $shown[] = ['id' => $settings->id, 'name' => EmployeeName::display($settings), 'status' => (string) $settings->employment_status, 'differs' => $differs];
        }

        return ['rows' => $shown, 'total' => count($rows)];
    }

    /**
     * What would stop or spoil the next payroll, most pressing first:
     * tables whose figures are due a check (payroll past the confirmed date
     * runs with a reminder), active employees
     * with no rate (paid nothing), loan balances left on employees who have
     * left (no payroll deducts them), versions about to take effect (draft
     * payrolls covering their date need a fresh review), and loans the next
     * payroll pays off (for information).
     *
     * @param  list<array{kind: string, label: string, current: ?StatutoryTableView, through: ?CarbonImmutable, daysLeft: ?int, next: ?StatutoryTableView}>  $tables
     * @param  array{company: ?PayRuleView, companyNext: ?PayRuleView, statuses: list<array{id: string, name: string, current: ?PayRuleView, next: ?PayRuleView}>, own: ?int, changes: list<array{what: string, from: CarbonImmutable}>}  $rates
     * @param  Collection<string, EmployeePaySettingsView>  $employees
     * @param  Collection<int, RecurringLineView>  $lines
     * @param  list<array{employee_id: string, code: string, from: string, to: string, period_end: string}>  $unsettled  days paid in advance that nothing has settled
     * @return list<array{title: string, detail: string, tier: string, pill: string, link: string, href: string}>
     */
    private function attention(array $tables, array $rates, Collection $employees, Collection $lines, string $today, array $unsettled = [], int $pending = 0): array
    {
        $items = [];

        if ($pending > 0) {
            $items[] = [
                'title' => ($pending === 1 ? 'One employee version waits' : $pending.' employee versions wait').' for approval.',
                'detail' => 'An employee\'s own Pay Basis & Rates or Contributions & tax settings take effect once someone other than the preparer approves them.',
                'tier' => 'pill-caution',
                'pill' => 'Awaiting approval',
                'link' => 'Open employees',
                'href' => route('records.employee-pay-settings'),
            ];
        }

        $ending = [];
        foreach ($tables as $table) {
            if ($table['through'] !== null && $table['daysLeft'] !== null && $table['daysLeft'] <= self::TABLE_WARNING_DAYS) {
                $ending[$table['through']->format('Y-m-d')][] = $table;
            }
        }
        ksort($ending);
        foreach ($ending as $through => $group) {
            $days = $group[0]['daysLeft'];
            $labels = array_column($group, 'label');
            $items[] = [
                'title' => self::listed($labels).' '.(count($labels) === 1 ? 'table' : 'tables').($days < 0 ? ' not confirmed past ' : ' confirmed through ').PayrollPeriodText::day($through).'.',
                'detail' => 'Payrolls past that date still run, with a reminder to check the figures. Confirm '.(count($labels) === 1 ? 'the table' : 'each table').' once its figures are checked, or add a version with the new ones.',
                'tier' => 'pill-caution',
                'pill' => $days < 0 ? 'Check due' : ($days === 0 ? 'Check due today' : ($days === 1 ? '1 day left' : $days.' days left')),
                'link' => 'Open tables',
                'href' => route('records.contribution-tables'),
            ];
        }

        $noRate = $employees->filter(static fn (EmployeePaySettingsView $e): bool => $e->is_active && (float) ($e->monthly_rate ?? 0) <= 0.0 && (float) ($e->daily_rate ?? 0) <= 0.0)->values();
        if ($noRate->isNotEmpty()) {
            $names = $noRate->map(static fn (EmployeePaySettingsView $e): string => EmployeeName::display($e))->all();
            $items[] = [
                'title' => self::listed($names, 3).' '.(count($names) === 1 ? 'has' : 'have').' no Rate Per Month or Rate Per Day.',
                'detail' => 'Payroll would pay '.(count($names) === 1 ? 'them' : 'each of them').' nothing. The rate is set on Employee Details.',
                'tier' => 'pill-danger',
                'pill' => 'Paid ₱0.00',
                'link' => count($names) === 1 ? 'Open Employee Details' : 'Open Employee Records',
                'href' => count($names) === 1 ? route('employees.show', ['employee' => $noRate->first()->id]) : route('employees.index'),
            ];
        }

        foreach ($lines as $line) {
            $employee = $employees->get($line->employee_id);
            if ($employee === null || $employee->is_active || $line->kind !== RecurringLine::KIND_DEDUCTION || ($line->left ?? 0.0) <= 0.0) {
                continue;
            }
            $items[] = [
                'title' => EmployeeName::display($employee).' has left with '.Hris::peso((float) $line->left).' still owed on '.$line->type_name.'.',
                'detail' => 'Payroll no longer deducts it. Settle it outside payroll, then end the line.',
                'tier' => 'pill-caution',
                'pill' => 'Unpaid balance',
                'link' => 'Open pay settings',
                'href' => route('records.employee-pay-settings.show', ['employee' => $employee->id]),
            ];
        }

        $coming = [];
        if ($rates['companyNext'] !== null) {
            $coming[] = ['New company Pay Basis & Rates start on', $rates['companyNext']->effective_from];
        }
        foreach ($rates['statuses'] as $status) {
            if ($status['next'] !== null) {
                $coming[] = ['New '.$status['name'].' rates start on', $status['next']->effective_from];
            }
        }
        foreach ($tables as $table) {
            if ($table['next'] !== null) {
                $coming[] = ['A new '.$table['label'].' table starts on', $table['next']->effective_from];
            }
        }
        usort($coming, static fn (array $a, array $b): int => $a[1] <=> $b[1]);
        foreach ($coming as [$what, $date]) {
            $items[] = [
                'title' => $what.' '.PayrollPeriodText::day($date->format('Y-m-d')).'.',
                'detail' => 'Draft payrolls covering that date will need a fresh review before they can be finalized.',
                'tier' => 'pill-caution',
                'pill' => 'Coming up',
                'link' => str_contains($what, 'table') ? 'Open tables' : 'Open rates',
                'href' => str_contains($what, 'table') ? route('records.contribution-tables') : route('records.pay-rates'),
            ];
        }

        // Days paid in advance on a posted payroll that no later payroll
        // has settled: an employee who left, or who was left off the next
        // payroll, still has days to settle (AdvanceSettlements).
        foreach ($unsettled as $pending) {
            $employee = $employees->get($pending['employee_id']);
            if ($employee === null) {
                continue;
            }
            $items[] = [
                'title' => EmployeeName::display($employee).'\'s days paid in advance on payroll '.$pending['code'].' ('.PayrollPeriodText::day($pending['from']).' to '.PayrollPeriodText::day($pending['to']).') are not settled yet.',
                'detail' => $employee->is_active
                    ? 'Their next regular payroll settles them once that period has ended.'
                    : 'They are no longer active, so no payroll will settle them on its own. Settle the difference outside payroll, or put them on one more payroll.',
                'tier' => $employee->is_active && $pending['period_end'] >= $today ? 'pill-muted' : 'pill-caution',
                'pill' => $employee->is_active && $pending['period_end'] >= $today ? 'For information' : 'Unsettled days',
                'link' => 'Open payroll',
                'href' => route('payroll.index'),
            ];
        }

        foreach ($lines as $line) {
            $employee = $employees->get($line->employee_id);
            if ($employee === null || ! $employee->is_active || $line->kind !== RecurringLine::KIND_DEDUCTION
                || $line->status !== RecurringLineRecords::ACTIVE || $line->left === null || $line->left <= 0.0 || $line->left > $line->amount) {
                continue;
            }
            $items[] = [
                'title' => EmployeeName::display($employee).'\'s '.$line->type_name.' is paid off on the next payroll it is due on.',
                'detail' => 'Nothing to do: the line stops by itself.',
                'tier' => 'pill-muted',
                'pill' => 'For information',
                'link' => 'Open pay settings',
                'href' => route('records.employee-pay-settings.show', ['employee' => $employee->id]),
            ];
        }

        return $items;
    }

    /**
     * "SSS, PhilHealth and Pag-IBIG"; past `$max` names, "A, B, C and 2 more".
     *
     * @param  array<int, string>  $names
     */
    private static function listed(array $names, int $max = PHP_INT_MAX): string
    {
        $shown = array_slice($names, 0, $max);
        $rest = count($names) - count($shown);
        if ($rest > 0) {
            return implode(', ', $shown).' and '.$rest.' more';
        }
        if (count($shown) <= 1) {
            return $shown[0] ?? '';
        }

        return implode(', ', array_slice($shown, 0, -1)).' and '.$shown[count($shown) - 1];
    }
}
