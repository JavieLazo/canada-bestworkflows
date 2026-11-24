<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Models\EmployeeProfile;
use App\Models\EmployeeSalaryRate;
use App\Modules\Workforce\Application\EmploymentHistoryRecorder;
use App\Modules\Workforce\Application\SalaryRates;
use App\Platform\Records\AccountEmployeeLinks;
use App\Support\Access;
use App\Support\AuditService;
use App\Support\EmployeeName;
use App\Support\LocalClock;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Approves or rejects a PAST-DATED salary change recorded as PENDING
 * (RecordSalaryChange): any all-data EMPLOYEES or PAYROLL holder, the
 * recorder and the employee themselves included — one administrator must
 * be able to run the whole flow alone. Approval
 * makes the version the record's rates at once (its date has passed),
 * writes the change into Employment History on its effective date and
 * leaves every finalized payslip as it was paid; a rejection keeps the
 * row with the note. Audited with AuditService, without the amounts.
 */
final class ReviewSalaryChange
{
    public const string APPROVE = 'APPROVE';

    public const string REJECT = 'REJECT';

    public function handle(Access $access, string $id, string $decision, ?string $note = null): string
    {
        if (! $access->mayReadAllData(ModuleCode::EMPLOYEES) && ! $access->mayReadAllData(ModuleCode::PAYROLL)) {
            throw new RuntimeException('Your role cannot review a salary change.');
        }
        if (! in_array($decision, [self::APPROVE, self::REJECT], true)) {
            throw new RuntimeException('Choose whether to approve or reject the salary change.');
        }
        $note = trim((string) $note);
        if (mb_strlen($note) > 500) {
            throw new RuntimeException('Keep the note to 500 characters.');
        }
        if (! SalaryRates::ready()) {
            throw new RuntimeException('Salary changes can be reviewed once this installation\'s upgrade finishes.');
        }
        $today = LocalClock::todayKey();

        return DB::transaction(static function () use ($access, $id, $decision, $note, $today): string {
            abort_unless(app(AccountEmployeeLinks::class)->isActive((int) $access->userId), 403);
            $hint = EmployeeSalaryRate::query()->whereKey($id)->first(['id', 'employee_id']);
            if ($hint === null) {
                throw new RuntimeException('That salary change no longer exists.');
            }
            $employee = EmployeeProfile::query()->whereKey($hint->employee_id)->lockForUpdate()->first();
            if ($employee === null) {
                throw new RuntimeException('That employee no longer exists.');
            }
            $row = EmployeeSalaryRate::query()->whereKey($id)->lockForUpdate()->first();
            if ($row === null || $row->status !== EmployeeSalaryRate::PENDING) {
                throw new RuntimeException('That salary change was already reviewed.');
            }
            $name = EmployeeName::display($employee);
            $from = $row->effective_from->format('Y-m-d');
            $day = (DateTimeImmutable::createFromFormat('!Y-m-d', $from) ?: new DateTimeImmutable('@0'))->format('d M Y');
            $row->status = $decision === self::APPROVE ? EmployeeSalaryRate::APPROVED : EmployeeSalaryRate::REJECTED;
            $row->review_note = $note === '' ? null : $note;
            $row->approved_by = (int) $access->userId;
            $row->approved_by_name = mb_substr($access->fullName, 0, 200);
            $row->approved_at = CarbonImmutable::now('UTC');
            if ($decision === self::APPROVE) {
                $latest = EmployeeSalaryRate::query()->where('employee_id', $row->employee_id)->where('status', EmployeeSalaryRate::APPROVED)
                    ->lockForUpdate()->orderByDesc('effective_from')->orderByDesc('id')->first();
                if ($latest !== null && $latest->effective_from->format('Y-m-d') >= $from) {
                    throw new RuntimeException('A salary version already takes effect on '.$latest->effective_from->format('d M Y').'. Reject this change and record it from a later date.');
                }
            }
            if (! $row->save()) {
                throw new RuntimeException('The salary change could not be reviewed.');
            }
            if ($decision === self::REJECT) {
                AuditService::record($access, AuditEvent::SALARY_CHANGE_REVIEWED, 'employee_salary_rates', $row->id,
                    "{$name} · salary change from {$day} rejected".($note === '' ? '' : " · {$note}"));

                return "Salary change from {$day} rejected for {$name}.";
            }
            $before = EmploymentHistoryRecorder::snapshot($employee);
            if ($from <= $today) {
                SalaryRates::copy($employee, $row);
            }
            app(EmploymentHistoryRecorder::class)->salaryChanged($access, $employee, $before, $from, $row->monthly_rate, $row->daily_rate);
            AuditService::record($access, AuditEvent::SALARY_CHANGE_REVIEWED, 'employee_salary_rates', $row->id,
                "{$name} · salary change from {$day} approved (recorded by ".($row->prepared_by_name ?? 'an unnamed account').')'.($note === '' ? '' : " · {$note}"));

            return "Salary change from {$day} approved for {$name}.";
        });
    }
}
