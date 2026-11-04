<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application;

use App\Models\EmployeeEmploymentHistory;
use App\Models\EmployeeProfile;
use App\Models\EmployeeRecordChangeRequest;
use App\Modules\Organization\Contracts\OrganizationReferences;
use App\Support\Access;
use App\Support\ManilaClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;

/**
 * Appends Workforce employment timeline rows beside employee record writes.
 *
 * The current profile remains the source used by operational modules. These
 * rows are HR evidence only: each row copies the Employee Details values after
 * a hire or a later HR edit and never feeds Payroll calculations.
 */
final class EmploymentHistoryRecorder
{
    public const string HIRED = 'HIRED';

    public const string BASELINE = 'BASELINE';

    public const string UPDATED = 'UPDATED';

    public const string RECORD_APPROVED = 'RECORD_APPROVED';

    /** Approval evidence is required in the caller's employee-update transaction. */
    public function approved(Access $actor, EmployeeProfile $employee, EmployeeRecordChangeRequest $request): void
    {
        $this->append($actor, $employee, self::RECORD_APPROVED, ManilaClock::todayKey(), ['record_change'], request: $request);
    }

    /** @var list<string> */
    public const array FIELDS = [
        'position',
        'position_level',
        'employment_status',
        'work_site_id',
        'date_hired',
        'date_regular',
        'date_resigned',
        'is_active',
        'monthly_rate',
        'daily_rate',
    ];

    public function hired(Access $actor, EmployeeProfile $employee): void
    {
        $this->append($actor, $employee, self::HIRED, $employee->date_hired->format('Y-m-d'), self::FIELDS);
    }

    /** @param array<string, mixed> $before */
    public function updated(Access $actor, EmployeeProfile $employee, array $before): void
    {
        $after = self::snapshot($employee);
        $changed = [];
        foreach (self::FIELDS as $field) {
            if (($before[$field] ?? null) !== ($after[$field] ?? null)) {
                $changed[] = $field;
            }
        }

        if ($changed === []) {
            return;
        }

        $this->append($actor, $employee, self::UPDATED, ManilaClock::todayKey(), $changed, $before);
    }

    /**
     * A dated salary change (RecordSalaryChange / ReviewSalaryChange): the
     * rate fields that change, recorded on the change's EFFECTIVE date —
     * today, a day to come, or a past day approved later — with the rates
     * the version carries, whether or not the record already shows them.
     *
     * @param  array<string, mixed>  $before
     */
    public function salaryChanged(Access $actor, EmployeeProfile $employee, array $before, string $effectiveDate, ?float $monthly, ?float $daily): void
    {
        $after = clone $employee;
        $after->setAttribute('monthly_rate', $monthly);
        $after->setAttribute('daily_rate', $daily);
        $changed = [];
        foreach (['monthly_rate', 'daily_rate'] as $field) {
            $value = $after->getAttribute($field);
            $text = is_float($value) ? number_format($value, 2, '.', '') : ($value === null ? null : (string) $value);
            if (($before[$field] ?? null) !== $text) {
                $changed[] = $field;
            }
        }
        if ($changed === []) {
            return;
        }

        $this->append($actor, $after, self::UPDATED, $effectiveDate, $changed, $before);
    }

    /** @return array<string, mixed> */
    public static function snapshot(EmployeeProfile $employee): array
    {
        $values = [];
        foreach (self::FIELDS as $field) {
            $value = $employee->getAttribute($field);
            if ($value instanceof CarbonImmutable) {
                $value = $value->format('Y-m-d');
            } elseif (is_float($value)) {
                $value = number_format($value, 2, '.', '');
            } elseif (is_bool($value)) {
                $value = $value ? '1' : '0';
            } elseif ($value !== null) {
                $value = (string) $value;
            }
            $values[$field] = $value;
        }
        // Capture this BEFORE the profile changes. Resolving a former site from
        // its current name later would rewrite the meaning of the old value.
        $values['work_site_name'] = $employee->work_site_id === null
            ? null
            : app(OrganizationReferences::class)->siteName($employee->work_site_id);

        return $values;
    }

    /** @param list<string> $changed
     * @param  array<string, mixed>|null  $before
     */
    private function append(Access $actor, EmployeeProfile $employee, string $event, string $effectiveDate, array $changed, ?array $before = null, ?EmployeeRecordChangeRequest $request = null): void
    {
        if ($request === null && ! Schema::hasTable('employee_employment_histories')) {
            return;
        }

        $row = new EmployeeEmploymentHistory;
        $row->fill([
            'employee_id' => $employee->id,
            'event_type' => $event,
            'effective_date' => $effectiveDate,
            'changed_fields' => implode(',', $changed),
            'position' => $employee->position,
            'position_level' => $employee->position_level,
            'employment_status' => $employee->employment_status,
            'work_site_id' => $employee->work_site_id,
            'date_hired' => $employee->date_hired->format('Y-m-d'),
            'date_regular' => $employee->date_regular?->format('Y-m-d'),
            'date_resigned' => $employee->date_resigned?->format('Y-m-d'),
            'is_active' => $employee->getAttribute('is_active') ?? true,
            'monthly_rate' => $employee->monthly_rate,
            'daily_rate' => $employee->daily_rate,
        ]);
        // Releases may arrive before their cron migration. Check afresh so the
        // next write begins keeping names as soon as the column exists.
        if (Schema::hasColumn('employee_employment_histories', 'work_site_name')) {
            $row->work_site_name = $employee->work_site_id === null
                ? null
                : app(OrganizationReferences::class)->siteName($employee->work_site_id);
        }
        if ($before !== null && Schema::hasColumn('employee_employment_histories', 'previous_values')) {
            $row->previous_values = $before;
        }
        $row->recorded_by = $actor->userId === null ? null : (int) $actor->userId;
        $row->recorded_by_name = $actor->fullName;
        if ($request !== null) {
            $row->request_id = $request->id;
            $row->record_changes = $request->changes;
            $row->requested_by = $request->requested_by;
            $row->requested_by_name = $request->requested_by_name;
            $row->requested_at = $request->created_at;
            $row->request_reason = $request->reason;
            $row->review_note = $request->review_note;
        }
        $row->save();
    }
}
