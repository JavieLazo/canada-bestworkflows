<?php

declare(strict_types=1);

namespace App\Livewire\Leaves;

use App\Domain\Enums\ModuleCode;
use App\Livewire\Concerns\TableDefaults;
use App\Livewire\Concerns\WithSorting;
use App\Livewire\Concerns\WithTableFilters;
use App\Livewire\Records\Support\LeavePolicyForm;
use App\Modules\Timekeeping\Application\Commands\ApproveLeavePolicy;
use App\Modules\Timekeeping\Application\Commands\DiscardLeavePolicy;
use App\Modules\Timekeeping\Application\Commands\SaveLeavePolicy;
use App\Modules\Timekeeping\Application\PostLeaveEarnings;
use App\Modules\Timekeeping\Application\Queries\LeavePolicyRecords;
use App\Modules\Timekeeping\Application\Queries\LeavePresetRecords;
use App\Modules\Timekeeping\Application\Queries\LeaveTypeCreditRecords;
use App\Modules\Timekeeping\Contracts\Views\LeaveTypeView;
use App\Modules\Workforce\Contracts\DirectoryEmployee;
use App\Modules\Workforce\Contracts\OperationalDirectory;
use App\Support\Access;
use App\Support\ManilaClock;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use RuntimeException;

/**
 * Leave Management's Leave policies tab: every employee the actor may see
 * with the leave policy in force for them — how their credits are earned
 * each month and whether lateness and absences are charged to credits —
 * and, for an all-data LEAVES holder, the versions dialog (each dated
 * version, its drafts to approve or discard, the earning postings made)
 * and the policy dialog that prepares a new version, started blank or
 * from a preset. Posting is the schedule's job; "Post earnings now" runs
 * it at once for whoever is waiting.
 *
 * A child of Leaves\Index with its own search and page name
 * (`policiesPage`), like the Leave Credits tab, for the same reason. Reads
 * go through LeavePolicyRecords behind the leave list's scope; every write
 * is a Timekeeping command that gates, re-resolves and audits for itself.
 */
class LeavePolicies extends Component
{
    use WithSorting;
    use WithTableFilters;

    public const string PAGE_NAME = 'policiesPage';

    /** The employee whose versions or new policy the dialogs show. Id-bearing → #[Locked]. */
    #[Locked]
    public string $policyEmployeeId = '';

    /** The draft version the policy dialog edits in place, or null for a new version. Id-bearing → #[Locked]. */
    #[Locked]
    public ?string $editingVersionId = null;

    public bool $versionsOpen = false;

    public bool $policyOpen = false;

    /**
     * The draft version the confirm dialog is about, and what it asks —
     * approve or discard. Approving posts months on the next run and can
     * never be undone, so neither happens from a menu click: the row menu
     * stages the version here, the dialog says what will follow, and only
     * its parameter-less `confirmVersion()` calls the command. Id-bearing →
     * #[Locked].
     */
    #[Locked]
    public ?string $confirmVersionId = null;

    #[Locked]
    public string $confirmAction = '';

    public bool $versionConfirmOpen = false;

    /**
     * The months the staged approval would post at once ('YYYY-MM'), read
     * when the dialog opens.
     *
     * @var list<string>
     */
    #[Locked]
    public array $confirmMonths = [];

    #[Locked]
    public string $confirmFrom = '';

    /**
     * The policy dialog's draft (LeavePolicyForm shape). NOT Locked on
     * purpose — the wire:model draft the fields edit, the sanctioned
     * exception: SaveLeavePolicy validates every value against the leave
     * types' credit rules before anything is written.
     *
     * @var array<string, mixed>
     */
    public array $policyForm = [];

    /** The date the new version takes effect ('Y-m-d'); the command validates it. */
    public string $policyFrom = '';

    public string $policyReason = '';

    /**
     * The preset the dialog copies its fields from when chosen. A draft the
     * select writes: `updatedPolicyPreset()` re-reads the preset through the
     * scoped query, so an unknown value copies nothing.
     */
    public string $policyPreset = '';

    public ?string $policyError = null;

    public function mount(): void
    {
        $this->sortKey = 'employee';
    }

    /** @return list<string> */
    protected function sortableKeys(): array
    {
        return ['employee'];
    }

    /** The trait's hook resets the default page name; this table has its own. */
    public function updatedPerPage(mixed $value): void
    {
        $size = (int) $value;
        $this->perPage = in_array($size, TableDefaults::PAGE_SIZE_OPTIONS, true) ? $size : TableDefaults::DEFAULT_PAGE_SIZE;
        $this->resetPage(self::PAGE_NAME);
    }

    public function updatedSearch(): void
    {
        $this->resetPage(self::PAGE_NAME);
    }

    /* ---- Versions ---- */

    public function openVersions(string $employeeId): void
    {
        $access = app(Access::class);
        if ($employeeId === '' || app(OperationalDirectory::class)->find($access, $employeeId) === null) {
            return;
        }
        $this->policyEmployeeId = $employeeId;
        $this->versionsOpen = true;
    }

    /** Stages a draft for approval and opens the confirm dialog with what the approval will post. */
    public function askApprove(string $id): void
    {
        $this->stageVersion($id, 'approve');
    }

    /** Stages a draft for discarding and opens the confirm dialog. */
    public function askDiscard(string $id): void
    {
        $this->stageVersion($id, 'discard');
    }

    private function stageVersion(string $id, string $action): void
    {
        $access = app(Access::class);
        if (! $access->mayReadAllData(ModuleCode::LEAVES)) {
            Flux::toast(variant: 'danger', text: 'Your role cannot manage organization-wide data.');

            return;
        }
        $version = app(LeavePolicyRecords::class)->find($access, $id);
        if ($version === null || $version->status !== 'DRAFT') {
            Flux::toast(variant: 'danger', text: 'That version is no longer a draft.');

            return;
        }
        $this->confirmVersionId = $id;
        $this->confirmAction = $action;
        $this->confirmFrom = $version->effective_from->format('d M Y');
        $this->confirmMonths = $action === 'approve' ? app(PostLeaveEarnings::class)->previewFor($access, $id, ManilaClock::todayKey()) : [];
        $this->versionConfirmOpen = true;
    }

    /** The dialog's one button: approves or discards the STAGED version, never one named by the click. */
    public function confirmVersion(): void
    {
        $id = $this->confirmVersionId;
        $action = $this->confirmAction;
        $this->versionConfirmOpen = false;
        $this->confirmVersionId = null;
        $this->confirmAction = '';
        $this->confirmMonths = [];
        if ($id === null) {
            return;
        }
        if ($action === 'approve') {
            $this->approveVersion($id);
        } elseif ($action === 'discard') {
            $this->discardVersion($id);
        }
    }

    public function approveVersion(string $id): void
    {
        $access = app(Access::class);
        if (! $access->mayReadAllData(ModuleCode::LEAVES)) {
            Flux::toast(variant: 'danger', text: 'Your role cannot manage organization-wide data.');

            return;
        }
        try {
            $message = app(ApproveLeavePolicy::class)->handle($access, $id);
        } catch (QueryException) {
            Flux::toast(variant: 'danger', text: 'The version could not be approved. Try again.');

            return;
        } catch (RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }
        Flux::toast(variant: 'success', text: $message);
    }

    public function discardVersion(string $id): void
    {
        $access = app(Access::class);
        if (! $access->mayReadAllData(ModuleCode::LEAVES)) {
            Flux::toast(variant: 'danger', text: 'Your role cannot manage organization-wide data.');

            return;
        }
        try {
            $message = app(DiscardLeavePolicy::class)->handle($access, $id);
        } catch (QueryException) {
            Flux::toast(variant: 'danger', text: 'The draft could not be discarded. Try again.');

            return;
        } catch (RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }
        Flux::toast(variant: 'success', text: $message);
    }

    /* ---- The policy dialog ---- */

    /**
     * Opens the policy dialog for an employee: blank for a new version from
     * today, or on an existing draft's fields to edit it in place. The
     * grant is re-checked and both ids re-resolved through scoped reads.
     */
    public function openPolicy(string $employeeId, ?string $versionId = null): void
    {
        $access = app(Access::class);
        if (! $access->mayReadAllData(ModuleCode::LEAVES) || ! LeavePolicyRecords::ready()) {
            return;
        }
        if ($employeeId === '' || app(OperationalDirectory::class)->find($access, $employeeId) === null) {
            return;
        }
        $version = $versionId === null ? null : app(LeavePolicyRecords::class)->find($access, $versionId);
        if ($versionId !== null && ($version === null || $version->employee_id !== $employeeId || $version->status !== 'DRAFT')) {
            return;
        }
        $this->policyEmployeeId = $employeeId;
        $this->editingVersionId = $version?->id;
        $this->policyError = null;
        $this->policyPreset = '';
        $this->policyFrom = $version === null ? ManilaClock::todayKey() : $version->effective_from->format('Y-m-d');
        $this->policyReason = $version === null ? '' : $version->reason;
        $this->policyForm = LeavePolicyForm::draft($version === null ? [] : $version->params, '', app(LeaveTypeCreditRecords::class)->list($access));
        $this->policyOpen = true;
    }

    /** Copies the chosen preset's rules into the fields; the effective date and reason stay. */
    public function updatedPolicyPreset(string $value): void
    {
        $access = app(Access::class);
        $preset = $value === '' ? null : app(LeavePresetRecords::class)->find($access, $value);
        if ($preset === null) {
            return;
        }
        $this->policyForm = LeavePolicyForm::draft($preset->params, '', app(LeaveTypeCreditRecords::class)->list($access));
    }

    public function addPolicyRow(string $token): void
    {
        if (! app(Access::class)->mayReadAllData(ModuleCode::LEAVES)) {
            return;
        }
        $this->policyForm = LeavePolicyForm::addRow($this->policyForm, $token);
    }

    public function removePolicyRow(string $token, int $index): void
    {
        if (! app(Access::class)->mayReadAllData(ModuleCode::LEAVES)) {
            return;
        }
        $this->policyForm = LeavePolicyForm::removeRow($this->policyForm, $token, $index);
    }

    public function savePolicy(): void
    {
        $access = app(Access::class);
        if (! $access->mayReadAllData(ModuleCode::LEAVES)) {
            $this->policyError = 'Your role cannot manage organization-wide data.';

            return;
        }
        try {
            app(SaveLeavePolicy::class)->handle($access, $this->policyEmployeeId, $this->policyFrom, LeavePolicyForm::params($this->policyForm), $this->policyReason);
        } catch (QueryException) {
            $this->policyError = 'The policy could not be saved. Try again.';

            return;
        } catch (RuntimeException $e) {
            $this->policyError = $e->getMessage();

            return;
        }
        $this->policyOpen = false;
        $this->editingVersionId = null;
        $this->versionsOpen = true;
        Flux::toast(variant: 'success', text: 'Draft saved. Approve it to take effect.');
    }

    /* ---- Posting ---- */

    /** Runs the month-end earning now, as the actor, for everyone with a policy. */
    public function postEarningsNow(): void
    {
        $access = app(Access::class);
        if (! $access->mayReadAllData(ModuleCode::LEAVES)) {
            Flux::toast(variant: 'danger', text: 'Your role cannot manage organization-wide data.');

            return;
        }
        try {
            $result = app(PostLeaveEarnings::class)->run(ManilaClock::todayKey(), $access);
        } catch (QueryException|RuntimeException) {
            Flux::toast(variant: 'danger', text: 'The earning could not be posted. Try again.');

            return;
        }
        Flux::toast(variant: 'success', text: $result['posted'] === 0
            ? 'Nothing to post: every month that has ended is already posted.'
            : sprintf('Posted %d month%s for %d employee%s.', $result['posted'], $result['posted'] === 1 ? '' : 's', $result['employees'], $result['employees'] === 1 ? '' : 's'));
    }

    public function render(): View
    {
        $access = app(Access::class);
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
        $records = app(LeavePolicyRecords::class);
        $canManage = $access->mayReadAllData(ModuleCode::LEAVES);
        $dialogEmployee = $this->policyEmployeeId === '' ? null : app(OperationalDirectory::class)->find($access, $this->policyEmployeeId);
        $types = app(LeaveTypeCreditRecords::class)->list($access);

        return view('livewire.leaves.leave-policies', [
            'rows' => $rows,
            'ready' => LeavePolicyRecords::ready(),
            'inForce' => $records->inForce($access, $pageIds),
            'drafts' => $records->drafts($access, $pageIds),
            'canManage' => $canManage,
            'dialogEmployee' => $dialogEmployee,
            'versions' => $dialogEmployee === null || ! $this->versionsOpen ? collect() : $records->versions($access, $dialogEmployee->id),
            'postings' => $dialogEmployee === null || ! $this->versionsOpen ? collect() : $records->postings($access, $dialogEmployee->id, 24),
            'charges' => $dialogEmployee === null || ! $this->versionsOpen ? collect() : $records->charges($access, $dialogEmployee->id, 24),
            'presets' => $canManage ? app(LeavePresetRecords::class)->list($access) : collect(),
            'types' => $types,
            'earningTypes' => $types->filter(static fn (LeaveTypeView $t): bool => (bool) $t->earns_credits)->values(),
            'userId' => $access->userId,
        ]);
    }
}
