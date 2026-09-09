<?php

declare(strict_types=1);

namespace App\Livewire\Records;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Livewire\Concerns\WithSorting;
use App\Livewire\Concerns\WithTableFilters;
use App\Livewire\Records\Support\Hris;
use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use App\Models\EmployeeProfile;
use App\Models\EmploymentStatusOption;
use App\Models\LeaveTravelRequest;
use App\Models\LeaveType;
use App\Models\PayrollDeduction;
use App\Models\Position;
use App\Support\Access;
use App\Support\AuditService;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One managed option list — the port of
 * src/app/(protected)/records/reference-list.tsx, instantiated per list by
 * route instead of hand-copied per page. Positions, document types, employment
 * statuses, leave types and payroll deductions differ only in their table,
 * prefix and wording, so they share this screen (and its dialogs) verbatim.
 *
 * Permissions, exactly as the reference:
 *  - the PAGE needs RECORDS (records/layout.tsx) — route middleware;
 *  - READS are open to any session (GET /api/records/[table]);
 *  - every WRITE needs all_data + RECORDS (requireAllDataModule("RECORDS"),
 *    api/records/[table]/route.ts), re-checked inside each action;
 *  - the usage counts read employees / documents / leave requests through the
 *    same row scoping their own APIs apply, so an employee-less actor counts
 *    zero rows rather than everyone's.
 */
#[Layout('layouts.app')]
class ReferenceListPage extends Component
{
    use WithSorting;
    use WithTableFilters;

    /**
     * The five lists sharing this screen — the props each `page.tsx` passed to
     * `<ReferenceList>` (positions/page.tsx, documents/page.tsx,
     * employment/page.tsx, leave-types/page.tsx, payroll-deductions/page.tsx).
     *
     * @var array<string, array{model: class-string<Model>, table: string, prefix: string,
     *     title: string, description: string, noun: string, plural: string,
     *     nameLabel: string, showDescription: bool}>
     */
    public const array LISTS = [
        'positions' => [
            'model' => Position::class,
            'table' => 'positions',
            'prefix' => 'POS',
            'title' => 'Position',
            'description' => 'Job positions offered when hiring or editing an employee record.',
            'noun' => 'position',
            'plural' => 'positions',
            'nameLabel' => 'Name',
            'showDescription' => true,
        ],
        'documents' => [
            'model' => DocumentType::class,
            'table' => 'document_types',
            'prefix' => 'DOC',
            'title' => 'Document',
            'description' => 'Document types offered when attaching a file to an employee record.',
            'noun' => 'document type',
            'plural' => 'document types',
            'nameLabel' => 'Name',
            'showDescription' => true,
        ],
        'employment' => [
            'model' => EmploymentStatusOption::class,
            'table' => 'employment_statuses',
            'prefix' => 'EMP',
            'title' => 'Employment',
            'description' => 'Statuses offered on employee records — regular, probationary and the rest.',
            'noun' => 'employment status',
            'plural' => 'employment statuses',
            'nameLabel' => 'Name',
            'showDescription' => true,
        ],
        'leave-types' => [
            'model' => LeaveType::class,
            'table' => 'leave_types',
            'prefix' => 'LVE',
            'title' => 'Leave Settings',
            'description' => 'Leave types offered when an employee files a request.',
            'noun' => 'leave type',
            'plural' => 'leave types',
            'nameLabel' => 'Leave',
            'showDescription' => false,
        ],
        'payroll-deductions' => [
            'model' => PayrollDeduction::class,
            'table' => 'payroll_deductions',
            'prefix' => 'DED',
            'title' => 'Payroll Deductions',
            'description' => 'Deductions kept on file — statutory contributions, loans and adjustments.',
            'noun' => 'deduction',
            'plural' => 'deductions',
            'nameLabel' => 'Name',
            'showDescription' => false,
        ],
    ];

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

    /** @return array{model: class-string<Model>, table: string, prefix: string, title: string, description: string, noun: string, plural: string, nameLabel: string, showDescription: bool} */
    public function config(): array
    {
        return self::LISTS[$this->slug];
    }

    /** Open the Add dialog clean (the source keys the dialog off `editing?.id ?? "new"`). */
    public function openAdd(): void
    {
        $this->editingId = null;
        $this->formName = '';
        $this->formDescription = '';
        $this->formError = null;
        $this->dialogOpen = true;
    }

    public function startEdit(string $id): void
    {
        $row = $this->config()['model']::query()->find($id);

        if ($row === null) {
            Flux::toast(variant: 'danger', text: "That {$this->config()['noun']} no longer exists.");

            return;
        }

        $this->editingId = (string) $row->getKey();
        $this->formName = (string) $row->getAttribute('name');
        $this->formDescription = (string) ($row->getAttribute('description') ?? '');
        $this->formError = null;
        $this->dialogOpen = true;
    }

    /**
     * Save the dialog — create or rename, exactly as reference-list.tsx `save()`
     * plus the API's own validation (ReferenceInput, schemas.ts:97-103).
     */
    public function save(): void
    {
        $config = $this->config();
        $name = trim($this->formName);
        $description = trim($this->formDescription);

        // reference-list.tsx:334-338 — inline, not a toast.
        if ($name === '') {
            $this->formError = 'Name is required.';

            return;
        }

        $this->formError = null;

        if (mb_strlen($name) > 160) {
            Flux::toast(variant: 'danger', text: 'name: must be at most 160 characters.');

            return;
        }

        if (mb_strlen($description) > 2000) {
            Flux::toast(variant: 'danger', text: 'description: must be at most 2000 characters.');

            return;
        }

        $access = app(Access::class);

        // requireAllDataModule("RECORDS") — re-checked in the action itself.
        if (! $access->mayReadAllData(ModuleCode::RECORDS)) {
            Flux::toast(variant: 'danger', text: 'Your role cannot manage organization-wide data.');

            return;
        }

        $payload = ['name' => $name, 'description' => $description === '' ? null : $description];

        try {
            if ($this->editingId !== null) {
                $row = $config['model']::query()->find($this->editingId);

                if ($row === null) {
                    Flux::toast(variant: 'danger', text: "That {$config['noun']} no longer exists.");

                    return;
                }

                $row->fill($payload)->save();

                AuditService::record(
                    $access,
                    AuditEvent::REFERENCE_UPDATED,
                    $config['table'],
                    (string) $row->getKey(),
                    "{$row->getAttribute('code')} updated to \"{$name}\"",
                );

                Flux::toast(variant: 'success', text: "{$name} updated.");
            } else {
                $code = $this->nextCode();
                $row = $config['model']::query()->create($payload + ['code' => $code]);

                AuditService::record(
                    $access,
                    AuditEvent::REFERENCE_CREATED,
                    $config['table'],
                    (string) $row->getKey(),
                    "\"{$name}\" added as {$code}",
                );

                Flux::toast(variant: 'success', text: "{$name} added.");
            }
        } catch (UniqueConstraintViolationException) {
            // `code` and `name` are both unique; a collision surfaces as the
            // save failing with a toast, exactly as errorMessage(e) did.
            Flux::toast(variant: 'danger', text: "\"{$name}\" is already on the list.");

            return;
        }

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
        $config = $this->config();
        $id = $this->deletingId;
        $this->confirmOpen = false;
        $this->deletingId = null;

        if ($id === null) {
            return;
        }

        $access = app(Access::class);

        if (! $access->mayReadAllData(ModuleCode::RECORDS)) {
            Flux::toast(variant: 'danger', text: 'Your role cannot manage organization-wide data.');

            return;
        }

        $row = $config['model']::query()->find($id);

        if ($row === null) {
            Flux::toast(variant: 'danger', text: "That {$config['noun']} no longer exists.");

            return;
        }

        $code = (string) $row->getAttribute('code');
        $name = (string) $row->getAttribute('name');
        $row->delete();

        AuditService::record(
            $access,
            AuditEvent::REFERENCE_DELETED,
            $config['table'],
            $id,
            "{$code} \"{$name}\" deleted",
        );

        Flux::toast(variant: 'success', text: "{$name} deleted.");
    }

    /**
     * Next `PREFIX-000` code, read from the rows rather than counting them
     * (reference-list.tsx:60-67).
     */
    private function nextCode(): string
    {
        $config = $this->config();
        $highest = 0;

        foreach ($config['model']::query()->pluck('code') as $code) {
            if (preg_match('/^'.preg_quote($config['prefix'], '/').'-(\d+)$/i', (string) $code, $m) === 1) {
                $highest = max($highest, (int) $m[1]);
            }
        }

        return sprintf('%s-%03d', $config['prefix'], $highest + 1);
    }

    /**
     * How many records currently carry each option, keyed by normalised name.
     * Matching is by name because that is what the records store — the forms
     * select from these lists but save the name, not the id
     * (reference-list.tsx:139-158). Each source is read through the scoping its
     * own API applies.
     *
     * @return array<string, int>
     */
    private function usageCounts(): array
    {
        $access = app(Access::class);
        $counts = [];

        $bump = static function (mixed $value) use (&$counts): void {
            $key = Hris::normalizeOption($value === null ? null : (string) $value);

            if ($key !== '') {
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        };

        if ($this->slug === 'positions' || $this->slug === 'employment') {
            $column = $this->slug === 'positions' ? 'position' : 'employment_status';

            foreach ($this->visibleEmployeeValues($access, $column) as $value) {
                $bump($value);
            }
        }

        if ($this->slug === 'documents') {
            $values = EmployeeDocument::query()
                ->toEmployee($access, 'employee_id', ModuleCode::EMPLOYEES, ModuleCode::RECORDS)
                ->pluck('category');

            foreach ($values as $value) {
                $bump($value);
            }
        }

        // Deductions are not yet referenced by a payroll run, so nothing to count.

        return $counts;
    }

    /**
     * The employee directory, tiered exactly as GET /api/employees
     * (employees/route.ts:38-52): the full directory for all_data + EMPLOYEES
     * or PAYROLL, the operational directory for all_data + an operational
     * module, otherwise the actor's own row — and no rows at all for an
     * employee-less account.
     *
     * @return list<string|null>
     */
    private function visibleEmployeeValues(Access $access, string $column): array
    {
        $query = EmployeeProfile::query();

        $widened = $access->mayReadAllData(ModuleCode::EMPLOYEES, ModuleCode::PAYROLL)
            || $access->mayReadAllData(
                ModuleCode::EDTR,
                ModuleCode::ROSTER,
                ModuleCode::LEAVES,
                ModuleCode::PROJECTS,
                ModuleCode::TASKS,
            );

        if (! $widened) {
            if ($access->employeeId === null) {
                return [];
            }

            $query->whereKey($access->ownEmployeeId());
        }

        return array_values($query->pluck($column)->map(static fn ($v): ?string => $v === null ? null : (string) $v)->all());
    }

    /**
     * Leave-type usage per row name — a request stores `SICK` or
     * `EMERGENCY_LEAVE`, never the row's exact name, so it is matched through
     * the same resolver the screens display with (reference-list.tsx:160-168).
     *
     * @param  list<string>  $allNames
     * @return array<string, int>
     */
    private function leaveUsage(array $allNames): array
    {
        $access = app(Access::class);

        $requestTypes = LeaveTravelRequest::query()
            ->toEmployee($access, 'employee_id', ModuleCode::LEAVES)
            ->pluck('request_type');

        $counts = [];

        foreach ($requestTypes as $type) {
            $name = Hris::requestDescription((string) $type, $allNames);
            $counts[$name] = ($counts[$name] ?? 0) + 1;
        }

        return $counts;
    }

    public function render(): View
    {
        $config = $this->config();

        $query = $config['model']::query();

        $needle = trim($this->search);

        if ($needle !== '') {
            // `${r.code} ${r.name} ${r.description ?? ""}`.includes(needle) —
            // reference-list.tsx:110-113.
            $query->where(function ($q) use ($needle): void {
                $like = '%'.addcslashes($needle, '%_\\').'%';
                $q->where('code', 'like', $like)
                    ->orWhere('name', 'like', $like)
                    ->orWhere('description', 'like', $like);
            });
        }

        $this->applySort($query, [
            'id' => 'code',
            'name' => 'name',
        ]);

        $rows = $this->paginateClamped($query);

        // Per-row usage, resolved the way each list's records reference it.
        if ($this->slug === 'leave-types') {
            // In list order (name asc), the order listReference serves rows in —
            // it decides which row wins when two names normalise alike.
            $allNames = array_values($config['model']::query()->orderBy('name')->pluck('name')->map(static fn ($n): string => (string) $n)->all());
            $byName = $this->leaveUsage($allNames);
            $usage = [];

            foreach ($rows as $row) {
                $usage[$row->getKey()] = $byName[$row->getAttribute('name')] ?? 0;
            }
        } elseif ($this->slug === 'payroll-deductions') {
            $usage = [];

            foreach ($rows as $row) {
                $usage[$row->getKey()] = 0;
            }
        } else {
            $counts = $this->usageCounts();
            $usage = [];

            foreach ($rows as $row) {
                $usage[$row->getKey()] = $counts[Hris::normalizeOption((string) $row->getAttribute('name'))] ?? 0;
            }
        }

        $editing = $this->editingId !== null ? $config['model']::query()->find($this->editingId) : null;
        $deleting = $this->deletingId !== null ? $config['model']::query()->find($this->deletingId) : null;

        return view('livewire.records.reference-list-page', [
            'config' => $config,
            'rows' => $rows,
            'usage' => $usage,
            'editing' => $editing,
            'deleting' => $deleting,
        ])->title($config['title']);
    }
}
