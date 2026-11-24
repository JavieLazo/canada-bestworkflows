<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Domain\Enums\PayrollRunKind;
use App\Domain\Enums\PayrollRunStatus;
use App\Domain\Notifications\Notifier;
use App\Domain\Payroll\PayslipDraft;
use App\Models\PayrollAdjustment;
use App\Models\PayrollFinalizationSnapshot;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\PayslipDeduction;
use App\Models\PayslipIncentive;
use App\Models\PayslipSettlement;
use App\Modules\Payroll\Application\Adjustments;
use App\Modules\Payroll\Application\AdvanceSettlements;
use App\Modules\Payroll\Application\ContributionMonthToDate;
use App\Modules\Payroll\Application\EnsurePayrollMembership;
use App\Modules\Payroll\Application\LeaveChargeLines;
use App\Modules\Payroll\Application\OpenAttendanceItems;
use App\Modules\Payroll\Application\PayrollData;
use App\Modules\Payroll\Application\PayrollInputs;
use App\Modules\Payroll\Application\PayrollPeriodText;
use App\Modules\Payroll\Application\PayrollReadTransaction;
use App\Modules\Payroll\Application\PayrollReview;
use App\Modules\Payroll\Application\RecurringLines;
use App\Modules\Payroll\Application\ScheduleHold;
use App\Modules\Payroll\Application\SourceDeductionFacts;
use App\Modules\Payroll\Application\TaxYearToDate;
use App\Modules\Payroll\Contracts\Views\DeductionColumnView;
use App\Modules\Payroll\Domain\Adjustments\AdjustmentRule;
use App\Modules\Payroll\Domain\CutoffAdvice;
use App\Modules\Payroll\Domain\PayrollAdvance;
use App\Modules\Timekeeping\Contracts\LeaveCashOutPoster;
use App\Modules\Timekeeping\Contracts\LeaveChargePoster;
use App\Modules\Workforce\Contracts\EmployeePayContexts;
use App\Platform\Audit\RequiredAudit;
use App\Support\Access;
use App\Support\EmployeeName;
use App\Support\LocalClock;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Reviewed inputs, evidence, payslips and mandatory audit must commit together.
 *
 * A regular payroll whose attendance is still open — the period not over,
 * overtime undecided, a request pending (OpenAttendanceItems) — posts as it
 * stands: undecided overtime pays nothing and a pending request is unpaid,
 * and attendance is reviewed before payroll, so nothing asks again here.
 * The items are read from the verified inputs, and the audit sentence and
 * the evidence record them.
 *
 * An ADVANCE PAYROLL (PayrollAdvance, DELIBERATE DIVERGENCE 14) posts each
 * member's own counted date on their payslip — the run's, pulled back before
 * an unfinished day (PayrollAdvance::countedThrough) — so Timekeeping
 * freezes only the counted days, and with one `payslip_settlements` row for each earlier payslip
 * whose presumed days it settles (AdvanceSettlements). Both come from the
 * same verified inputs as the pay.
 *
 * A REGULAR payroll is HELD while a member has a date with no shift inside
 * their employment (ScheduleHold): the engine would judge it by its own
 * default week. The schedule is set in Shift Roster first.
 *
 * What the payroll CHARGES TO LEAVE CREDITS (DELIBERATE DIVERGENCE 15) is
 * posted here too, through Timekeeping's LeaveChargePoster inside the same
 * transaction: a charge that cannot be recorded cancels the finalization,
 * and a day charged twice fails it.
 */
final class FinalizePayroll
{
    public function handle(Access $actor, string $runId, ?string $reviewReceipt = null): int
    {
        if (! $actor->mayReadAllData(ModuleCode::PAYROLL)) {
            throw new RuntimeException('Your role cannot manage organization-wide data.');
        }
        $result = PayrollReadTransaction::run(function () use ($runId, $actor, $reviewReceipt): array {
            PayrollData::lockMembership();

            $run = PayrollRun::query()->with('members.incentives', 'members.deductions')->whereKey($runId)->lockForUpdate()->first();

            if ($run === null) {
                throw new RuntimeException('That payroll no longer exists.');
            }

            if ($run->status === PayrollRunStatus::FINALIZED) {
                throw new RuntimeException('That payroll is already finalized.');
            }

            // Only a regular cut-off is computed and posted. A draft of
            // another kind is never priced as one.
            $kind = $run->kind ?? PayrollRunKind::REGULAR;
            if ($kind !== PayrollRunKind::REGULAR) {
                throw new RuntimeException('That draft is a 13th month payroll, which can no longer be saved or finalized here. Delete it from the Payroll list.');
            }

            $memberIds = PayrollData::memberIds($run->members);

            if ($memberIds === []) {
                throw new RuntimeException('Add at least one employee first.');
            }

            // The route's zod re-parse of the stored JSON has no analogue:
            // the overrides live in typed DECIMAL columns, so "invalid
            // draft adjustments" is unrepresentable by construction.
            $overrides = PayrollData::overridesFromMembers($run->members);

            $periodStart = $run->period_start->format('Y-m-d');
            $periodEnd = $run->period_end->format('Y-m-d');

            // The dates, then the Contribution & Tax Tables in force over
            // the period.
            $cutoffError = PayrollData::cutoffError($actor, $memberIds, $periodStart, $periodEnd);

            if ($cutoffError !== null) {
                throw new RuntimeException($cutoffError);
            }

            EnsurePayrollMembership::check($actor, $memberIds, $periodStart, $periodEnd, $run->id, $kind);

            // An adjustment still awaiting approval for a member holds the
            // payroll: it is reviewed or discarded first, never paid or
            // skipped quietly.
            $pending = Adjustments::pendingFor($memberIds, $run->id);
            if ($pending !== []) {
                $names = [];
                foreach (app(EmployeePayContexts::class)->get($actor, array_keys($pending)) as $employee) {
                    $names[] = EmployeeName::display($employee);
                }
                sort($names, SORT_STRING);
                throw new RuntimeException('An adjustment is awaiting approval for '.implode(', ', $names).'. Review or discard it on Adjustments before finalizing.');
            }

            // A date with no shift inside a member's employment holds
            // the payroll (ScheduleHold): it is judged by the engine's
            // default week, which is nobody's schedule. Read under the
            // membership lock, the lock every schedule change takes first.
            $members = app(EmployeePayContexts::class)->get($actor, $memberIds);
            $noShift = ScheduleHold::noShiftDays($actor, $members, $periodStart, $periodEnd);
            if ($noShift !== []) {
                throw new RuntimeException(ScheduleHold::message(ScheduleHold::names($members, $noShift)));
            }

            // The advance payroll's counted date (PayrollAdvance), checked
            // again here: the day must still be over.
            $advance = AdvanceSettlements::ready();
            $attendanceThrough = $advance
                ? PayrollAdvance::normalize($run->attendance_through?->format('Y-m-d'), $periodEnd)
                : null;
            $advanceError = PayrollAdvance::error($attendanceThrough, $periodStart, $periodEnd, LocalClock::todayKey());
            if ($advanceError !== null) {
                throw new RuntimeException($advanceError);
            }

            // THE computation — the same domain path the draft grid renders.
            $calculation = PayrollData::calculateDrafts(
                $memberIds,
                $periodStart,
                $periodEnd,
                $overrides['deductions'],
                $overrides['allowances'],
                $overrides['earnings'],
                strict: true,
                actor: $actor,
                draftIncentives: $overrides['incentives'],
                draftCustomDeductions: $overrides['customDeductions'],
                draftSkippedRecurring: $overrides['skippedRecurring'],
                attendanceThrough: $attendanceThrough,
                payDate: $run->pay_date?->format('Y-m-d'),
                contributionMonth: $run->contribution_month,
                runId: $run->id,
            );
            $incentiveLines = $calculation['incentiveLines'];
            $customDeductionLines = $calculation['customDeductionLines'];
            $settlementLines = $calculation['settlementLines'];
            $leaveChargeLines = $calculation['leaveChargeLines'];
            $presumedLines = $calculation['presumedLines'];
            $countedThrough = $calculation['countedThrough'];

            $inputs = $calculation['inputs'];
            if ($inputs === null) {
                throw new RuntimeException('Payroll could not capture its calculation inputs.');
            }
            PayrollReview::verify($actor, $run->id, $run->name, $inputs, $reviewReceipt);

            if ($calculation['combined'] !== []) {
                $issue = $calculation['combined'][0];
                $employee = $calculation['employees']->firstWhere('id', $issue['employee_id']);
                $who = $employee !== null ? PayrollData::employeeName($employee) : 'An employee';

                throw new RuntimeException("{$who}: {$issue['message']}");
            }

            // What attendance has not finished deciding, from the same
            // verified inputs, for the record. It never holds the posting.
            $open = OpenAttendanceItems::forInputs($inputs, LocalClock::todayKey());

            // Evidence, payslips, status and the required audit commit as one unit.
            $schema = PayrollInputs::SCHEMA_VERSION;
            $payload = PayrollInputs::json([
                'schema_version' => $schema,
                'kind' => $kind,
                'run' => ['id' => $run->id, 'code' => $run->code, 'name' => $run->name],
                'actor' => ['id' => $actor->userId, 'name' => $actor->fullName],
                'recorded_at' => CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s.v\Z'),
                'inputs' => $inputs->toArray(),
                'results' => array_map(static fn (PayslipDraft $draft): array => $draft->toArray(), $calculation['drafts']),
                // Per employee: what the engine computed for each statutory
                // line, which lines the officer typed over it, and whether the
                // tax was re-derived from typed shares. Derived from the
                // inputs, so it sits beside the results and never enters the
                // input fingerprint.
                'statutory' => $calculation['statutory'],
                // Each posted incentive line with the part of it over the
                // PHP 90,000 ceiling — what payslip_incentives itemises.
                'incentives' => $incentiveLines,
                'custom_deductions' => $customDeductionLines,
                // The Deductions columns as the Payroll Deductions list stood
                // at finalization: which row named each computed line, and
                // which typed rows existed. Evidence beside the results; the
                // screens read the list, never this.
                'deduction_columns' => PayrollData::deductionColumns($actor)->map(static fn (DeductionColumnView $c): array => $c->toArray())->values()->all(),
                // What attendance had not finished deciding when this posted
                // (OpenAttendanceItems): nothing, or what the officer
                // accepted paying as it stood.
                'open_items' => $open->toArray(),
                // What each member's payroll charged to leave credits
                // (DIVERGENCE 15), the plan the payslips were priced with.
                'leave_charges' => $leaveChargeLines,
                // On an advance payroll, each member's presumed days priced
                // on their own by this very engine (DIVERGENCE 14): the
                // frozen figure the settling payroll reads (AdvanceSettlements),
                // so a later engine never re-prices what was paid.
                'presumed' => $presumedLines,
            ]);
            $snapshot = new PayrollFinalizationSnapshot;
            $snapshot->run_id = $run->id;
            $snapshot->schema_version = $schema;
            $snapshot->calculator_version = $inputs->calculatorVersion;
            $snapshot->input_hash = $inputs->fingerprint();
            $snapshot->payload_hash = hash('sha256', $payload);
            $snapshot->payload = $payload;
            $snapshot->finalized_by = (int) $actor->userId;
            try {
                $saved = $snapshot->save();
            } catch (QueryException $e) {
                // Database exceptions include bindings: never send the raw evidence to logs or the UI.
                Log::error('Payroll evidence storage failed.', ['exception_type' => $e::class, 'sqlstate' => $e->errorInfo[0] ?? null]);
                throw new RuntimeException('Payroll could not save its finalization evidence. Nothing was finalized.');
            }
            if (! $saved) {
                throw new RuntimeException('Payroll could not save its finalization evidence. Nothing was finalized.');
            }

            Payslip::query()->where('run_id', $run->id)->delete();
            // Each posted line names the recurring line it paid: the ledger
            // a deduction's total is paid off against (RecurringLineLedger).
            $ledger = RecurringLines::ready();
            $charging = LeaveChargeLines::ready();
            $adjusting = Adjustments::ready();
            $chargedDays = 0.0;
            $chargedEmployees = 0;
            $cashedOut = 0;
            $adjusted = 0;

            foreach ($calculation['drafts'] as $draft) {
                $payslip = new Payslip;
                $columns = array_merge($draft->toArray(), [
                    'run_id' => $run->id,
                    // These columns stay pinned to 0 — pay is additive, so
                    // their reductions live inside basic_pay.
                    'late_undertime_deduction' => 0,
                    'absence_deduction' => 0,
                    'unpaid_leave_deduction' => 0,
                    // The member's own counted date — the run's, pulled back
                    // before an unfinished day (PayrollAdvance::countedThrough)
                    // — on the payslip, so the paid-day freeze and the
                    // settlement read the payslip alone.
                    'attendance_through' => $countedThrough[$draft->employee_id] ?? $attendanceThrough,
                ]);
                if (! $advance) {
                    // The window before the cron migrates 2026_10_31_0001:
                    // nothing was presumed or settled, and the columns do
                    // not exist yet.
                    unset($columns['attendance_through'], $columns['presumed_days'], $columns['settlement']);
                }
                if (! $charging) {
                    // The window before the cron migrates 2026_10_31_0006.
                    unset($columns['leave_charged_days']);
                }
                if (ContributionMonthToDate::ready()) {
                    // The month this payslip's shares count toward (divergence 19).
                    $columns['contribution_month'] = $run->contribution_month ?? substr((string) $run->period_start->format('Y-m-d'), 0, 7);
                } else {
                    // The window before the cron migrates 2026_11_02_0009.
                    unset($columns['contribution_base']);
                }
                if (TaxYearToDate::ready()) {
                    // The tax year the withholding counts in: the year of the pay date (divergence 20).
                    $columns['tax_year'] = TaxYearToDate::yearOf($run->pay_date?->format('Y-m-d'), (string) $run->period_end->format('Y-m-d'));
                } else {
                    // The window before the cron migrates 2026_11_02_0011.
                    unset($columns['tax_year'], $columns['tax_method'], $columns['tax_refund']);
                }
                if (! SourceDeductionFacts::ready()) {
                    // The window before the cron migrates 2026_11_24_0002.
                    unset(
                        $columns['cpp'], $columns['cpp2'], $columns['ei'], $columns['federal_tax'], $columns['provincial_tax'],
                        $columns['cpp_er'], $columns['cpp2_er'], $columns['ei_er'], $columns['pensionable_earnings'], $columns['insurable_earnings'],
                    );
                }
                $payslip->fill($columns);
                if (! $payslip->save()) {
                    throw new RuntimeException('Payroll could not save every payslip. Nothing was finalized.');
                }

                // What this payslip charged to leave credits (DIVERGENCE 15):
                // the ledger entries, the allocations and one charge row per
                // day, under the leave-credits lock, after payroll's own.
                $plan = $leaveChargeLines[$draft->employee_id] ?? null;
                if ($plan !== null && $plan['lines'] !== []) {
                    app(LeaveChargePoster::class)->post($actor, $draft->employee_id, $payslip->id, $run->code, $plan['lines'], $inputs->leaveCharges[$draft->employee_id]);
                    if ($draft->leave_charged_days > 0) {
                        $chargedDays += $draft->leave_charged_days;
                        $chargedEmployees++;
                    }
                }

                // The settlements this payslip makes (AdvanceSettlements), one
                // immutable row each, naming the payslip whose days it settled.
                foreach ($settlementLines[$draft->employee_id] ?? [] as $line) {
                    PayslipSettlement::create([
                        'payslip_id' => $payslip->id,
                        'settled_payslip_id' => $line['payslip_id'],
                        'employee_id' => $draft->employee_id,
                        'settled_code' => $line['run_code'],
                        'from_date' => $line['from'],
                        'to_date' => $line['to'],
                        'presumed_amount' => $line['presumed_amount'],
                        'actual_amount' => $line['actual_amount'],
                        'amount' => $line['amount'],
                        'details' => ['presumed' => $line['presumed'], 'actual' => $line['actual']],
                    ]);
                }

                // The incentive lines it paid, itemised and COPIED (code,
                // name, flag): the catalog can change, the payslip cannot.
                foreach ($incentiveLines[$draft->employee_id] ?? [] as $position => $line) {
                    PayslipIncentive::create([
                        'payslip_id' => $payslip->id,
                        'position' => $position,
                        'type_code' => $line['code'],
                        'name' => $line['name'],
                        'is_taxable' => $line['is_taxable'],
                        'amount' => $line['amount'],
                        'over_ceiling' => $line['over_ceiling'],
                        'tax' => $line['tax'],
                        'counts_toward_13th' => $line['counts_toward_13th'],
                        ...($ledger ? ['recurring_line_id' => $line['recurring_line_id'] ?? null] : []),
                        ...($adjusting ? ['adjustment_id' => $line['adjustment_id'] ?? null] : []),
                    ]);
                }
                // The leave credit cash-outs this payslip paid: each request
                // marked PAID and the days taken off the ledger, through
                // Timekeeping's LeaveCashOutPoster, inside this transaction.
                $cashOuts = [];
                foreach ($incentiveLines[$draft->employee_id] ?? [] as $line) {
                    if (isset($line['cash_out'])) {
                        $cashOuts[] = $line['cash_out'];
                    }
                }
                if ($cashOuts !== []) {
                    app(LeaveCashOutPoster::class)->post($actor, $draft->employee_id, $payslip->id, $run->code, $cashOuts);
                    $cashedOut += count($cashOuts);
                }
                foreach ($customDeductionLines[$draft->employee_id] ?? [] as $position => $line) {
                    PayslipDeduction::create([
                        'payslip_id' => $payslip->id,
                        'position' => $position,
                        'deduction_code' => $line['code'],
                        'name' => $line['name'],
                        'amount' => $line['amount'],
                        'employer_amount' => $line['employer_amount'],
                        ...($ledger ? ['recurring_line_id' => $line['recurring_line_id'] ?? null] : []),
                        ...($adjusting ? ['adjustment_id' => $line['adjustment_id'] ?? null] : []),
                    ]);
                }
                // The adjustments this payslip paid are POSTED, naming it:
                // every captured line must still be approved and unposted,
                // or the evidence no longer matches the ledger.
                $adjustmentIds = array_values(array_filter(array_map(
                    static fn (array $line): ?string => $line['adjustment_id'] ?? null,
                    [...($incentiveLines[$draft->employee_id] ?? []), ...($customDeductionLines[$draft->employee_id] ?? [])],
                )));
                if ($adjustmentIds !== []) {
                    $posted = PayrollAdjustment::query()->whereIn('id', $adjustmentIds)->where('status', AdjustmentRule::APPROVED)->whereNull('payslip_id')
                        ->update(['status' => AdjustmentRule::POSTED, 'payslip_id' => $payslip->id, 'updated_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.v')]);
                    if ($posted !== count($adjustmentIds)) {
                        throw new RuntimeException('An adjustment changed before finalization completed. Review the payroll again.');
                    }
                    $adjusted += $posted;
                }
            }

            $flipped = PayrollRun::query()
                ->whereKey($run->id)
                ->where('status', PayrollRunStatus::DRAFT)
                ->update([
                    'status' => PayrollRunStatus::FINALIZED,
                    // UTC at the DATETIME(3) precision the column declares.
                    'finalized_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.v'),
                    // Actor column: from the session-derived Access, never the request.
                    'finalized_by' => (int) $actor->userId,
                ]);

            if ($flipped === 0) {
                throw new RuntimeException('That payroll changed before finalization completed.');
            }
            $typed = array_sum(array_map(static fn (array $line): int => count($line['overridden']), $calculation['statutory']));
            // What this payroll paid ahead of attendance, and what it settled.
            $presumedDays = (int) array_sum(array_map(static fn (PayslipDraft $draft): float => $draft->presumed_days, $calculation['drafts']));
            $settledEmployees = count($settlementLines);
            RequiredAudit::record($actor, AuditEvent::PAYROLL_FINALIZED, 'payroll_runs', $run->id,
                "{$run->name} finalized — ".count($calculation['drafts']).' payslips'
                .match ($typed) {
                    0 => '',
                    1 => '; 1 statutory line typed over the computed figure',
                    default => "; {$typed} statutory lines typed over the computed figure",
                }
                .(CutoffAdvice::warning($periodStart, $periodEnd) !== null ? '; non-standard cut-off' : '')
                .($cashedOut === 0 ? '' : "; {$cashedOut} leave credit cash-out".($cashedOut === 1 ? '' : 's').' paid')
                .($adjusted === 0 ? '' : "; {$adjusted} adjustment".($adjusted === 1 ? '' : 's').' paid')
                .($attendanceThrough === null ? '' : '; attendance counted through '.PayrollPeriodText::day($attendanceThrough).', '.$presumedDays.($presumedDays === 1 ? ' day' : ' days').' paid as scheduled')
                .($settledEmployees === 0 ? '' : '; settles earlier presumed days for '.$settledEmployees.($settledEmployees === 1 ? ' employee' : ' employees'))
                .($chargedEmployees === 0 ? '' : '; '.number_format($chargedDays, 4, '.', '').' days of leave credits charged for '.$chargedEmployees.($chargedEmployees === 1 ? ' employee' : ' employees'))
                .($open->isEmpty() ? '' : '; finalized with open attendance: '.$open->summary()));

            return [
                'run' => $run,
                'drafts' => $calculation['drafts'],
                'periodStart' => $periodStart,
                'periodEnd' => $periodEnd,
            ];
        });

        $run = $result['run'];
        $drafts = $result['drafts'];

        // One fan-out for the whole run — every active login of every member,
        // one account query and ONE bell ring — rather than a ring (an outbound
        // HTTPS call when realtime is on) per employee.
        DB::afterCommit(static fn () => Notifier::employees(
            array_map(static fn (PayslipDraft $draft): string => $draft->employee_id, $drafts),
            [],
            'PAYSLIP_POSTED',
            "Payroll {$run->code} payslip posted",
            "{$run->name} · {$result['periodStart']} to {$result['periodEnd']}",
            '/portal?tab=payslips',
        ));

        return count($drafts);
    }
}
