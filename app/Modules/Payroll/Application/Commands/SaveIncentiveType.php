<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Models\PayrollIncentiveType;
use App\Modules\Payroll\Application\PayrollData;
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
 * Who: an all-data holder of RECORDS or of PAYROLL, because the payroll
 * officers who pay incentives have no Records access. What: a name
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
     */
    public function handle(Access $actor, ?string $id, string $name, ?string $description, bool $isTaxable, ?array $rule = null): string
    {
        if (! $actor->mayReadAllData(ModuleCode::RECORDS) && ! $actor->mayReadAllData(ModuleCode::PAYROLL)) {
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

        if ($id === null) {
            $created = DB::transaction(static function () use ($name, $description, $isTaxable, $figures): PayrollIncentiveType {
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
                ])->save();

                return $row;
            });

            AuditService::record($actor, AuditEvent::INCENTIVE_TYPE_CREATED, 'payroll_incentive_types', $created->id,
                "Incentive type {$created->code} \"{$name}\" added ({$category}".($benefit->isScheduled() ? ', scheduled' : '').')');

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
        $flagChanged = $row->is_taxable !== $isTaxable;
        $ruleChanged = $before->toArray() !== $benefit->toArray();
        if ($row->name === $name && $row->description === $description && ! $flagChanged && ! $ruleChanged) {
            return 'Nothing changed.';
        }

        $row->fill(['name' => $name, 'description' => $description, 'is_taxable' => $isTaxable, ...$figures])->save();

        $note = '';
        if ($before->tax !== $benefit->tax) {
            $note .= "; now {$category}";
        } elseif ($ruleChanged) {
            $note .= '; rule changed';
        }
        AuditService::record($actor, AuditEvent::INCENTIVE_TYPE_UPDATED, 'payroll_incentive_types', $row->id,
            "Incentive type {$row->code} \"{$name}\" updated{$note}");

        return "{$name} saved.";
    }
}
