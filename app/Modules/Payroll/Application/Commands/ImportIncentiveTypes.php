<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application\Commands;

use App\Models\PayrollIncentiveType;
use App\Modules\Payroll\Application\PayrollData;
use App\Modules\Payroll\Application\PayrollSettingsAccess;
use App\Modules\Payroll\Domain\Benefits\BenefitRule;
use App\Platform\Records\ReferenceCatalogs;
use App\Platform\Transfer\ImportLayout;
use App\Platform\Transfer\ImportOutcome;
use App\Platform\Transfer\ImportRow;
use App\Platform\Transfer\TableImport;
use App\Support\Access;
use RuntimeException;

/**
 * Incentive and bonus types read back from CSV, one row at a time through
 * {@see SaveIncentiveType} — its name rules, its locked id mint, its audit
 * row, and its gate (all-data in RECORDS or in PAYROLL). ID is the id the
 * table shows: kept, the row updates that type; blank, it adds one. The
 * first four columns are the catalog's former file; the rule's columns
 * follow, and one left BLANK keeps what the row holds (a new row: the
 * typed shape). Employment statuses are the list's names, separated by
 * semicolons.
 */
final class ImportIncentiveTypes implements TableImport
{
    /** The columns an import reads — the Incentives & Bonuses export's own. */
    public const array COLUMNS = ['ID', 'Name', 'Tax', 'Description', 'Amount type', 'Amount', 'How often', 'Month', 'Minimum months of service', 'Proration', 'Employment statuses', 'Tax-free up to', 'Approval reference', 'Counts toward 13th'];

    public const array TAX = [
        BenefitRule::TAX_TAXABLE => 'Taxable',
        BenefitRule::TAX_EXEMPT => 'Tax-exempt',
        BenefitRule::TAX_DE_MINIMIS => 'De minimis',
        BenefitRule::TAX_NOT_INCOME => 'Not income',
    ];

    public const array AMOUNT_TYPES = [
        BenefitRule::AMOUNT_FIXED => 'Fixed amount',
        BenefitRule::AMOUNT_PERCENT => 'Percent of monthly salary',
        BenefitRule::AMOUNT_MONTH => "One month's basic salary",
        BenefitRule::AMOUNT_DAILY => 'Amount per day worked',
        BenefitRule::AMOUNT_TYPED => 'Typed per payroll',
    ];

    public const array FREQUENCIES = [
        BenefitRule::FREQUENCY_EVERY => 'Every payroll',
        BenefitRule::FREQUENCY_FIRST => 'Monthly 1st cut-off',
        BenefitRule::FREQUENCY_SECOND => 'Monthly 2nd cut-off',
        BenefitRule::FREQUENCY_YEARLY => 'Yearly',
        BenefitRule::FREQUENCY_ONCE => 'One-time',
    ];

    public const array PRORATIONS = [
        BenefitRule::PRORATION_NONE => 'None',
        BenefitRule::PRORATION_MONTHS => 'By months of service',
        BenefitRule::PRORATION_DAYS => 'By paid days',
    ];

    public function layout(Access $actor, string $context): ImportLayout
    {
        return new ImportLayout('incentive and bonus types', 'ID', self::COLUMNS, [
            'Tax is Taxable; Tax-exempt; De minimis; or Not income — blank on a row with an ID keeps what the row holds.',
            'Amount type is Fixed amount; Percent of monthly salary; One month\'s basic salary; Amount per day worked; or Typed per payroll. Amount is the pesos, or the percent for a percent rule.',
            'How often is Every payroll; Monthly 1st cut-off; Monthly 2nd cut-off; Yearly (with Month 1-12); or One-time.',
            'Employment statuses are names from the Employment list separated by semicolons; blank means everyone.',
            'Every column of the template must be present; a rule cell left blank keeps what the row holds, and a figure that does not fit the rule is refused.',
        ]);
    }

    public function allows(Access $actor, string $context): bool
    {
        return PayrollSettingsAccess::mayManage($actor);
    }

    public function row(Access $actor, string $context, ImportRow $row): ImportOutcome
    {
        abort_unless($this->allows($actor, $context), 403);
        $code = $row->identifier('ID');
        $type = null;
        if ($code !== '') {
            $type = PayrollIncentiveType::query()->where('code', $code)->first();
            if ($type === null) {
                throw new RuntimeException("No incentive type has ID {$code}. Leave ID blank to add a new one.");
            }
        }
        $tax = $row->choice('Tax', self::TAX);
        if ($tax === '' && $type !== null && trim($row->text('Tax')) === '' && PayrollData::benefitRulesReady()) {
            // A row the file identifies keeps its category when the cell is blank.
            $tax = PayrollData::benefitRuleOf($type)->tax;
        }
        if ($tax === '') {
            throw new RuntimeException('Tax is required: Taxable, Tax-exempt, De minimis or Not income.');
        }
        $name = trim($row->text('Name'));
        $description = $row->text('Description');
        $taxable = $tax === BenefitRule::TAX_TAXABLE;
        if (! PayrollData::benefitRulesReady()) {
            if ($type !== null && $type->name === $name && (string) $type->description === $description && $type->is_taxable === $taxable) {
                return ImportOutcome::UNCHANGED;
            }
            app(SaveIncentiveType::class)->handle($actor, $type === null ? null : (string) $type->getKey(), $name, $description, $taxable);

            return $type === null ? ImportOutcome::CREATED : ImportOutcome::UPDATED;
        }

        // The rule: what the row holds, with every column the file fills read over it.
        $current = ($type === null ? BenefitRule::typed($taxable) : PayrollData::benefitRuleOf($type))->toArray();
        $figures = ['tax' => $tax] + $current;
        $given = static fn (string $column): bool => $row->has($column) && trim($row->text($column)) !== '';
        if ($given('Amount type')) {
            $figures['amount_type'] = $row->choice('Amount type', self::AMOUNT_TYPES) ?: $current['amount_type'];
        }
        if ($given('Amount')) {
            // "10%" is a percent of salary; the sign is allowed on a percent rule.
            $figures['amount'] = $figures['amount_type'] === BenefitRule::AMOUNT_PERCENT && str_ends_with(trim($row->text('Amount')), '%')
                ? (float) rtrim(trim($row->text('Amount')), '% ')
                : (float) $row->money('Amount');
        }
        if ($given('How often')) {
            $figures['frequency'] = $row->choice('How often', self::FREQUENCIES) ?: $current['frequency'];
        }
        if ($given('Month')) {
            $figures['month'] = $row->integer('Month');
        }
        if ($given('Minimum months of service')) {
            $figures['min_service_months'] = $row->integer('Minimum months of service') ?? 0;
        }
        if ($given('Proration')) {
            $figures['proration'] = $row->choice('Proration', self::PRORATIONS) ?: $current['proration'];
        }
        if ($given('Employment statuses')) {
            $figures['statuses'] = $this->statusIds($actor, $row->text('Employment statuses'));
        }
        if ($given('Tax-free up to')) {
            $figures['cap'] = (float) $row->money('Tax-free up to');
        }
        if ($given('Approval reference')) {
            $figures['approval_reference'] = trim($row->text('Approval reference'));
        }
        if ($given('Counts toward 13th')) {
            $figures['counts_toward_13th'] = $row->flag('Counts toward 13th') ?? false;
        }
        // A figure the file fills that the rule it names cannot take is a
        // mistake to report, never to drop; one the file leaves blank follows the rule.
        if (in_array($figures['amount_type'], [BenefitRule::AMOUNT_TYPED, BenefitRule::AMOUNT_MONTH], true)) {
            if ($given('Amount')) {
                throw new RuntimeException('Amount: leave it blank for '.self::AMOUNT_TYPES[$figures['amount_type']].'.');
            }
            $figures['amount'] = null;
        }
        if ($figures['frequency'] !== BenefitRule::FREQUENCY_YEARLY) {
            if ($given('Month')) {
                throw new RuntimeException('Month: only a yearly benefit has a month.');
            }
            $figures['month'] = null;
        }
        if ($figures['tax'] !== BenefitRule::TAX_DE_MINIMIS) {
            if ($given('Tax-free up to')) {
                throw new RuntimeException('Tax-free up to: only a de minimis benefit has a cap.');
            }
            $figures['cap'] = null;
        }
        if ($type !== null && $type->name === $name && (string) $type->description === $description && $current === $figures) {
            return ImportOutcome::UNCHANGED;
        }
        app(SaveIncentiveType::class)->handle($actor, $type === null ? null : (string) $type->getKey(), $name, $description, $taxable, $figures);

        return $type === null ? ImportOutcome::CREATED : ImportOutcome::UPDATED;
    }

    /**
     * The Employment list ids the names spell, or null for everyone.
     *
     * @return list<string>|null
     */
    private function statusIds(Access $actor, string $names): ?array
    {
        $wanted = array_values(array_filter(array_map(static fn (string $n): string => mb_strtolower(trim($n)), explode(';', $names)), static fn (string $n): bool => $n !== ''));
        if ($wanted === []) {
            return null;
        }
        $byName = [];
        foreach (app(ReferenceCatalogs::class)->for('employment')->options($actor) as $status) {
            $byName[mb_strtolower(trim($status['name']))] = $status['id'];
        }
        $ids = [];
        foreach ($wanted as $name) {
            if (! isset($byName[$name])) {
                throw new RuntimeException("No employment status is named \"{$name}\".");
            }
            $ids[] = $byName[$name];
        }

        return array_values(array_unique($ids));
    }
}
