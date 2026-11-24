<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Domain\Enums\PayrollRunKind;
use App\Domain\Enums\PayrollRunStatus;
use App\Domain\Payroll\PayrollPeriod;
use App\Domain\Payroll\PayrollValidation;
use App\Models\PayrollDeduction;
use App\Models\PayrollIncentiveType;
use App\Models\PayrollPaySchedule;
use App\Models\PayrollRecurringLine;
use App\Models\PayrollRun;
use App\Models\PayrollRunMember;
use App\Modules\Payroll\Application\AdvanceSettlements;
use App\Modules\Payroll\Application\EnsurePayrollMembership;
use App\Modules\Payroll\Application\PayrollData;
use App\Modules\Payroll\Application\PayrollDraft;
use App\Modules\Payroll\Application\PayrollPeriodText;
use App\Modules\Payroll\Application\PaySchedules;
use App\Modules\Payroll\Application\RecurringLines;
use App\Modules\Payroll\Domain\PayrollAdvance;
use App\Platform\Audit\RequiredAudit;
use App\Support\Access;
use App\Support\LocalClock;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Owns draft validation, membership serialization and audit without changing payroll arithmetic. */
final class SavePayroll
{
    /** @return array{id: string, created: bool} */
    public function handle(Access $actor, PayrollDraft $input): array
    {
        if (! $actor->mayReadAllData(ModuleCode::PAYROLL)) {
            throw new RuntimeException('Your role cannot manage organization-wide data.');
        }
        $name = trim($input->name);
        if ($name === '') {
            throw new RuntimeException('Give the payroll a name.');
        }
        // Every payroll saved here is a regular cut-off: the kind is written
        // at creation and checked below on an update.
        $kind = PayrollRunKind::REGULAR;
        // The cut-off check: the dates, then the Pay Basis & Rates in force
        // over the period.
        $cutoffError = PayrollData::cutoffError($actor, $input->members, $input->start, $input->end);
        if ($cutoffError !== null) {
            throw new RuntimeException($cutoffError);
        }
        // The advance payroll's counted date (PayrollAdvance): inside the
        // period and already over; the period's own last day is stored as
        // nothing.
        $attendanceThrough = PayrollAdvance::normalize($input->attendanceThrough, $input->end);
        if ($attendanceThrough !== null) {
            if (! AdvanceSettlements::ready()) {
                throw new RuntimeException('Attendance can be counted through an earlier day once this installation\'s upgrade finishes.');
            }
            $advanceError = PayrollAdvance::error($attendanceThrough, $input->start, $input->end, LocalClock::todayKey());
            if ($advanceError !== null) {
                throw new RuntimeException($advanceError);
            }
        }
        // The pay schedule the run's dates come from and its pay date
        // (PaySchedules): the schedule one that exists, the pay date a real
        // day. A lagged attendance window is not priced yet.
        [$scheduleId, $payDate, $scheduleNote] = self::scheduleDates($input);
        $deductions = $input->deductions;
        $allowances = $input->allowances;
        $earnings = $input->earnings;
        // requirePayrollOverrideMembers — structurally always clean here (the
        // active maps are filtered by membership), kept for parity with the route.
        $overrideError = PayrollValidation::overrideMembersError($input->members, $deductions, $allowances, $earnings);
        if ($overrideError !== null) {
            throw new RuntimeException($overrideError);
        }
        $members = $input->members;
        // Incentive and bonus amounts: members only, every type known, every
        // amount a positive peso figure — refused otherwise.
        $incentives = self::incentives($input->incentives, $input->members);
        $customDeductions = self::customDeductions($input->customDeductions, $input->members);
        // The recurring lines skipped on this payroll: members only, each a line id.
        $skipped = self::skippedRecurring($input->skippedRecurring, $input->members);
        $runId = $input->runId;
        $start = $input->start;
        $end = $input->end;
        // Named in the audit sentence: the days this payroll pays ahead of
        // their attendance.
        $advanceNote = $attendanceThrough === null ? '' : '; attendance counted through '.PayrollPeriodText::day($attendanceThrough);
        $result = DB::transaction(function () use ($actor, $runId, $name, $start, $end, $members, $deductions, $allowances, $earnings, $incentives, $customDeductions, $kind, $skipped, $attendanceThrough, $advanceNote, $scheduleId, $payDate, $scheduleNote): array {
            PayrollData::lockMembership();
            EnsurePayrollMembership::check($actor, $members, $start, $end, $runId, $kind);
            $skipped = self::knownRecurring($skipped);
            if ($runId !== null) {
                $existing = PayrollRun::query()->whereKey($runId)->lockForUpdate()->first();
                if ($existing === null) {
                    throw new RuntimeException('That payroll no longer exists.');
                }
                if ($existing->status === PayrollRunStatus::FINALIZED) {
                    throw new RuntimeException('That payroll is finalized and can no longer be edited.');
                }
                // A draft of another kind is one this system no longer
                // works on: it is never rewritten as a regular cut-off.
                if (($existing->kind ?? PayrollRunKind::REGULAR) !== $kind) {
                    throw new RuntimeException('That draft is a 13th month payroll, which can no longer be saved or finalized here. Delete it from the Payroll list.');
                }
                $updated = PayrollRun::query()->whereKey($runId)->where('status', PayrollRunStatus::DRAFT)->update([
                    'name' => $name, 'period_start' => $start, 'period_end' => $end,
                    ...(AdvanceSettlements::ready() ? ['attendance_through' => $attendanceThrough] : []),
                    ...(PaySchedules::ready() ? ['schedule_id' => $scheduleId, 'pay_date' => $payDate] : []),
                ]);
                if ($updated === 0) {
                    throw new RuntimeException('That payroll changed before the save completed.');
                }
                $memberIds = $this->writeMembers($runId, $members, $start, $end, $deductions, $allowances, $earnings, $skipped);
                $lines = PayrollData::writeMemberIncentives($memberIds, self::knownTypes($incentives));
                $customLines = PayrollData::writeMemberDeductions($memberIds, self::knownCustomTypes($customDeductions));
                RequiredAudit::record($actor, AuditEvent::PAYROLL_RUN_UPDATED, 'payroll_runs', $runId, "{$name} saved{$advanceNote}{$scheduleNote}".self::incentiveNote($lines).self::customDeductionNote($customLines).self::skipNote($skipped));

                return ['id' => $runId, 'created' => false];
            }
            // The server assigns the authoritative code (runs/route.ts:47-51).
            $code = PayrollData::nextCode('PR', PayrollRun::query()->pluck('code'));
            $run = new PayrollRun;
            $run->fill([
                'code' => $code, 'name' => $name, 'period_start' => $start, 'period_end' => $end, 'status' => PayrollRunStatus::DRAFT, 'kind' => $kind,
                ...(AdvanceSettlements::ready() ? ['attendance_through' => $attendanceThrough] : []),
            ]);
            if (PaySchedules::ready()) {
                $run->forceFill(['schedule_id' => $scheduleId, 'pay_date' => $payDate]);
            }
            $run->save();
            $memberIds = $this->writeMembers($run->id, $members, $start, $end, $deductions, $allowances, $earnings, $skipped);
            $lines = PayrollData::writeMemberIncentives($memberIds, self::knownTypes($incentives));
            $customLines = PayrollData::writeMemberDeductions($memberIds, self::knownCustomTypes($customDeductions));
            RequiredAudit::record($actor, AuditEvent::PAYROLL_RUN_CREATED, 'payroll_runs', $run->id, "{$name} created as a draft with ".count($members).' employees'.$advanceNote.$scheduleNote.self::incentiveNote($lines).self::customDeductionNote($customLines).self::skipNote($skipped));

            return ['id' => $run->id, 'created' => true];
        });

        return $result;
    }

    /**
     * The run's schedule dates, validated: the schedule (blank = none) and
     * the pay date (blank = the one the schedule gives the period, when the
     * dates are one of its periods; a real day), with the audit note.
     * Before the columns exist, nothing. `payroll_runs.contribution_month`
     * is not written.
     *
     * @return array{0: ?string, 1: ?string, 2: string}
     */
    private static function scheduleDates(PayrollDraft $input): array
    {
        $scheduleId = trim((string) $input->scheduleId) === '' ? null : trim((string) $input->scheduleId);
        $payDate = trim((string) $input->payDate) === '' ? null : trim((string) $input->payDate);
        if (! PaySchedules::ready()) {
            if ($scheduleId !== null || $payDate !== null) {
                throw new RuntimeException('Pay schedules can be used once this installation\'s upgrade finishes.');
            }

            return [null, null, ''];
        }
        if ($payDate !== null && ! PayrollPeriod::isPayrollDate($payDate)) {
            throw new RuntimeException('Enter the pay date as a real day, or leave it blank.');
        }
        $note = '';
        if ($scheduleId !== null) {
            $schedule = PayrollPaySchedule::query()->whereKey($scheduleId)->first();
            if ($schedule === null) {
                throw new RuntimeException('That pay schedule no longer exists.');
            }
            if (PaySchedules::schedule($schedule)->attendanceLag > 0) {
                throw new RuntimeException('A schedule whose attendance window ends before the salary period cannot make a payroll yet.');
            }
            $period = PaySchedules::periodFor($schedule, $input->start, $input->end);
            $payDate ??= $period['pay_date'] ?? null;
            $note = '; on the '.$schedule->name.' schedule';
        }
        if ($payDate !== null) {
            $note .= '; paid '.PayrollPeriodText::day($payDate);
        }

        return [$scheduleId, $payDate, $note];
    }

    /**
     * The draft's incentive amounts, validated: members only, and every
     * amount a finite peso figure above zero and within the money maximum
     * (Money::round). An absent or empty employee entry is no incentive.
     *
     * @param  array<string, array<string, mixed>>  $incentives
     * @param  list<string>  $members
     * @return array<string, array<string, float>>
     */
    private static function incentives(array $incentives, array $members): array
    {
        $valid = [];
        foreach ($members as $employeeId) {
            foreach ($incentives[$employeeId] ?? [] as $typeId => $amount) {
                if (! is_numeric($amount) || ! is_finite((float) $amount) || Money::round($amount) <= 0) {
                    throw new RuntimeException('Every incentive amount must be more than zero.');
                }
                if (Money::round($amount) > PayrollData::MONEY_MAX) {
                    throw new RuntimeException('An incentive amount is too large.');
                }
                $valid[$employeeId][(string) $typeId] = Money::round($amount);
            }
        }

        return $valid;
    }

    /**
     * The same map, refused if any type is gone — read inside the save's
     * transaction, just before the lines are written (the type_id RESTRICT
     * is the backstop against a delete in between).
     *
     * @param  array<string, array<string, float>>  $incentives
     * @return array<string, array<string, float>>
     */
    private static function knownTypes(array $incentives): array
    {
        $typeIds = [];
        foreach ($incentives as $lines) {
            foreach (array_keys($lines) as $typeId) {
                $typeIds[(string) $typeId] = true;
            }
        }
        if ($typeIds !== [] && PayrollIncentiveType::query()->whereIn('id', array_keys($typeIds))->count() !== count($typeIds)) {
            throw new RuntimeException('An incentive type on this payroll no longer exists. Choose the incentives again.');
        }
        // The type that pays leave credit cash-outs is priced from the
        // approved requests, never typed.
        if ($typeIds !== [] && PayrollData::cashOutTypeReady()) {
            $cashOut = PayrollIncentiveType::query()->whereIn('id', array_keys($typeIds))->where('pays_leave_cash_out', true)->value('name');
            if ($cashOut !== null) {
                throw new RuntimeException("{$cashOut} pays leave credit cash conversions and cannot be typed on a payroll.");
            }
        }

        return $incentives;
    }

    /**
     * Member amounts of zero or more, with the same peso ceiling as other
     * payroll inputs. A zero stands only on a percentage row, and any
     * unknown or system-managed row is refused, below.
     *
     * @param  array<string, array<string, mixed>>  $deductions
     * @param  list<string>  $members
     * @return array<string, array<string, float>>
     */
    private static function customDeductions(array $deductions, array $members): array
    {
        $valid = [];
        foreach ($members as $employeeId) {
            foreach ($deductions[$employeeId] ?? [] as $typeId => $amount) {
                if (! is_numeric($amount) || ! is_finite((float) $amount) || Money::round($amount) < 0) {
                    throw new RuntimeException('Every custom deduction amount must be more than zero.');
                }
                if (Money::round($amount) > PayrollData::MONEY_MAX) {
                    throw new RuntimeException('A custom deduction amount is too large.');
                }
                $valid[$employeeId][(string) $typeId] = Money::round($amount);
            }
        }

        return $valid;
    }

    /**
     * A deleted row cannot become a typed charge, and neither can a row
     * payroll computes itself (its `calculation`): the engine's line and a
     * typed line would both be deducted. A zero stands only on a percentage
     * row, where it means the employee's share is already paid for this
     * cut-off. Read inside the same save transaction as the lines are
     * inserted.
     *
     * @param  array<string, array<string, float>>  $deductions
     * @return array<string, array<string, float>>
     */
    private static function knownCustomTypes(array $deductions): array
    {
        $ids = [];
        foreach ($deductions as $lines) {
            foreach (array_keys($lines) as $id) {
                $ids[(string) $id] = true;
            }
        }
        if ($ids === []) {
            return $deductions;
        }
        $rows = PayrollDeduction::query()->whereIn('id', array_keys($ids))->get();
        foreach ($deductions as $lines) {
            foreach ($lines as $id => $amount) {
                if ($amount <= 0.0 && $rows->firstWhere('id', (string) $id)?->percent === null) {
                    throw new RuntimeException('Every custom deduction amount must be more than zero.');
                }
            }
        }
        if ($rows->count() !== count($ids)) {
            throw new RuntimeException('A deduction type on this payroll no longer exists. Open the payroll again and save.');
        }
        if ($rows->contains(static fn (PayrollDeduction $row): bool => $row->calculation !== null)) {
            throw new RuntimeException('Computed payroll deductions cannot be added twice.');
        }

        return $deductions;
    }

    /** "; 2 incentive lines", or '' — so a draft without any keeps its sentence. */
    private static function incentiveNote(int $lines): string
    {
        return match ($lines) {
            0 => '',
            1 => '; 1 incentive line',
            default => "; {$lines} incentive lines",
        };
    }

    private static function customDeductionNote(int $lines): string
    {
        return match ($lines) {
            0 => '',
            1 => '; 1 custom deduction line',
            default => "; {$lines} custom deduction lines",
        };
    }

    /**
     * The recurring lines skipped on this payroll: members only, each a list
     * of line ids, once each and sorted.
     *
     * @param  array<string, mixed>  $skipped  as the screen sent it
     * @param  list<string>  $members
     * @return array<string, list<string>>
     */
    private static function skippedRecurring(array $skipped, array $members): array
    {
        $valid = [];
        foreach ($members as $employeeId) {
            $ids = $skipped[$employeeId] ?? [];
            if (! is_array($ids)) {
                throw new RuntimeException('A skipped deduction or allowance is not one this payroll knows. Open the payroll again and save.');
            }
            $lineIds = [];
            foreach ($ids as $id) {
                if (! is_string($id) || $id === '') {
                    throw new RuntimeException('A skipped deduction or allowance is not one this payroll knows. Open the payroll again and save.');
                }
                $lineIds[] = $id;
            }
            $lineIds = array_values(array_unique($lineIds));
            sort($lineIds, SORT_STRING);
            if ($lineIds !== []) {
                $valid[$employeeId] = $lineIds;
            }
        }

        return $valid;
    }

    /**
     * Each skipped line must be one of that employee's own recurring lines,
     * read inside the save transaction; a line deleted meanwhile has nothing
     * left to skip and is dropped.
     *
     * @param  array<string, list<string>>  $skipped
     * @return array<string, list<string>>
     */
    private static function knownRecurring(array $skipped): array
    {
        if ($skipped === []) {
            return [];
        }
        if (! RecurringLines::ready()) {
            throw new RuntimeException('Recurring deductions and allowances can be skipped once this installation\'s upgrade finishes.');
        }
        $owners = PayrollRecurringLine::query()->whereIn('id', array_merge(...array_values($skipped)))->pluck('employee_id', 'id');
        $known = [];
        foreach ($skipped as $employeeId => $ids) {
            foreach ($ids as $id) {
                $owner = $owners[$id] ?? null;
                if ($owner !== null && $owner !== $employeeId) {
                    throw new RuntimeException('A skipped deduction or allowance is not one this payroll knows. Open the payroll again and save.');
                }
                if ($owner !== null) {
                    $known[$employeeId][] = $id;
                }
            }
        }

        return $known;
    }

    /**
     * "; 2 recurring lines skipped", or ''.
     *
     * @param  array<string, list<string>>  $skipped
     */
    private static function skipNote(array $skipped): string
    {
        $count = array_sum(array_map('count', $skipped));

        return match ($count) {
            0 => '',
            1 => '; 1 recurring line skipped',
            default => "; {$count} recurring lines skipped",
        };
    }

    /**
     * Re-creates the members, and returns employee id => member id for the
     * lines written under them.
     *
     * @param  list<string>  $memberIds
     * @param  array<string, array<string, mixed>>  $deductions
     * @param  array<string, float>  $allowances
     * @param  array<string, array<string, mixed>>  $earnings
     * @param  array<string, list<string>>  $skipped
     * @return array<string, string>
     */
    private function writeMembers(
        string $runId,
        array $memberIds,
        string $periodStart,
        string $periodEnd,
        array $deductions,
        array $allowances,
        array $earnings,
        array $skipped = [],
    ): array {
        PayrollRunMember::query()->where('run_id', $runId)->delete();

        $created = [];
        foreach ($memberIds as $position => $employeeId) {
            $created[$employeeId] = PayrollRunMember::create(PayrollData::memberColumns(
                $runId,
                $employeeId,
                $position,
                $periodStart,
                $periodEnd,
                $deductions,
                $allowances,
                $earnings,
                $skipped,
            ))->id;
        }

        return $created;
    }
}
