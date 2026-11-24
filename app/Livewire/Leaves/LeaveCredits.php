<?php

declare(strict_types=1);

namespace App\Livewire\Leaves;

use App\Domain\Enums\ModuleCode;
use App\Livewire\Concerns\TableDefaults;
use App\Livewire\Concerns\WithSorting;
use App\Livewire\Concerns\WithTableFilters;
use App\Modules\Timekeeping\Application\Commands\SaveLeaveCredits;
use App\Modules\Timekeeping\Application\Queries\LeaveCreditRecords;
use App\Modules\Timekeeping\Application\Queries\LeaveRuleRecords;
use App\Modules\Timekeeping\Contracts\Views\LeaveBalanceView;
use App\Modules\Timekeeping\Domain\Absence\LeaveCreditPolicy;
use App\Modules\Timekeeping\Domain\Credits\LeaveQuantity;
use App\Modules\Workforce\Contracts\DirectoryEmployee;
use App\Modules\Workforce\Contracts\OperationalDirectory;
use App\Support\Access;
use App\Support\LocalClock;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use RuntimeException;

/**
 * Leave Management's Leave Credits tab: every employee the actor may see,
 * with what is left of each Leave Settings type's credit for one year, and
 * — for an all_data + LEAVES holder — the "Set credits" dialog that enters
 * them. The reference has no counterpart: it keeps two numbers on the
 * employee record that nothing reads.
 *
 * A child of Leaves\Index rather than more state on it, because both tables
 * sort, search and paginate: this one keeps its own WithSorting /
 * WithTableFilters state, and paginates under its OWN page name
 * (`creditsPage`) — Livewire binds every WithPagination user to `?page=`, so
 * the two tables would otherwise read and write one URL key.
 *
 * Reads go through Timekeeping's LeaveCreditRecords behind the leave list's
 * scope — the directory decides which employees appear, one batched balance
 * read covers the page — and the write is SaveLeaveCredits, which gates,
 * re-resolves the employee, re-validates every field and audits; this class
 * only stages the dialog and toasts what the command answers.
 */
class LeaveCredits extends Component
{
    use WithSorting;
    use WithTableFilters;

    /** The paginator's name — see the class docblock. */
    public const string PAGE_NAME = 'creditsPage';

    /**
     * The day the balances are read as of, 'Y-m-d'. Plain filter state,
     * deliberately unlocked: the date field writes it, `updatedAsOf()`
     * collapses anything outside the three offered years back to today,
     * and it carries no id.
     */
    public string $asOf = '';

    /**
     * The credits year of that day — the year the Set credits dialog
     * enters. Follows `$asOf`; nothing on the page sets it on its own.
     */
    #[Locked]
    public int $year = 0;

    /**
     * The Set credits dialog's employee. Id-bearing → #[Locked]:
     * `openCredits()` alone sets it, after re-checking the grant and
     * re-resolving the id against the actor's visible directory, and
     * `saveCredits()` clears it once the command has written. A client-side
     * Cancel or Escape leaves it staged, which is harmless — the next open
     * overwrites it and nothing reads it but the dialog.
     */
    #[Locked]
    public string $creditsEmployeeId = '';

    /**
     * The Set credits dialog's number fields: leave-type token => the typed
     * days ('12.5', '' for no credit, '0' for an explicit zero). NOT Locked
     * on purpose — this is the wire:model draft the fields edit, the
     * sanctioned exception: `SaveLeaveCredits` re-validates every token
     * (against the creditable Leave Settings types, or one the employee
     * already holds) and every value (a number of whole or half days, 0 to
     * 9999.99) before anything is written, so a client-edited entry is
     * refused, never trusted. Read as mixed for that reason.
     *
     * @var array<array-key, mixed>
     */
    public array $creditDraft = [];

    public function mount(): void
    {
        $this->sortKey = 'employee';
        $this->asOf = LocalClock::todayKey();
        $this->year = self::currentYear();
    }

    /** @return list<string> */
    protected function sortableKeys(): array
    {
        return ['employee'];
    }

    /** The current local year — credits are entered per calendar year. */
    public static function currentYear(): int
    {
        return LeaveCreditPolicy::yearOf(LocalClock::todayKey());
    }

    /**
     * The years the select offers: last, this, next — enough to enter next
     * year's credits in December and to read last year's in January.
     *
     * @return list<int>
     */
    public static function yearOptions(): array
    {
        $year = self::currentYear();

        return [$year - 1, $year, $year + 1];
    }

    /**
     * The day a typed value stands for: a real date inside the offered
     * years (last, this, next), else today.
     */
    public static function asOfDate(mixed $value): string
    {
        $date = is_string($value) ? trim($value) : '';
        $valid = preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $date) === 1
            && checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))
            && in_array(LeaveCreditPolicy::yearOf($date), self::yearOptions(), true);

        return $valid ? $date : LocalClock::todayKey();
    }

    /** Anything but a date inside the offered years collapses to today; the page starts over. */
    public function updatedAsOf(mixed $value): void
    {
        $this->asOf = self::asOfDate($value);
        $this->year = LeaveCreditPolicy::yearOf($this->asOf);
        $this->resetPage(self::PAGE_NAME);
    }

    /**
     * The trait's hook resets the default page name; this table has its own,
     * so the size whitelist is repeated here and the right page reset.
     */
    public function updatedPerPage(mixed $value): void
    {
        $size = (int) $value;
        $this->perPage = in_array($size, TableDefaults::PAGE_SIZE_OPTIONS, true)
            ? $size
            : TableDefaults::DEFAULT_PAGE_SIZE;
        $this->resetPage(self::PAGE_NAME);
    }

    /**
     * Open the Set credits dialog for one row. The id is a client argument,
     * so the grant is re-checked and the id re-resolved through the
     * actor-scoped directory first (an id outside it is dropped on the
     * floor); then the fields are seeded from the year's balances — the
     * entered days, or blank for a type with no credit — and the dialog
     * opens on them.
     */
    public function openCredits(string $employeeId): void
    {
        if ($employeeId === '') {
            return;
        }
        $access = app(Access::class);
        if (! $access->mayReadAllData(ModuleCode::LEAVES)) {
            return;
        }
        if (app(OperationalDirectory::class)->find($access, $employeeId) === null) {
            return;
        }

        $draft = [];
        foreach (app(LeaveCreditRecords::class)->balances($access, $employeeId, $this->year) as $balance) {
            $draft[$balance->request_type] = $balance->credited === null ? '' : LeaveQuantity::credits($balance->credited);
        }

        $this->creditsEmployeeId = $employeeId;
        $this->creditDraft = $draft;

        $this->modal('leave-credits')->show();
    }

    /**
     * The Set credits dialog's Save.
     * A fresh Access is gated here so a refused actor gets the toast at once;
     * the command gates again (all_data + LEAVES), refuses a self-edit,
     * re-resolves the employee, re-validates every field and audits — the
     * write lives there, not here. The draft is shaped to strings first: a
     * non-scalar entry reads blank (no credit), everything else passes
     * through for the command to judge. The dialog stays open on failure so
     * the numbers are not lost, and closes only once the command has
     * answered.
     */
    public function saveCredits(): void
    {
        $employeeId = $this->creditsEmployeeId;
        if ($employeeId === '') {
            return;
        }

        $access = app(Access::class);
        if (! $access->mayReadAllData(ModuleCode::LEAVES)) {
            Flux::toast(variant: 'danger', text: 'Your role cannot manage organization-wide data.');

            return;
        }

        $credits = [];
        foreach ($this->creditDraft as $key => $value) {
            $credits[$key] = is_scalar($value) ? trim((string) $value) : '';
        }

        try {
            $message = app(SaveLeaveCredits::class)->handle($access, $employeeId, $this->year, $credits);
        } catch (QueryException $e) {
            report($e);
            Flux::toast(variant: 'danger', text: 'The leave credits could not be saved. Please try again.');

            return;
        } catch (RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        $this->creditsEmployeeId = '';
        $this->creditDraft = [];
        $this->modal('leave-credits')->close();
        Flux::toast(variant: 'success', text: $message);
    }

    public function render(): View
    {
        $access = app(Access::class);

        // The directory decides who appears (all_data + LEAVES: everyone;
        // otherwise the actor's own row; unlinked: nobody), already in
        // last-name order; desc is the whole list reversed, as the ported
        // screens do it.
        $employees = app(OperationalDirectory::class)->list($access, null, trim($this->search))->values();
        if ($this->sortDir === 'desc') {
            $employees = $employees->reverse()->values();
        }

        $rows = $this->paginateClamped($employees, self::PAGE_NAME);

        /** @var list<string> $pageIds */
        $pageIds = [];
        /** @var DirectoryEmployee $employee */
        foreach ($rows->items() as $employee) {
            $pageIds[] = $employee->id;
        }

        $records = app(LeaveCreditRecords::class);
        $types = $records->creditableTypes($access);
        // The table reads the books as they stood on the chosen day.
        $balances = $records->balancesAsOf($access, $this->asOf, $pageIds);
        $canEdit = $access->mayReadAllData(ModuleCode::LEAVES);

        // The dialog's derived state: its employee and the year's allocation
        // AS IT STANDS NOW, which is what Set credits edits — never the
        // as-of figures the table shows.
        $dialogEmployee = $this->creditsEmployeeId === ''
            ? null
            : app(OperationalDirectory::class)->find($access, $this->creditsEmployeeId);
        /** @var list<LeaveBalanceView> $dialogRows */
        $dialogRows = $dialogEmployee === null
            ? []
            : $records->balances($access, $dialogEmployee->id, $this->year);

        return view('livewire.leaves.leave-credits', [
            'rows' => $rows,
            'types' => $types,
            'balances' => $balances,
            'standing' => app(LeaveRuleRecords::class)->standing($access, $pageIds),
            'lastRun' => app(LeaveRuleRecords::class)->lastRun($access),
            'asOf' => $this->asOf,
            'canEdit' => $canEdit,
            'dialogEmployee' => $dialogEmployee,
            'dialogRows' => $dialogRows,
            // The types the dialog's employee may not use: HR may still set
            // their credits, and the dialog says what that does.
            'dialogIneligible' => $dialogEmployee === null ? [] : (app(LeaveRuleRecords::class)->ineligible($access, [$dialogEmployee->id])[$dialogEmployee->id] ?? []),
        ]);
    }
}
