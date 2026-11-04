<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Models\PayrollIncentiveType;
use App\Models\PayrollRecurringLine;
use App\Modules\Payroll\Application\PayrollData;
use App\Modules\Payroll\Application\PayrollSettingsAccess;
use App\Modules\Payroll\Application\RecurringLines;
use App\Modules\Payroll\Domain\Benefits\BenefitRule;
use App\Support\Access;
use App\Support\AuditService;
use App\Support\CodeSequence;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Adds or edits one benefit of the Incentives & Bonuses catalog under
 * Records → Payroll Settings.
 *
 * Who: whoever manages Payroll Settings (PayrollSettingsAccess: all-data
 * PAYROLL), since the page lives under Payroll. What: a name
 * (unique, at most 100 characters), an optional description (at most 500)
 * and the benefit's rule — how the amount is set, how often it is due, who
 * qualifies, the proration, the tax category and whether it counts toward
 * the 13th month (`BenefitRule`). A caller with no rule saves the typed
 * shape: an amount typed per payroll, taxed or under the ceiling as the
 * flag says. The flag is derived from the category and never disagrees
 * with it. A new type's id is the next bare number, minted from a locked
 * scan of the whole table inside the inserting transaction
 * (codes-and-locks guide); the id never changes afterwards.
 *
 * Editing a type re-describes every OPEN payroll that pays it — its preview
 * changes and asks for a fresh review — while posted payslips keep the
 * name, flag and category they copied. Audit is best-effort AuditService,
 * like every other catalog; an unchanged edit writes nothing.
 */
final class SaveIncentiveType
{
    /** The category as the audit line and the CSV name it. */
    public const array TAX_LABELS = [
        BenefitRule::TAX_TAXABLE => 'taxable',
        BenefitRule::TAX_EXEMPT => 'tax-exempt',
        BenefitRule::TAX_DE_MINIMIS => 'de minimis',
        BenefitRule::TAX_NOT_INCOME => 'not income',
    ];

    /**
     * The toast sentence. Every refusal throws a RuntimeException carrying it, and writes nothing.
     *
     * @param  array<string, mixed>|null  $rule  the benefit's figures in BenefitRule shape; null keeps the typed shape the flag describes
     * @param  bool  $paysLeaveCashOut  whether leave credit cash-outs are paid as this type (one type at most; a scheduled benefit cannot)
     */
    public function handle(Access $actor, ?string $id, string $name, ?string $description, bool $isTaxable, ?array $rule = null, bool $paysLeaveCashOut = false): string
    {
        if (! PayrollSettingsAccess::mayManage($actor)) {
            throw new RuntimeException('Your role cannot manage organization-wide data.');
        }

        $name = trim($name);
        if ($name === '') {
            throw new RuntimeException('Name is required.');
        }
        if (mb_strlen($name) > 100) {
            throw new RuntimeException('Keep the name to 100 characters.');
        }
        $description = trim((string) $description);
        if (mb_strlen($description) > 500) {
            throw new RuntimeException('Keep the description to 500 characters.');
        }
        $description = $description === '' ? null : $description;
        if ($rule !== null) {
            $error = BenefitRule::error($rule);
            if ($error !== null) {
                throw new RuntimeException($error);
            }
        }
        $benefit = $rule === null ? BenefitRule::typed($isTaxable) : BenefitRule::fromArray($rule);
        $isTaxable = $benefit->isTaxable();
        // The rule's columns are written only once the catalog carries them.
        $figures = PayrollData::benefitRulesReady() ? $benefit->toArray() : [];
        $category = self::TAX_LABELS[$benefit->tax];
        // The cash-out flag: a type payroll schedules cannot carry it (the
        // cash-out line is priced from the days, never from a rule), and
        // only once the catalog has the column.
        if ($paysLeaveCashOut && $benefit->isScheduled()) {
            throw new RuntimeException('A benefit payroll schedules cannot pay leave credit cash-outs. Use a type whose amount is typed per payroll.');
        }
        $cashOutColumn = PayrollData::cashOutTypeReady();
        if ($paysLeaveCashOut && ! $cashOutColumn) {
            throw new RuntimeException('Leave credit cash-outs can be set up once this installation\'s upgrade finishes.');
        }
        $flagColumns = $cashOutColumn ? ['pays_leave_cash_out' => $paysLeaveCashOut] : [];

        if ($id === null) {
            $created = DB::transaction(static function () use ($name, $description, $isTaxable, $figures, $flagColumns, $paysLeaveCashOut): PayrollIncentiveType {
                // FIRST: a locked scan of every code, then the checks and the
                // insert from it (codes-and-locks guide).
                $codes = DB::table('payroll_incentive_types')->lockForUpdate()->pluck('code')->all();
                if (PayrollIncentiveType::query()->where('name', $name)->exists()) {
                    throw new RuntimeException('An incentive type with that name already exists.');
                }

                $row = new PayrollIncentiveType;
                $row->fill([
                    'code' => CodeSequence::next('INC', $codes),
                    'name' => $name,
                    'description' => $description,
                    'is_taxable' => $isTaxable,
                    ...$figures,
                    ...$flagColumns,
                ])->save();
                // One type pays cash-outs: the flag moves to this one.
                if ($paysLeaveCashOut) {
                    PayrollIncentiveType::query()->whereKeyNot($row->id)->where('pays_leave_cash_out', true)->update(['pays_leave_cash_out' => false]);
                }

                return $row;
            });

            AuditService::record($actor, AuditEvent::INCENTIVE_TYPE_CREATED, 'payroll_incentive_types', $created->id,
                "Incentive type {$created->code} \"{$name}\" added ({$category}".($benefit->isScheduled() ? ', scheduled' : '').($paysLeaveCashOut ? ', pays leave credit cash-outs' : '').')');

            return "{$name} added.";
        }

        $row = PayrollIncentiveType::query()->find($id);
        if ($row === null) {
            throw new RuntimeException('That incentive type no longer exists.');
        }
        if (PayrollIncentiveType::query()->where('name', $name)->whereKeyNot($id)->exists()) {
            throw new RuntimeException('An incentive type with that name already exists.');
        }

        $before = PayrollData::benefitRuleOf($row);
        // A recurring line pays a type payroll does not schedule; scheduling it would pay it twice.
        if (! $before->isScheduled() && $benefit->isScheduled() && RecurringLines::ready() && PayrollRecurringLine::query()->where('incentive_type_id', $id)->exists()) {
            throw new RuntimeException('Employees have recurring lines on this type, so payroll cannot schedule it. End or delete those lines in Employee Pay Settings first.');
        }
        $flagChanged = $row->is_taxable !== $isTaxable;
        $ruleChanged = $before->toArray() !== $benefit->toArray();
        $cashOutChanged = $cashOutColumn && (bool) ($row->pays_leave_cash_out ?? false) !== $paysLeaveCashOut;
        if ($row->name === $name && $row->description === $description && ! $flagChanged && ! $ruleChanged && ! $cashOutChanged) {
            return 'Nothing changed.';
        }

        DB::transaction(static function () use ($row, $name, $description, $isTaxable, $figures, $flagColumns, $paysLeaveCashOut, $id): void {
            $row->fill(['name' => $name, 'description' => $description, 'is_taxable' => $isTaxable, ...$figures, ...$flagColumns])->save();
            // One type pays cash-outs: the flag moves to this one.
            if ($paysLeaveCashOut) {
                PayrollIncentiveType::query()->whereKeyNot($id)->where('pays_leave_cash_out', true)->update(['pays_leave_cash_out' => false]);
            }
        });

        $note = '';
        if ($before->tax !== $benefit->tax) {
            $note .= "; now {$category}";
        } elseif ($ruleChanged) {
            $note .= '; rule changed';
        }
        if ($cashOutChanged) {
            $note .= $paysLeaveCashOut ? '; now pays leave credit cash-outs' : '; no longer pays leave credit cash-outs';
        }
        AuditService::record($actor, AuditEvent::INCENTIVE_TYPE_UPDATED, 'payroll_incentive_types', $row->id,
            "Incentive type {$row->code} \"{$name}\" updated{$note}");

        return "{$name} saved.";
    }
}
