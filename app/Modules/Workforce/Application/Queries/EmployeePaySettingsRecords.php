<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Queries;

use App\Domain\Enums\ModuleCode;
use App\Models\EmployeeProfile;
use App\Models\EmploymentStatusOption;
use App\Modules\Workforce\Application\EmployeePaySettingsInput;
use App\Modules\Workforce\Contracts\Views\EmployeePaySettingsView;
use App\Platform\ReadModels\Snapshot;
use App\Support\Access;
use Illuminate\Support\Collection;

/**
 * The pay settings of every employee, for Records → Payroll Settings →
 * Employee Pay Settings. Pay data (SEC-01): only an all-data PAYROLL holder
 * reads it, so there is no own-row case — a payroll officer sets these for
 * others, and an employee sees what they were paid on their payslip.
 *
 * Only the columns the page needs are read, and the Employment status is
 * matched to its list entry by name the way EmployeePayContextQuery matches
 * it, so the page can name the Pay Basis & Rates a status override applies.
 */
final class EmployeePaySettingsRecords
{
    private const array IDENTITY = ['id', 'employee_code', 'employee_id_no', 'first_name', 'middle_name', 'last_name', 'suffix', 'employment_status', 'date_hired', 'is_active'];

    /** @return Collection<int, EmployeePaySettingsView> by last name, then first */
    public function list(Access $actor): Collection
    {
        abort_unless($actor->mayReadAllData(ModuleCode::PAYROLL), 403);
        $statusIds = self::statusIds();

        return EmployeeProfile::query()
            ->orderBy('last_name')->orderBy('first_name')->orderBy('id')
            ->get(self::columns())
            ->map(static fn (EmployeeProfile $row): EmployeePaySettingsView => self::view($row, $statusIds));
    }

    public function find(Access $actor, string $id): ?EmployeePaySettingsView
    {
        abort_unless($actor->mayReadAllData(ModuleCode::PAYROLL), 403);
        $row = EmployeeProfile::query()->whereKey($id)->first(self::columns());

        return $row === null ? null : self::view($row, self::statusIds());
    }

    /** The rates Employee Details sets: shown on the pay settings, never edited there. */
    private const array RATES = ['monthly_rate', 'daily_rate'];

    /**
     * The sixteen statutory columns the engine reads off the record: the
     * BASELINE an employee with no approved Contributions & tax version is
     * priced by. No screen writes them any more.
     */
    public const array STATUTORY = [
        'allow_sss', 'allow_philhealth', 'allow_pagibig', 'allow_withholding_tax', 'annualize_tax',
        'fixed_tax', 'percent_tax', 'fixed_sss_ee', 'fixed_sss_er', 'fixed_philhealth_ee', 'fixed_philhealth_er',
        'fixed_pagibig_ee', 'fixed_pagibig_er', 'sss_mode', 'philhealth_mode', 'pagibig_mode',
    ];

    /** @return list<string> */
    private static function columns(): array
    {
        return [...self::IDENTITY, ...self::RATES, ...self::STATUTORY, ...array_keys(EmployeePaySettingsInput::DEFAULTS)];
    }

    /** @param array<string, string> $statusIds */
    private static function view(EmployeeProfile $row, array $statusIds): EmployeePaySettingsView
    {
        return Snapshot::of($row, EmployeePaySettingsView::class, [
            'employment_status_id' => $statusIds[mb_strtolower(trim((string) $row->employment_status))] ?? null,
        ]);
    }

    /** @return array<string, string> lower-cased Employment list name => its id */
    private static function statusIds(): array
    {
        $ids = [];
        foreach (EmploymentStatusOption::query()->get(['id', 'name']) as $option) {
            $ids[mb_strtolower(trim((string) $option->name))] = (string) $option->id;
        }

        return $ids;
    }
}
