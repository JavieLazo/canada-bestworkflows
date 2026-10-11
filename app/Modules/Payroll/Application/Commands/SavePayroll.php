<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Domain\Enums\PayrollRunKind;
use App\Domain\Enums\PayrollRunStatus;
use App\Domain\Payroll\PayrollValidation;
use App\Models\PayrollDeduction;
use App\Models\PayrollIncentiveType;
use App\Models\PayrollRun;
use App\Models\PayrollRunMember;
use App\Modules\Payroll\Application\EnsurePayrollMembership;
use App\Modules\Payroll\Application\PayrollData;
use App\Modules\Payroll\Application\PayrollDraft;
use App\Modules\Payroll\Application\StatutoryTables;
use App\Platform\Audit\RequiredAudit;
use App\Support\Access;
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
        // Which payroll this is: a regular cut-off, or the yearly 13th month
        // run — fixed at creation, checked below on an update.
        $kind = $input->kind;
        if (! in_array($kind, PayrollRunKind::all(), true)) {
            throw new RuntimeException('Choose which payroll this is.');
        }
        $yearly = $kind === PayrollRunKind::THIRTEENTH_MONTH;
        // The cut-off check: the dates, then the Contribution & Tax Tables in
        // force — every table over a regular period, the withholding table
        // on 31 December for the 13th month run.
        $cutoffError = $yearly
            ? (preg_match('/^(\d{4})-01-01$/', $input->start, $year) === 1 && $input->end === $year[1].'-12-31'
                ? app(StatutoryTables::class)->yearlyCutoffError((int) $year[1])
                : 'A 13th month payroll covers 1 January to 31 December of one year.')
            : PayrollData::cutoffError($actor, $input->members, $input->start, $input->end);
        if ($cutoffError !== null) {
            throw new RuntimeException($cutoffError);
        }
        $deductions = $input->deductions;
        $allowances = $input->allowances;
        $earnings = $input->earnings;
        $additional = [];
        if ($yearly) {
            if (array_filter($input->incentives, static fn (array $lines): bool => $lines !== []) !== []) {
                throw new RuntimeException('Incentives are paid on a regular payroll, not on the 13th month run.');
            }
            if (array_filter($input->customDeductions, static fn (array $lines): bool => $lines !== []) !== []) {
                throw new RuntimeException('Custom deductions belong on a regular payroll, not on the 13th month run.');
            }
            // Only a typed withholding applies; nothing else is on the run.
            $deductions = array_filter(array_map(
                static fn (array $lines): array => array_key_exists('withholding_tax', $lines) ? ['withholding_tax' => $lines['withholding_tax']] : [],
                $deductions,
            ));
            $allowances = [];
            $earnings = [];
            $additional = self::additionalBasicPay($input->additionalBasicPay, $input->members);
        }
        // requirePayrollOverrideMembers — structurally always clean here (the
        // active maps are filtered by membership), kept for parity with the route.
        $overrideError = PayrollValidation::overrideMembersError($input->members, $deductions, $allowances, $earnings);
        if ($overrideError !== null) {
            throw new RuntimeException($overrideError);
        }
        $members = $input->members;
        // Named in the audit sentence: which statutory lines an officer typed
        // over the computed figure. Counted from the typed map, never
        // computed here.
        $typed = self::typedStatutoryNote($deductions);
        // Incentive and bonus amounts: members only, every type known, every
        // amount a positive peso figure — refused otherwise.
        $incentives = self::incentives($input->incentives, $input->members);
        $customDeductions = self::customDeductions($input->customDeductions, $input->members);
        $runId = $input->runId;
        $start = $input->start;
        $end = $input->end;
        $kindNote = $yearly ? '; 13th month '.substr($start, 0, 4) : '';
        $result = DB::transaction(function () use ($actor, $runId, $name, $start, $end, $members, $deductions, $allowances, $earnings, $typed, $incentives, $customDeductions, $kind, $additional, $kindNote): array {
            PayrollData::lockMembership();
            EnsurePayrollMembership::check($actor, $members, $start, $end, $runId);
            if ($runId !== null) {
                $existing = PayrollRun::query()->whereKey($runId)->lockForUpdate()->first();
                if ($existing === null) {
                    throw new RuntimeException('That payroll no longer exists.');
                }
                if ($existing->status === PayrollRunStatus::FINALIZED) {
                    throw new RuntimeException('That payroll is finalized and can no longer be edited.');
                }
                if (($existing->kind ?? PayrollRunKind::REGULAR) !== $kind) {
                    throw new RuntimeException('A payroll cannot change between a regular and a 13th month run.');
                }
                if ($kind === PayrollRunKind::THIRTEENTH_MONTH && $existing->period_start->format('Y-m-d') !== $start) {
                    throw new RuntimeException('A 13th month run keeps the year it was created for.');
                }
                $updated = PayrollRun::query()->whereKey($runId)->where('status', PayrollRunStatus::DRAFT)->update(['name' => $name, 'period_start' => $start, 'period_end' => $end]);
                if ($updated === 0) {
                    throw new RuntimeException('That payroll changed before the save completed.');
                }
                $memberIds = $this->writeMembers($runId, $members, $start, $end, $deductions, $allowances, $earnings, $additional);
                $lines = PayrollData::writeMemberIncentives($memberIds, self::knownTypes($incentives));
                $customLines = PayrollData::writeMemberDeductions($memberIds, self::knownCustomTypes($customDeductions));
                RequiredAudit::record($actor, AuditEvent::PAYROLL_RUN_UPDATED, 'payroll_runs', $runId, "{$name} saved{$kindNote}{$typed}".self::incentiveNote($lines).self::customDeductionNote($customLines));

                return ['id' => $runId, 'created' => false];
            }
            // The server assigns the authoritative code (runs/route.ts:47-51).
            $code = PayrollData::nextCode('PR', PayrollRun::query()->pluck('code'));
            $run = new PayrollRun;
            $run->fill(['code' => $code, 'name' => $name, 'period_start' => $start, 'period_end' => $end, 'status' => PayrollRunStatus::DRAFT, 'kind' => $kind])->save();
            $memberIds = $this->writeMembers($run->id, $members, $start, $end, $deductions, $allowances, $earnings, $additional);
            $lines = PayrollData::writeMemberIncentives($memberIds, self::knownTypes($incentives));
            $customLines = PayrollData::writeMemberDeductions($memberIds, self::knownCustomTypes($customDeductions));
            RequiredAudit::record($actor, AuditEvent::PAYROLL_RUN_CREATED, 'payroll_runs', $run->id, "{$name} created as a draft with ".count($members).' employees'.$kindNote.$typed.self::incentiveNote($lines).self::customDeductionNote($customLines));

            return ['id' => $run->id, 'created' => true];
        });

        return $result;
    }

    /**
     * "; 3 statutory lines typed over the computed figure", or '' when the
     * draft overtypes none — so the audit sentence of a draft without
     * overrides carries no such note.
     *
     * @param  array<string, array<string, mixed>>  $deductions
     */
    private static function typedStatutoryNote(array $deductions): string
    {
        $count = 0;
        foreach ($deductions as $lines) {
            $count += count(PayrollData::overriddenStatutoryKeys($lines));
        }

        return match ($count) {
            0 => '',
            1 => '; 1 statutory line typed over the computed figure',
            default => "; {$count} statutory lines typed over the computed figure",
        };
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

        return $incentives;
    }

    /**
     * Positive member amounts only, with the same peso ceiling as other
     * payroll inputs. Any unknown or system-managed row is refused below.
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
                if (! is_numeric($amount) || ! is_finite((float) $amount) || Money::round($amount) <= 0) {
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
     * typed line would both be deducted. Read inside the same save
     * transaction as the lines are inserted.
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
        $rows = PayrollDeduction::query()->whereIn('id', array_keys($ids))->get(['id', 'calculation']);
        if ($rows->count() !== count($ids)) {
            throw new RuntimeException('A deduction type on this payroll no longer exists. Open the payroll again and save.');
        }
        if ($rows->contains(static fn (PayrollDeduction $row): bool => $row->calculation !== null)) {
            throw new RuntimeException('Computed payroll deductions cannot be added twice.');
        }

        return $deductions;
    }

    /**
     * A 13th month run's outside-EMS basic pay, validated: members only, each
     * a finite peso figure from 0 to the money maximum (Money::round). A
     * blank is nothing typed.
     *
     * @param  array<string, mixed>  $additional
     * @param  list<string>  $members
     * @return array<string, float>
     */
    private static function additionalBasicPay(array $additional, array $members): array
    {
        $valid = [];
        foreach ($members as $employeeId) {
            $value = $additional[$employeeId] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            if (! is_numeric($value) || ! is_finite((float) $value) || (float) $value < 0) {
                throw new RuntimeException('Type the basic pay earned outside EMS as a peso figure, or leave it empty.');
            }
            if (Money::round($value) > PayrollData::MONEY_MAX) {
                throw new RuntimeException('A basic pay figure is too large.');
            }
            $valid[$employeeId] = Money::round($value);
        }

        return $valid;
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
     * Re-creates the members, and returns employee id => member id for the
     * lines written under them.
     *
     * @param  list<string>  $memberIds
     * @param  array<string, array<string, mixed>>  $deductions
     * @param  array<string, float>  $allowances
     * @param  array<string, array<string, mixed>>  $earnings
     * @param  array<string, float>  $additional
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
        array $additional = [],
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
                $additional,
            ))->id;
        }

        return $created;
    }
}
