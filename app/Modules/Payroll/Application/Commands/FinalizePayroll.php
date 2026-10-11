<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Domain\Enums\PayrollRunKind;
use App\Domain\Enums\PayrollRunStatus;
use App\Domain\Notifications\Notifier;
use App\Domain\Payroll\PayslipDraft;
use App\Models\PayrollFinalizationSnapshot;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\PayslipDeduction;
use App\Models\PayslipIncentive;
use App\Modules\Payroll\Application\EnsurePayrollMembership;
use App\Modules\Payroll\Application\PayrollData;
use App\Modules\Payroll\Application\PayrollInputs;
use App\Modules\Payroll\Application\PayrollReadTransaction;
use App\Modules\Payroll\Application\PayrollReview;
use App\Modules\Payroll\Application\StatutoryTables;
use App\Modules\Payroll\Application\ThirteenthMonthData;
use App\Modules\Payroll\Application\ThirteenthMonthInputs;
use App\Modules\Payroll\Contracts\Views\DeductionColumnView;
use App\Modules\Payroll\Domain\CutoffAdvice;
use App\Platform\Audit\RequiredAudit;
use App\Support\Access;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/** Reviewed inputs, evidence, payslips and mandatory audit must commit together. */
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

            // The dates, then the Contribution & Tax Tables in force: every
            // table over a regular period, the withholding table on 31
            // December for the 13th month run.
            $cutoffError = ($run->kind ?? PayrollRunKind::REGULAR) === PayrollRunKind::THIRTEENTH_MONTH
                ? app(StatutoryTables::class)->yearlyCutoffError((int) substr($periodStart, 0, 4))
                : PayrollData::cutoffError($actor, $memberIds, $periodStart, $periodEnd);

            if ($cutoffError !== null) {
                throw new RuntimeException($cutoffError);
            }

            EnsurePayrollMembership::check($actor, $memberIds, $periodStart, $periodEnd, $run->id);

            // THE computation — the same domain path the draft grid renders:
            // the cut-off payroll's, or the 13th month run's.
            $kind = $run->kind ?? PayrollRunKind::REGULAR;
            $yearly = $kind === PayrollRunKind::THIRTEENTH_MONTH;
            $incentiveLines = [];
            $customDeductionLines = [];
            if ($yearly) {
                $calculation = ThirteenthMonthData::calculateDrafts(
                    $memberIds,
                    (int) substr($periodStart, 0, 4),
                    $overrides['additional'],
                    array_filter(array_map(static fn (array $lines): mixed => $lines['withholding_tax'] ?? null, $overrides['deductions']), static fn (mixed $tax): bool => $tax !== null),
                    strict: true,
                    actor: $actor,
                );
            } else {
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
                );
                $incentiveLines = $calculation['incentiveLines'];
                $customDeductionLines = $calculation['customDeductionLines'];
            }

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

            // Evidence, payslips, status and the required audit commit as one unit.
            $schema = $yearly ? ThirteenthMonthInputs::SCHEMA_VERSION : PayrollInputs::SCHEMA_VERSION;
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
                'deduction_columns' => $yearly ? [] : PayrollData::deductionColumns($actor)->map(static fn (DeductionColumnView $c): array => $c->toArray())->values()->all(),
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

            foreach ($calculation['drafts'] as $draft) {
                $payslip = new Payslip;
                $payslip->fill(array_merge($draft->toArray(), [
                    'run_id' => $run->id,
                    // These columns stay pinned to 0 — pay is additive, so
                    // their reductions live inside basic_pay.
                    'late_undertime_deduction' => 0,
                    'absence_deduction' => 0,
                    'unpaid_leave_deduction' => 0,
                ]));
                if (! $payslip->save()) {
                    throw new RuntimeException('Payroll could not save every payslip. Nothing was finalized.');
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
                    ]);
                }
                foreach ($customDeductionLines[$draft->employee_id] ?? [] as $position => $line) {
                    PayslipDeduction::create([
                        'payslip_id' => $payslip->id,
                        'position' => $position,
                        'deduction_code' => $line['code'],
                        'name' => $line['name'],
                        'amount' => $line['amount'],
                        'employer_amount' => $line['employer_amount'],
                    ]);
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
            RequiredAudit::record($actor, AuditEvent::PAYROLL_FINALIZED, 'payroll_runs', $run->id,
                "{$run->name} finalized — ".count($calculation['drafts']).($yearly ? ' 13th month payslips' : ' payslips')
                .match ($typed) {
                    0 => '',
                    1 => '; 1 statutory line typed over the computed figure',
                    default => "; {$typed} statutory lines typed over the computed figure",
                }
                .(! $yearly && CutoffAdvice::warning($periodStart, $periodEnd) !== null ? '; non-standard cut-off' : ''));

            return [
                'run' => $run,
                'yearly' => $yearly,
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
            $result['yearly'] ? "13th month pay {$run->code} posted" : "Payroll {$run->code} payslip posted",
            "{$run->name} · {$result['periodStart']} to {$result['periodEnd']}",
            '/portal?tab=payslips',
        ));

        return count($drafts);
    }
}
