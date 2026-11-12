<?php

declare(strict_types=1);

namespace App\Livewire\Records;

use App\Livewire\Concerns\WithSorting;
use App\Livewire\Concerns\WithTableFilters;
use App\Livewire\Records\Support\Hris;
use App\Modules\Payroll\Application\PayrollData;
use App\Modules\Payroll\Application\Queries\PayrollRecords;
use App\Modules\Payroll\Domain\Deductions\PercentDeduction;
use App\Platform\Records\ReferenceCatalogs;
use App\Support\Access;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One managed option list — the port of
 * src/app/(protected)/records/reference-list.tsx, instantiated per list by
 * route instead of hand-copied per page. Document types, employment
 * statuses, leave types and payroll deductions differ only in their table,
 * prefix and wording, so they share this screen (and its dialogs) verbatim.
 * Positions are placed in departments and units, so they have a page of
 * their own ({@see PositionsPage}).
 *
 * Permissions, exactly as the reference:
 *  - the PAGE needs RECORDS (records/layout.tsx) — route middleware;
 *  - READS are open to any session (GET /api/records/[table]);
 *  - every WRITE needs all_data + RECORDS (requireAllDataModule("RECORDS"),
 *    api/records/[table]/route.ts), re-checked inside each action;
 *  - the "in use by N" counts are the owners' UNSCOPED counts of the records
 *    still carrying each entry (ReferenceCatalog::inUseCounts) — counts only,
 *    never who — because the delete refuses on that same number.
 */
#[Layout('layouts.app')]
class ReferenceListPage extends Component
{
    use WithSorting;
    use WithTableFilters;

    /**
     * The four lists sharing this screen — the props each `page.tsx` passed to
     * `<ReferenceList>` (documents/page.tsx, employment/page.tsx,
     * leave-types/page.tsx, payroll-deductions/page.tsx).
     *
     * The Payroll Deductions list is also the Basic Payroll grid's
     * Deductions columns, one per row. Which rows payroll computes
     * (`payroll_deductions.calculation`) is system data the migration and
     * seeder set; this screen only names them, and cannot delete one.
     *
     * `csv` says whether the list has an Export CSV and an Import CSV. THE
     * TWO GENERAL RECORDS LISTS HERE — Document and Employment — AND THE
     * LEAVE TYPES HAVE NEITHER: no button, no export route, no entry in the
     * transfer registry. Payroll Deductions carries both.
     *
     * @var array<string, array{table: string, prefix: string,
     *     title: string, description: string, noun: string, plural: string,
     *     nameLabel: string, showDescription: bool, csv: bool}>
     */
    public const array LISTS = [
        'documents' => [

            'table' => 'document_types',
            'prefix' => 'DOC',
            'title' => 'Document',
            'description' => 'Document types offered when attaching a file to an employee record.',
            'noun' => 'document type',
            'plural' => 'document types',
            'nameLabel' => 'Name',
            'showDescription' => true,
            'csv' => false,
        ],
        'employment' => [

            'table' => 'employment_statuses',
            'prefix' => 'EMP',
            'title' => 'Employment',
            'description' => 'Statuses offered on employee records — regular, probationary and the rest.',
            'noun' => 'employment status',
            'plural' => 'employment statuses',
            'nameLabel' => 'Name',
            'showDescription' => true,
            'csv' => false,
        ],
        'leave-types' => [

            'table' => 'leave_types',
            'prefix' => 'LVE',
            'title' => 'Leave Settings',
            'description' => 'Leave types offered when an employee files a request.',
            'noun' => 'leave type',
            'plural' => 'leave types',
            'nameLabel' => 'Leave',
            'showDescription' => false,
            'csv' => false,
        ],
        'payroll-deductions' => [

            'table' => 'payroll_deductions',
            'prefix' => 'DED',
            'title' => 'Payroll Deductions',
            'description' => 'Every deduction here is a column of the Basic Payroll.',
            'noun' => 'deduction',
            'plural' => 'deductions',
            'nameLabel' => 'Name',
            'showDescription' => false,
            'csv' => true,
        ],
    ];

    /**
     * The lists that carry a CSV, keyed by slug — what the export routes and
     * the transfer registry are built from, so a list without one has no
     * route to download from and no key to import under.
     *
     * @return array<string, array{table: string, prefix: string,
     *     title: string, description: string, noun: string, plural: string,
     *     nameLabel: string, showDescription: bool, csv: bool}>
     */
    public static function csvLists(): array
    {
        return array_filter(self::LISTS, static fn (array $list): bool => $list['csv']);
    }

    /** The list this screen shows under Records → Payroll Settings, at /records/payroll-settings/payroll-deductions. */
    public const string PAYROLL_LIST = 'payroll-deductions';

    /**
     * The lists that live under Records Management: every one but Payroll
     * Deductions, a Payroll Setting.
     *
     * @return array<string, array{table: string, prefix: string,
     *     title: string, description: string, noun: string, plural: string,
     *     nameLabel: string, showDescription: bool, csv: bool}>
     */
    public static function recordsLists(): array
    {
        return array_diff_key(self::LISTS, [self::PAYROLL_LIST => true]);
    }

    /** Which list this page is. Route-supplied and authorization-relevant → #[Locked]. */
    #[Locked]
    public string $slug = '';

    /** Add/Edit dialog visibility. Plain UI state. */
    public bool $dialogOpen = false;

    /** The row being edited, or null when adding. Id-bearing → #[Locked]; set via actions only. */
    #[Locked]
    public ?string $editingId = null;

    public string $formName = '';

    public string $formDescription = '';

    /* ---- Payroll Deductions only: how the row is priced ---- */

    /** TYPED (an amount per employee) or PERCENT (a percentage of salary). */
    public string $formComputation = 'TYPED';

    public string $formPercentEmployee = '';

    public string $formPercentEmployer = '';

    public string $formBase = PercentDeduction::BASE_MONTHLY;

    public string $formFrequency = PercentDeduction::FREQUENCY_EVERY;

    public string $formCap = '';

    public bool $formBeforeTax = false;

    /** A refusal of the percentage rule, shown under its section (the Name field keeps its own). */
    public ?string $ruleError = null;

    /** The dialog's inline error — "Name is required." (reference-list.tsx:335-338). */
    public ?string $formError = null;

    /** Delete confirmation visibility. Plain UI state. */
    public bool $confirmOpen = false;

    /** The row queued for deletion. Id-bearing → #[Locked]. */
    #[Locked]
    public ?string $deletingId = null;

    public function mount(string $slug): void
    {
        abort_unless(isset(self::LISTS[$slug]), 404);

        $this->slug = $slug;

        // useSort<SortKey>("name") — reference-list.tsx:103.
        $this->sortKey = 'name';
    }

    /** @return list<string> */
    protected function sortableKeys(): array
    {
        return ['id', 'name'];
    }

    /** @return array{table: string, prefix: string, title: string, description: string, noun: string, plural: string, nameLabel: string, showDescription: bool, csv: bool} */
    public function config(): array
    {
        return self::LISTS[$this->slug];
    }

    /** Open the Add dialog clean (the source keys the dialog off `editing?.id ?? "new"`). */
    /** Any edit clears the last refusal. */
    public function updated(string $name): void
    {
        if (str_starts_with($name, 'form')) {
            $this->formError = null;
            $this->ruleError = null;
        }
    }

    public function openAdd(): void
    {
        $this->editingId = null;
        $this->formName = '';
        $this->formDescription = '';
        $this->fillPercent(null);
        $this->formError = null;
        $this->ruleError = null;
        $this->dialogOpen = true;
    }

    public function startEdit(string $id): void
    {
        $row = app(ReferenceCatalogs::class)->for($this->slug)->find(app(Access::class), $id);

        if ($row === null) {
            Flux::toast(variant: 'danger', text: "That {$this->config()['noun']} no longer exists.");

            return;
        }

        $this->editingId = (string) $row->getKey();
        $this->formName = (string) $row->getAttribute('name');
        $this->formDescription = (string) ($row->getAttribute('description') ?? '');
        $percent = $row->getAttribute('percent');
        $this->fillPercent(is_array($percent) ? $percent : null);
        $this->formError = null;
        $this->ruleError = null;
        $this->dialogOpen = true;
    }

    /**
     * The percentage fields from a row's rule, in display terms; the typed
     * shape when it has none.
     *
     * @param  array<string, mixed>|null  $percent
     */
    private function fillPercent(?array $percent): void
    {
        $this->formComputation = $percent === null ? 'TYPED' : 'PERCENT';
        $this->formPercentEmployee = $percent === null ? '' : Hris::moneyText($percent['employee'] ?? 0);
        $this->formPercentEmployer = $percent === null ? '' : Hris::moneyText($percent['employer'] ?? 0);
        $this->formBase = is_string($percent['base'] ?? null) ? $percent['base'] : PercentDeduction::BASE_MONTHLY;
        $this->formFrequency = is_string($percent['frequency'] ?? null) ? $percent['frequency'] : PercentDeduction::FREQUENCY_EVERY;
        $this->formCap = is_numeric($percent['cap'] ?? null) ? Hris::moneyText($percent['cap']) : '';
        $this->formBeforeTax = (bool) ($percent['before_tax'] ?? false);
    }

    /**
     * The percentage rule being typed, in PercentDeduction's shape; null for
     * a typed row; a sentence when a figure is unreadable.
     *
     * @return array<string, mixed>|string|null
     */
    private function percentFigures(): array|string|null
    {
        if ($this->slug !== 'payroll-deductions' || $this->formComputation !== 'PERCENT') {
            return null;
        }
        $employee = self::percentNumber($this->formPercentEmployee);
        $employer = self::percentNumber($this->formPercentEmployer);
        if ($employee === null || $employer === null) {
            return 'Enter the employee and employer shares as percents, 0 for none.';
        }
        $cap = null;
        if (trim($this->formCap) !== '') {
            $cap = self::percentNumber($this->formCap);
            if ($cap === null) {
                return 'Enter the monthly cap, or leave it blank.';
            }
        }

        return ['employee' => $employee, 'employer' => $employer, 'base' => $this->formBase, 'frequency' => $this->formFrequency, 'cap' => $cap, 'before_tax' => $this->formBeforeTax];
    }

    /**
     * What the rule being typed takes at a sample figure, worked out by
     * the rule itself — or, while a figure is unreadable, what is wrong.
     *
     * @return array{text: string, valid: bool}|null
     */
    private function percentSample(): ?array
    {
        $figures = $this->percentFigures();
        if ($figures === null) {
            return null;
        }
        if (is_string($figures)) {
            return ['text' => $figures, 'valid' => false];
        }
        $error = PercentDeduction::error($figures);
        if ($error !== null) {
            return ['text' => $error, 'valid' => false];
        }
        $rule = PercentDeduction::fromArray($figures);
        $shares = $rule->sharesFor(30000.0, '2026-01-16');
        $base = match ($rule->base) {
            PercentDeduction::BASE_MONTHLY => 'a month',
            PercentDeduction::BASE_BASIC => 'of basic pay this payroll',
            default => 'of gross pay this payroll',
        };
        $when = $rule->frequency === PercentDeduction::FREQUENCY_SECOND ? 'On the 2nd cut-off only, at' : 'At';
        $each = $rule->frequency === PercentDeduction::FREQUENCY_EVERY
            ? ($rule->base === PercentDeduction::BASE_MONTHLY ? ' each payroll (half of the monthly figure)' : ' each payroll')
            : '';
        $fraction = $rule->frequency === PercentDeduction::FREQUENCY_EVERY ? 0.5 : 1.0;
        $capped = $rule->cap !== null && max($shares['employee'], $shares['employer']) >= $rule->cap * $fraction
            ? ' — capped at '.Hris::peso($rule->cap).' a month per share'
            : '';

        return ['text' => "{$when} ₱30,000 {$base}: employee ".Hris::peso($shares['employee']).', employer '.Hris::peso($shares['employer']).$each.$capped.'.', 'valid' => true];
    }

    private static function percentNumber(string $text): ?float
    {
        $text = str_replace(',', '', trim($text));
        if ($text === '') {
            return 0.0;
        }
        if (! is_numeric($text) || ! is_finite((float) $text)) {
            return null;
        }

        return (float) $text;
    }

    /**
     * Save the dialog — create or rename, exactly as reference-list.tsx `save()`
     * plus the API's own validation (ReferenceInput, schemas.ts:97-103).
     */
    public function save(): void
    {
        $name = trim($this->formName);
        if ($name === '') {
            $this->formError = 'Name is required.';

            return;
        }
        $this->formError = null;
        $this->ruleError = null;
        $access = app(Access::class);
        $percent = $this->percentFigures();
        if (is_string($percent)) {
            $this->ruleError = $percent;

            return;
        }
        if (is_array($percent)) {
            $this->ruleError = PercentDeduction::error($percent);
            if ($this->ruleError !== null) {
                return;
            }
        }
        $extra = $this->slug === 'payroll-deductions' && PayrollData::percentDeductionsReady() ? ['percent' => $percent] : [];
        try {
            $message = app(ReferenceCatalogs::class)->for($this->slug)->save($access, $this->editingId, $name, $this->formDescription, $extra);
        } catch (UniqueConstraintViolationException) {
            $this->formError = "\"{$name}\" is already on the list.";

            return;
        } catch (QueryException $e) {
            report($e);
            Flux::toast(variant: 'danger', text: 'The record could not be saved. Please try again.');

            return;
        } catch (\RuntimeException $e) {
            // The catalog's own refusals: a name problem stays with the Name field, the rest with the rule.
            if (str_contains($e->getMessage(), 'computed deduction') || str_contains($e->getMessage(), 'percent') || str_contains($e->getMessage(), 'share')) {
                $this->ruleError = $e->getMessage();
            } else {
                $this->formError = $e->getMessage();
            }

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
            $message = app(ReferenceCatalogs::class)->for($this->slug)->delete($access, $id);
        } catch (QueryException $e) {
            report($e);
            Flux::toast(variant: 'danger', text: 'The record could not be deleted. Please try again.');

            return;
        } catch (\RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }
        Flux::toast(variant: 'success', text: $message);
    }

    public function render(): View
    {
        $config = $this->config();

        $catalog = app(ReferenceCatalogs::class)->for($this->slug);
        $rows = $this->acceptPage($catalog->page(app(Access::class), $this->search, $this->sortKey, $this->sortDir, (int) $this->getPage(), $this->perPage));

        // Per-row usage: the owner's UNSCOPED count of the records still
        // carrying the entry (ReferenceCatalog::inUseCounts), the same number
        // the delete refuses on — a settings manager who sees few employees
        // must not read a held status as free to delete.
        $draftUsage = [];
        if ($this->slug === 'payroll-deductions') {
            $ids = [];
            foreach ($rows as $row) {
                $ids[] = (string) $row->getKey();
            }
            // What blocks a delete is a FINALIZED payroll (history); drafts
            // only warn, since their typed lines leave with the row.
            $usage = [];
            foreach (app(PayrollRecords::class)->deductionUsage(app(Access::class), $ids) as $id => $counts) {
                $usage[$id] = $counts['finalized'];
                $draftUsage[$id] = $counts['drafts'];
            }
        } else {
            $usage = $catalog->inUseCounts(app(Access::class), $rows);
        }

        $editing = $this->editingId !== null ? $catalog->find(app(Access::class), $this->editingId) : null;
        $deleting = $this->deletingId !== null ? $catalog->find(app(Access::class), $this->deletingId) : null;

        return view('livewire.records.reference-list-page', [
            // Writes need all-data; a viewer gets the list without the buttons.
            'canManage' => $catalog->mayManage(app(Access::class)),
            'config' => $config,
            'rows' => $rows,
            'usage' => $usage,
            // Payroll Deductions: the DRAFT payrolls typing each row — a
            // warning on delete, never a block.
            'draftUsage' => $draftUsage,
            'editing' => $editing,
            'deleting' => $deleting,
            // Payroll Deductions: the percentage rule's fields and sample.
            'percentReady' => $this->slug === 'payroll-deductions' && PayrollData::percentDeductionsReady(),
            'percentSample' => $this->dialogOpen ? $this->percentSample() : null,
        ])->title($config['title']);
    }
}
