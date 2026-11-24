<?php

declare(strict_types=1);

namespace App\Livewire\Records;

use App\Livewire\Concerns\WithSorting;
use App\Livewire\Concerns\WithTableFilters;
use App\Livewire\Records\Support\Hris;
use App\Livewire\Records\Support\IncentiveTypeRows;
use App\Modules\Payroll\Application\BenefitCeilings;
use App\Modules\Payroll\Application\Commands\DeleteIncentiveType;
use App\Modules\Payroll\Application\Commands\ReplaceBenefitCeiling;
use App\Modules\Payroll\Application\Commands\SaveIncentiveType;
use App\Modules\Payroll\Application\PayrollData;
use App\Modules\Payroll\Application\PayrollSettingsAccess;
use App\Modules\Payroll\Application\Queries\BenefitCeilingRecords;
use App\Modules\Payroll\Application\Queries\IncentiveTypeRecords;
use App\Modules\Payroll\Contracts\Views\IncentiveTypeView;
use App\Modules\Payroll\Domain\Benefits\BenefitRule;
use App\Modules\Payroll\Domain\Benefits\LeaveCashOutValue;
use App\Platform\Records\ReferenceCatalogs;
use App\Support\Access;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use RuntimeException;

/**
 * Incentives & Bonuses — the benefits catalog under Records → Payroll
 * Settings: the allowances, incentives and bonuses a payroll can pay, each
 * with how much, how often, who qualifies, how it is prorated and how it is
 * taxed (`BenefitRule`). A scheduled benefit is proposed on every draft
 * payroll it is due on; a payroll officer can still type over the amount.
 *
 * Payroll officers pay these but hold no Records grant, so the page is
 * gated on RECORDS OR PAYROLL, reads are open to either, and every write
 * needs all-data in either — re-checked inside the Payroll module's
 * commands. The run workspace's incentives dialog links here.
 *
 * "In use by N" counts the payroll lines naming a type (draft or finalized):
 * such a type cannot be deleted, only edited.
 */
#[Layout('layouts.app')]
#[Title('Incentives & Bonuses')]
class IncentiveTypesPage extends Component
{
    use WithSorting;
    use WithTableFilters;

    /** Add/Edit dialog visibility. Plain UI state. */
    public bool $dialogOpen = false;

    /** The type being edited, or null when adding. Id-bearing → #[Locked]. */
    #[Locked]
    public ?string $editingId = null;

    public string $formName = '';

    public string $formDescription = '';

    public string $formAmountType = BenefitRule::AMOUNT_TYPED;

    public string $formAmount = '';

    public string $formFrequency = BenefitRule::FREQUENCY_ONCE;

    public string $formMonth = '';

    /** ALL, or SOME of the Employment statuses, ticked in `$formStatuses`. */
    public string $formStatusMode = 'ALL';

    /**
     * The Employment statuses ticked, id => on. Client-editable: save()
     * keeps only ids on the Employment list.
     *
     * @var array<string, bool>
     */
    public array $formStatuses = [];

    public string $formMinServiceMonths = '0';

    public string $formProration = BenefitRule::PRORATION_NONE;

    public string $formTax = BenefitRule::TAX_TAXABLE;

    public string $formCap = '';

    public string $formApprovalReference = '';

    /**
     * The rule's stored basic-pay flag, carried through an edit as it
     * stands: no field shows or changes it, and a new type starts with it
     * off. #[Locked], so only the row opened for editing sets it.
     */
    #[Locked]
    public bool $formCountsToward13th = false;

    /** Whether leave credit cash-outs are paid as this type (one type at most; the command moves the flag). */
    public bool $formPaysLeaveCashOut = false;

    /** What one converted day pays on the type that pays them: FACTOR or DAILY (LeaveCashOutValue). Plain form state; SaveIncentiveType re-validates it. */
    public string $formCashOutDayValue = LeaveCashOutValue::FACTOR;

    /** The FACTOR method's factor as typed. */
    public string $formCashOutFactor = '0.0481927';

    /** The dialog's inline error. */
    public ?string $formError = null;

    /** The control the error belongs to (name, amount, month, frequency, statuses, service, proration, cap, reference, tax), or null for the dialog as a whole. */
    public ?string $formErrorField = null;

    /** Delete confirmation visibility. Plain UI state. */
    public bool $confirmOpen = false;

    /** The type queued for deletion. Id-bearing → #[Locked]. */
    #[Locked]
    public ?string $deletingId = null;

    /** The tax-exempt ceiling dialog. Plain UI state. */
    public bool $ceilingOpen = false;

    /**
     * The ceiling as typed: the pesos alone. Free text — no id in it, so not
     * #[Locked]; ReplaceBenefitCeiling re-validates it.
     *
     * @var array<string, string>
     */
    public array $ceilingForm = ['amount' => ''];

    public ?string $ceilingError = null;

    public function mount(): void
    {
        $this->sortKey = 'id';
    }

    /** Opens the ceiling dialog on the ceiling in use. */
    public function openCeiling(): void
    {
        $access = app(Access::class);
        if (! PayrollSettingsAccess::mayManage($access)) {
            return;
        }
        $current = app(BenefitCeilingRecords::class)->current($access);
        $this->ceilingForm = ['amount' => $current === null ? '' : Hris::moneyText($current->ceiling)];
        $this->ceilingError = null;
        $this->ceilingOpen = true;
    }

    public function updatedCeilingOpen(bool $value): void
    {
        if (! $value) {
            $this->ceilingError = null;
        }
    }

    /** Replaces the ceiling through the audited command; it applies at once. */
    public function saveCeiling(): void
    {
        $access = app(Access::class);
        if (! PayrollSettingsAccess::mayManage($access)) {
            Flux::toast(variant: 'danger', text: 'Your role cannot manage payroll settings.');

            return;
        }
        try {
            $message = app(ReplaceBenefitCeiling::class)->handle($access, (string) ($this->ceilingForm['amount'] ?? ''));
        } catch (QueryException $e) {
            report($e);
            $this->ceilingError = 'The ceiling could not be saved. Nothing was changed. Please try again.';

            return;
        } catch (RuntimeException $e) {
            $this->ceilingError = $e->getMessage();

            return;
        }
        $this->ceilingOpen = false;
        $this->ceilingError = null;
        Flux::toast(variant: 'success', text: $message);
    }

    /** Any edit clears the last refusal. */
    public function updated(string $name): void
    {
        if (str_starts_with($name, 'form')) {
            $this->formError = null;
            $this->formErrorField = null;
        }
    }

    /**
     * The amount rule decides what else applies: a typed amount is never
     * scheduled (How often falls back to one-time), and a peso figure is not
     * a percent (the amount is cleared across the change).
     */
    public function updatedFormAmountType(string $value): void
    {
        if ($value === BenefitRule::AMOUNT_TYPED) {
            $this->formFrequency = BenefitRule::FREQUENCY_ONCE;
            $this->formMonth = '';
        }
        $this->formAmount = '';
    }

    /** @return list<string> */
    protected function sortableKeys(): array
    {
        return ['id', 'name', 'taxable'];
    }

    public function openAdd(): void
    {
        $this->editingId = null;
        $this->formName = '';
        $this->formDescription = '';
        $this->fillRule(BenefitRule::typed(true));
        $this->formPaysLeaveCashOut = false;
        $this->fillCashOutValue(LeaveCashOutValue::standard());
        $this->formError = null;
        $this->formErrorField = null;
        $this->dialogOpen = true;
    }

    public function startEdit(string $id): void
    {
        $row = app(IncentiveTypeRecords::class)->find(app(Access::class), $id);

        if ($row === null) {
            Flux::toast(variant: 'danger', text: 'That incentive type no longer exists.');

            return;
        }

        $this->editingId = (string) $row->getKey();
        $this->formName = $row->name;
        $this->formDescription = (string) ($row->description ?? '');
        $this->fillRule(self::ruleOf($row));
        $this->formPaysLeaveCashOut = (bool) ($row->pays_leave_cash_out ?? false);
        $this->fillCashOutValue(PayrollData::cashOutValueReady() ? LeaveCashOutValue::fromColumns($row->cash_out_day_value ?? null, $row->cash_out_factor ?? null) : LeaveCashOutValue::standard());
        $this->formError = null;
        $this->formErrorField = null;
        $this->dialogOpen = true;
    }

    /** The dialog's day value fields from a stored or starting value; DAILY keeps the standard factor in the box. */
    private function fillCashOutValue(LeaveCashOutValue $value): void
    {
        $this->formCashOutDayValue = $value->method;
        $factor = $value->factor ?? LeaveCashOutValue::DEFAULT_FACTOR;
        $this->formCashOutFactor = rtrim(rtrim(number_format($factor, LeaveCashOutValue::FACTOR_DECIMALS, '.', ''), '0'), '.');
    }

    /**
     * Save the dialog. The catches are ordered narrowest first — a unique
     * clash is the duplicate sentence, any other QueryException the generic
     * one, and a RuntimeException the command's own refusal; each class
     * extends the next, so the order is load-bearing.
     */
    public function save(): void
    {
        if (trim($this->formName) === '') {
            $this->refuse('Name is required.');

            return;
        }

        $this->formError = null;
        $this->formErrorField = null;
        $access = app(Access::class);
        $rule = PayrollData::benefitRulesReady() ? $this->ruleFigures($access) : null;
        if (is_string($rule)) {
            $this->refuse($rule);

            return;
        }
        try {
            // The day's value is sent only with the flag on: a type that does
            // not pay cash conversions keeps whatever it holds.
            $value = $this->formPaysLeaveCashOut && PayrollData::cashOutValueReady() ? ['day_value' => $this->formCashOutDayValue, 'factor' => $this->formCashOutFactor] : null;
            $message = app(SaveIncentiveType::class)->handle($access, $this->editingId, $this->formName, $this->formDescription, $this->formTax === BenefitRule::TAX_TAXABLE, $rule, $this->formPaysLeaveCashOut, $value);
        } catch (UniqueConstraintViolationException) {
            $this->refuse('An incentive type with that name already exists.');

            return;
        } catch (QueryException $e) {
            report($e);
            Flux::toast(variant: 'danger', text: 'The incentive type could not be saved. Nothing was changed. Please try again.');

            return;
        } catch (RuntimeException $e) {
            $this->refuse($e->getMessage());

            return;
        }
        Flux::toast(variant: 'success', text: $message);
        $this->dialogOpen = false;
        $this->editingId = null;
    }

    public function confirmDelete(string $id): void
    {
        $this->deletingId = $id;
        $this->confirmOpen = true;
    }

    public function deleteConfirmed(): void
    {
        $id = $this->deletingId;
        $this->confirmOpen = false;
        $this->deletingId = null;
        if ($id === null) {
            return;
        }
        $access = app(Access::class);
        try {
            $name = app(DeleteIncentiveType::class)->handle($access, $id);
        } catch (QueryException $e) {
            report($e);
            Flux::toast(variant: 'danger', text: 'The incentive type could not be deleted. It may be on a payroll now.');

            return;
        } catch (RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }
        Flux::toast(variant: 'success', text: "{$name} deleted.");
    }

    public function render(): View
    {
        $access = app(Access::class);
        $canEdit = PayrollSettingsAccess::mayManage($access);
        $usage = app(IncentiveTypeRecords::class)->usage($access);

        // Search and sort: one copy shared with the CSV export.
        $items = IncentiveTypeRows::list($access, $this->search, $this->sortKey, $this->sortDir);

        $editing = $this->editingId !== null ? app(IncentiveTypeRecords::class)->find($access, $this->editingId) : null;
        $deleting = $this->deletingId !== null ? app(IncentiveTypeRecords::class)->find($access, $this->deletingId) : null;
        $statuses = $this->statusOptions($access);

        return view('livewire.records.incentive-types-page', [
            'rows' => $this->paginateClamped($items),
            'usage' => $usage,
            'canEdit' => $canEdit,
            'editing' => $editing,
            'deleting' => $deleting,
            'statuses' => $statuses,
            'statusNames' => array_column($statuses, 'name', 'id'),
            'rulesReady' => PayrollData::benefitRulesReady(),
            'ceilingReady' => BenefitCeilings::ready(),
            'ceiling' => app(BenefitCeilingRecords::class)->current($access),
            'sample' => $this->dialogOpen ? $this->sample() : null,
            // Statuses ticked in the dialog that the Employment list no longer has: saving drops them.
            'removedStatuses' => count(array_filter(array_keys(array_filter($this->formStatuses)), static fn (string $id): bool => ! in_array($id, array_column($statuses, 'id'), true))),
        ]);
    }

    /** A refusal, filed under the control it names so the dialog can flag it there. */
    private function refuse(string $message): void
    {
        $this->formError = $message;
        $this->formErrorField = self::fieldOf($message);
    }

    /** Which control a refusal is about, from its wording. */
    private static function fieldOf(string $message): ?string
    {
        $lower = mb_strtolower($message);
        foreach ([
            'name' => ['name is required', 'that name already exists', 'keep the name'],
            'amount' => ['enter the amount', 'percent of the monthly salary', 'between 0 and 1,000', 'takes no figure'],
            'month' => ['month it is paid', 'has a month'],
            'frequency' => ['how often', 'cannot be scheduled'],
            'statuses' => ['who qualifies', 'employment status'],
            'service' => ['months of service'],
            'proration' => ['proration'],
            'cap' => ['cap'],
            'reference' => ['approval reference'],
            'tax' => ['how it is taxed'],
        ] as $field => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($lower, $needle)) {
                    return $field;
                }
            }
        }

        return null;
    }

    /** The form from a benefit's rule, in display terms. */
    private function fillRule(BenefitRule $rule): void
    {
        $this->formAmountType = $rule->amountType;
        $this->formAmount = $rule->amount === null ? '' : Hris::moneyText($rule->amount);
        $this->formFrequency = $rule->frequency;
        $this->formMonth = $rule->month === null ? '' : (string) $rule->month;
        $this->formStatusMode = $rule->statuses === null ? 'ALL' : 'SOME';
        $this->formStatuses = [];
        foreach ($rule->statuses ?? [] as $id) {
            $this->formStatuses[$id] = true;
        }
        $this->formMinServiceMonths = (string) $rule->minServiceMonths;
        $this->formProration = $rule->proration;
        $this->formTax = $rule->tax;
        $this->formCap = $rule->cap === null ? '' : Hris::moneyText($rule->cap);
        $this->formApprovalReference = (string) ($rule->approvalReference ?? '');
        $this->formCountsToward13th = $rule->countsToward13th;
    }

    /**
     * The typed form in the rule's shape, or the first thing wrong with it.
     * The Employment statuses are re-resolved against the list.
     *
     * @return array<string, mixed>|string
     */
    private function ruleFigures(Access $access): array|string
    {
        $amount = null;
        if (! in_array($this->formAmountType, [BenefitRule::AMOUNT_TYPED, BenefitRule::AMOUNT_MONTH], true)) {
            $amount = self::number($this->formAmount);
            if ($amount === null) {
                return $this->formAmountType === BenefitRule::AMOUNT_PERCENT ? 'Enter the percent of the monthly salary.' : 'Enter the amount.';
            }
        }
        $month = null;
        if ($this->formFrequency === BenefitRule::FREQUENCY_YEARLY) {
            $month = is_numeric($this->formMonth) ? (int) $this->formMonth : 0;
        }
        $statuses = null;
        if ($this->formStatusMode === 'SOME') {
            $known = array_column($this->statusOptions($access), 'id');
            $statuses = array_values(array_filter($known, fn (string $id): bool => (bool) ($this->formStatuses[$id] ?? false)));
            if ($statuses === []) {
                return 'Tick at least one employment status, or choose all employees.';
            }
        }
        $months = self::number($this->formMinServiceMonths) ?? -1.0;
        $cap = null;
        if ($this->formTax === BenefitRule::TAX_DE_MINIMIS) {
            $cap = self::number($this->formCap);
            if ($cap === null) {
                return 'Enter the cap up to which it is tax-exempt.';
            }
        }

        return [
            'amount_type' => $this->formAmountType,
            'amount' => $amount,
            'frequency' => $this->formFrequency,
            'month' => $month,
            'statuses' => $statuses,
            'min_service_months' => $months < 0 || floor($months) !== $months ? -1 : (int) $months,
            'proration' => $this->formProration,
            'tax' => $this->formTax,
            'cap' => $cap,
            'approval_reference' => trim($this->formApprovalReference) === '' ? null : trim($this->formApprovalReference),
            'counts_toward_13th' => $this->formCountsToward13th,
        ];
    }

    /**
     * What the rule being typed pays at a sample salary, worked out by the
     * rule itself: 30,000 a month, 22 of 22 days paid, a full year served.
     *
     * @return array{text: string}|null
     */
    private function sample(): ?array
    {
        $figures = $this->ruleFigures(app(Access::class));
        if (is_string($figures) || BenefitRule::error($figures) !== null) {
            return null;
        }
        $rule = BenefitRule::fromArray($figures);
        if (! $rule->isScheduled()) {
            return null;
        }
        $amount = $rule->amountFor(30000.0, 22.0, 22.0, 22, 12);

        return ['text' => 'At $30,000 a month with every day paid: '.Hris::money($amount).' when due.'];
    }

    /**
     * The Employment list, id => name, for the Who-qualifies checkboxes and
     * the table's phrases.
     *
     * @return list<array{id: string, name: string}>
     */
    private function statusOptions(Access $access): array
    {
        return app(ReferenceCatalogs::class)->for('employment')->options($access);
    }

    /** The rule a row carries (the typed shape before the catalog has the columns). */
    private static function ruleOf(IncentiveTypeView $row): BenefitRule
    {
        if (! PayrollData::benefitRulesReady()) {
            return BenefitRule::typed((bool) $row->is_taxable);
        }

        return BenefitRule::fromArray([
            'amount_type' => $row->amount_type, 'amount' => $row->amount, 'frequency' => $row->frequency, 'month' => $row->month,
            'statuses' => $row->statuses, 'min_service_months' => (int) ($row->min_service_months ?? 0), 'proration' => $row->proration,
            'tax' => $row->tax, 'cap' => $row->cap, 'approval_reference' => $row->approval_reference, 'counts_toward_13th' => (bool) ($row->counts_toward_13th ?? false),
        ]);
    }

    private static function number(mixed $text): ?float
    {
        $text = is_string($text) ? str_replace(',', '', trim($text)) : $text;
        if (! is_numeric($text) || ! is_finite((float) $text)) {
            return null;
        }

        return (float) $text;
    }
}
