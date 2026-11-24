<?php

declare(strict_types=1);

namespace App\Livewire\Leaves;

use App\Domain\Enums\ModuleCode;
use App\Livewire\Concerns\TableDefaults;
use App\Livewire\Concerns\WithTableFilters;
use App\Modules\Timekeeping\Application\Commands\RecordLeaveCredit;
use App\Modules\Timekeeping\Application\Queries\LeaveCreditRecords;
use App\Modules\Timekeeping\Application\Queries\LeaveLedgerRecords;
use App\Modules\Timekeeping\Domain\Absence\LeaveCreditPolicy;
use App\Modules\Workforce\Contracts\OperationalDirectory;
use App\Support\Access;
use App\Support\LocalClock;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use RuntimeException;

/**
 * The annual leave ledger on Leave Management's Leave Ledger tab. An all-data
 * LEAVES holder picks any employee in scope and records credit entries; any
 * other LEAVES holder reads only their own record. My Portal has no ledger.
 */
class LeaveLedger extends Component
{
    use WithTableFilters;

    #[Locked]
    public string $employeeId = '';

    public int $year = 0;

    public string $type = 'ALL';

    public bool $entryOpen = false;

    #[Locked]
    public string $entryId = '';

    #[Locked]
    public string $entryEmployeeId = '';

    #[Locked]
    public int $entryYear = 0;

    public string $entryType = '';

    public string $kind = 'ACCRUAL';

    public string $days = '';

    public string $note = '';

    public function mount(): void
    {
        $access = $this->access();
        $this->year = LeaveCredits::currentYear();
        if (! $access->mayReadAllData(ModuleCode::LEAVES)) {
            $this->employeeId = $access->hasEmployeeLink() ? $access->ownEmployeeId() : '';
        }
    }

    #[On('kit-employee-picked')]
    public function employeePicked(string $name, string $id): void
    {
        $access = $this->access();
        if ($name !== 'ledger-employee' || ! $access->mayReadAllData(ModuleCode::LEAVES)) {
            return;
        }
        if (app(OperationalDirectory::class)->find($access, $id) !== null) {
            $this->employeeId = $id;
            $this->clearEntry();
            $this->type = 'ALL';
            $this->resetPage('ledgerPage');
        }
    }

    public function updatedYear(): void
    {
        $this->year = max(LeaveCreditPolicy::YEAR_MIN, min(LeaveCreditPolicy::YEAR_MAX, $this->year));
        $this->clearEntry();
        $this->resetPage('ledgerPage');
    }

    public function updatedType(): void
    {
        $this->resetPage('ledgerPage');
    }

    public function updatedPerPage(mixed $value): void
    {
        $this->perPage = in_array((int) $value, TableDefaults::PAGE_SIZE_OPTIONS, true)
            ? (int) $value : TableDefaults::DEFAULT_PAGE_SIZE;
        $this->resetPage('ledgerPage');
    }

    public function openEntry(): void
    {
        $access = $this->access();
        if (! $this->canEdit($access) || app(OperationalDirectory::class)->find($access, $this->employeeId) === null) {
            return;
        }
        $this->entryEmployeeId = $this->employeeId;
        $this->entryYear = $this->year;
        $this->entryId = (string) Str::ulid();
        $types = app(LeaveCreditRecords::class)->creditableTypes($access);
        $this->entryType = isset($types[$this->type]) ? $this->type : (array_key_first($types) ?? '');
        $this->kind = 'ACCRUAL';
        $this->days = '';
        $this->note = '';
        $this->entryOpen = true;
    }

    public function saveEntry(): void
    {
        $access = $this->access();
        if (! $this->canEdit($access) || ! $this->entryOpen || $this->entryId === ''
            || $this->entryEmployeeId !== $this->employeeId || $this->entryYear !== $this->year) {
            return;
        }
        try {
            $message = app(RecordLeaveCredit::class)->handle($access, $this->entryEmployeeId,
                $this->entryYear, $this->entryType, $this->kind, $this->days, $this->note, $this->entryId);
        } catch (QueryException $exception) {
            report($exception);
            Flux::toast(text: 'The credits could not be recorded. Please try again.', variant: 'danger');

            return;
        } catch (RuntimeException $exception) {
            Flux::toast(text: $exception->getMessage(), variant: 'danger');

            return;
        }
        $this->clearEntry();
        $this->resetPage('ledgerPage');
        Flux::toast(text: $message, variant: 'success');
    }

    private function access(): Access
    {
        $access = app(Access::class);
        abort_unless($access->hasModule(ModuleCode::LEAVES), 403);

        return $access;
    }

    private function clearEntry(): void
    {
        $this->entryOpen = false;
        $this->entryId = '';
        $this->entryEmployeeId = '';
        $this->entryYear = 0;
    }

    private function canEdit(Access $access): bool
    {
        return $access->mayReadAllData(ModuleCode::LEAVES)
            && $this->employeeId !== '' && $this->employeeId !== $access->employeeId;
    }

    public function render(): View
    {
        $access = $this->access();
        $employeeId = $this->employeeId;
        $records = app(LeaveLedgerRecords::class);
        $balances = $records->summary($access, $employeeId, $this->year);
        $types = app(LeaveCreditRecords::class)->creditableTypes($access);
        $historyTypes = $types;
        foreach ($balances as $balance) {
            $historyTypes[$balance->request_type] = $balance->label;
        }

        return view('livewire.leaves.leave-ledger', [
            'employee' => $employeeId === '' ? null : app(OperationalDirectory::class)->find($access, $employeeId),
            'balances' => $balances,
            'types' => $types,
            'historyTypes' => $historyTypes,
            'rows' => $this->acceptPage($records->page($access, $employeeId, $this->year, $this->type,
                (int) $this->getPage('ledgerPage'), $this->perPage), 'ledgerPage'),
            'canPick' => $access->mayReadAllData(ModuleCode::LEAVES),
            'canEdit' => $this->canEdit($access),
            'today' => LocalClock::todayKey(),
        ]);
    }
}
