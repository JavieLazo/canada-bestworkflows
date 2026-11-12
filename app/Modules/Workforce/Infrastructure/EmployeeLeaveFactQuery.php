<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Infrastructure;

use App\Domain\Payroll\PayrollPeriod;
use App\Models\EmployeeEmploymentHistory;
use App\Models\EmployeeProfile;
use App\Models\EmploymentStatusOption;
use App\Modules\Organization\Contracts\OrganizationReferences;
use App\Modules\Workforce\Application\EmployeePlacements;
use App\Modules\Workforce\Contracts\EmployeeLeaveFacts;
use App\Support\EmployeeName;
use Illuminate\Support\Facades\Schema;

/**
 * Answers leave from Workforce's own persistence: employment dates, the
 * active flag, the employment status by name and Employment list id, the
 * sex, the status over time from Employment History, a signatory's name and
 * position, and the Application for Leave's applicant lines — nothing else.
 */
final class EmployeeLeaveFactQuery implements EmployeeLeaveFacts
{
    public function forPosting(?array $ids = null): array
    {
        $statusIds = $this->statusIds();
        $out = [];
        $rows = EmployeeProfile::query()
            ->when($ids !== null, static fn ($q) => $q->whereIn('id', $ids))
            ->orderBy('id')
            ->get(['id', 'date_hired', 'date_resigned', 'is_active', 'employment_status', 'sex']);
        foreach ($rows as $row) {
            $status = trim((string) $row->employment_status);
            $sex = trim((string) $row->sex);
            $out[(string) $row->id] = [
                'id' => (string) $row->id,
                'date_hired' => PayrollPeriod::dateKeyFromDate($row->date_hired),
                'date_resigned' => $row->date_resigned === null ? null : PayrollPeriod::dateKeyFromDate($row->date_resigned),
                'is_active' => (bool) $row->is_active,
                'employment_status' => $status === '' ? null : $status,
                'employment_status_id' => $statusIds[mb_strtolower($status)] ?? null,
                'sex' => $sex === '' ? null : $sex,
            ];
        }

        return $out;
    }

    public function statusTimelines(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $statusIds = $this->statusIds();
        $idOf = static fn (?string $name): ?string => $statusIds[mb_strtolower(trim((string) $name))] ?? null;
        $history = [];
        if (Schema::hasTable('employee_employment_histories')) {
            $rows = EmployeeEmploymentHistory::query()->whereIn('employee_id', $ids)
                ->orderBy('employee_id')->orderBy('effective_date')->orderBy('id')
                ->get(['employee_id', 'effective_date', 'changed_fields', 'employment_status']);
            foreach ($rows as $row) {
                // The first row, then only the rows that changed the status:
                // a salary change dated back copies the status it was
                // recorded under onto an earlier day, which says nothing
                // about when that status began.
                $employeeId = (string) $row->employee_id;
                if (isset($history[$employeeId]) && ! in_array('employment_status', explode(',', (string) $row->changed_fields), true)) {
                    continue;
                }
                $history[$employeeId][] = ['from' => $row->effective_date->format('Y-m-d'), 'name' => $row->employment_status];
            }
        }
        $out = [];
        $profiles = EmployeeProfile::query()->whereIn('id', $ids)->get(['id', 'date_hired', 'employment_status']);
        foreach ($profiles as $profile) {
            $id = (string) $profile->id;
            $hired = PayrollPeriod::dateKeyFromDate($profile->date_hired);
            $timeline = [];
            $lastName = null;
            $lastFrom = $hired;
            foreach ($history[$id] ?? [] as $i => $entry) {
                $name = mb_strtolower(trim((string) $entry['name']));
                // The first row stands from the date hired: a baseline row's
                // date says when tracking began, not when its status did.
                $from = $i === 0 ? $hired : max($hired, $entry['from']);
                $lastFrom = $from;
                if ($name === $lastName) {
                    continue;
                }
                $lastName = $name;
                $timeline[] = ['from' => $from, 'status_id' => $idOf($entry['name'])];
            }
            $current = mb_strtolower(trim((string) $profile->employment_status));
            if ($timeline === []) {
                $timeline[] = ['from' => $hired, 'status_id' => $idOf($profile->employment_status)];
            } elseif ($current !== $lastName) {
                $timeline[] = ['from' => $lastFrom, 'status_id' => $idOf($profile->employment_status)];
            }
            $out[$id] = $timeline;
        }

        return $out;
    }

    public function signatories(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return EmployeeProfile::query()->whereKey(array_values(array_unique($ids)))
            ->get(['id', 'first_name', 'middle_name', 'last_name', 'suffix', 'position', 'is_active'])
            ->mapWithKeys(static fn (EmployeeProfile $row): array => [(string) $row->id => [
                'name' => EmployeeName::full($row, ''),
                'position' => trim((string) $row->position),
                'is_active' => (bool) $row->is_active,
            ]])
            ->all();
    }

    public function applicant(string $employeeId): ?array
    {
        $row = EmployeeProfile::query()->whereKey($employeeId)->first();
        if ($row === null) {
            return null;
        }
        $placement = EmployeePlacements::of(collect([$row]))[$employeeId] ?? [];

        return [
            'first_name' => (string) $row->first_name,
            'middle_name' => $row->middle_name,
            'last_name' => (string) $row->last_name,
            'suffix' => $row->suffix,
            'position' => $row->position,
            'department_name' => $placement['department_name'] ?? null,
            'unit_name' => $placement['unit_name'] ?? null,
            'monthly_rate' => (float) $row->monthly_rate,
            'daily_rate' => (float) $row->daily_rate,
            'head' => app(OrganizationReferences::class)->officeHead($placement['unit_id'] ?? null, $placement['department_id'] ?? null),
        ];
    }

    /**
     * The Employment list by name, as payroll's status rules read it: case
     * and surrounding spaces aside.
     *
     * @return array<string, string> lower-cased name => id
     */
    private function statusIds(): array
    {
        $out = [];
        foreach (EmploymentStatusOption::query()->get(['id', 'name']) as $option) {
            $out[mb_strtolower(trim((string) $option->name))] = (string) $option->id;
        }

        return $out;
    }
}
