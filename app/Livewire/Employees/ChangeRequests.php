<?php

declare(strict_types=1);

namespace App\Livewire\Employees;

use App\Domain\Enums\ModuleCode;
use App\Livewire\Concerns\WithTableFilters;
use App\Modules\Workforce\Application\Commands\ReviewRecordChange;
use App\Modules\Workforce\Application\Commands\WithdrawRecordChange;
use App\Modules\Workforce\Application\PdsLists;
use App\Modules\Workforce\Application\Queries\RecordChangeRequests;
use App\Modules\Workforce\Contracts\Views\RecordChangeRequestView;
use App\Support\Access;
use Flux\Flux;
use Illuminate\Database\QueryException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;
use RuntimeException;

/** Managers review proposals in one queue; My Profile shares the own-employee request history. */
#[Title('Record change requests')]
class ChangeRequests extends Component
{
    use WithTableFilters;

    #[Locked]
    public bool $own = false;

    #[Locked]
    public ?string $openId = null;

    public bool $detailOpen = false;

    public string $status = 'PENDING';

    public string $reviewNote = '';

    public function mount(bool $own = false): void
    {
        $this->own = $own;
        $this->authorizeView(app(Access::class));
        if ($own) {
            $this->status = 'ALL';
        }
        $id = request()->query('request');
        if (is_string($id) && $id !== '') {
            $this->show($id);
        }
    }

    private function authorizeView(Access $access): void
    {
        abort_unless($this->own ? $access->hasModule(ModuleCode::PROFILE, ModuleCode::EMPLOYEES) : $access->mayReadAllData(ModuleCode::EMPLOYEES), 403);
    }

    public function updatedStatus(string $status): void
    {
        $this->status = in_array($status, ['ALL', 'PENDING', 'APPROVED', 'REJECTED', 'WITHDRAWN'], true) ? $status : 'PENDING';
        $this->resetPage();
    }

    public function show(string $id): void
    {
        $access = app(Access::class);
        $this->authorizeView($access);
        $row = app(RecordChangeRequests::class)->find($access, $id, $this->own);
        if ($row === null) {
            Flux::toast(text: 'That request is unavailable.', variant: 'danger');

            return;
        }
        $this->openId = $id;
        $this->reviewNote = '';
        $this->detailOpen = true;
    }

    public function review(bool $approve): void
    {
        $access = app(Access::class);
        $this->authorizeView($access);
        abort_if($this->own, 403);
        if ($this->openId === null) {
            return;
        }
        try {
            app(ReviewRecordChange::class)->handle($access, $this->openId, $approve, $this->reviewNote);
        } catch (QueryException $e) {
            report($e);
            Flux::toast(text: 'The decision could not be saved. Try again.', variant: 'danger');

            return;
        } catch (RuntimeException $e) {
            Flux::toast(text: $e->getMessage(), variant: 'danger');

            return;
        }
        $this->resetPage();
        Flux::toast(text: $approve ? 'Approved. The official employee record is updated.' : 'Request rejected. The official record is unchanged.', variant: 'success');
    }

    public function withdraw(): void
    {
        $access = app(Access::class);
        $this->authorizeView($access);
        abort_unless($this->own, 403);
        if ($this->openId === null) {
            return;
        }
        try {
            app(WithdrawRecordChange::class)->handle($access, $this->openId);
        } catch (QueryException $e) {
            report($e);
            Flux::toast(text: 'The request could not be withdrawn. Try again.', variant: 'danger');

            return;
        } catch (RuntimeException $e) {
            Flux::toast(text: $e->getMessage(), variant: 'danger');

            return;
        }
        $this->resetPage();
        Flux::toast(text: 'Request withdrawn. Your official record is unchanged.', variant: 'success');
    }

    #[On('record-change-submitted')]
    public function refreshRequests(): void
    {
        $this->resetPage();
    }

    public static function sectionLabel(RecordChangeRequestView $row): string
    {
        if ($row->kind === 'PORTRAIT') {
            return 'Portrait';
        }
        if ($row->kind === 'DOCUMENT') {
            return 'Documents';
        }
        $section = (string) $row->section;

        return $row->kind === 'PDS' && in_array($section, PdsLists::KEYS, true)
            ? PdsLists::spec($section)['title']
            : (EmployeeFields::section($section)['title'] ?? ucfirst(str_replace('_', ' ', $section)));
    }

    public function render(): View
    {
        $access = app(Access::class);
        $this->authorizeView($access);
        $query = app(RecordChangeRequests::class);
        $rows = $query->page($access, $this->own, $this->status, (int) $this->getPage(), $this->perPage);
        if ($rows->currentPage() > $rows->lastPage()) {
            $rows = $query->page($access, $this->own, $this->status, $rows->lastPage(), $this->perPage);
        }
        $rows = $this->acceptPage($rows);

        return view('livewire.employees.change-requests', [
            'rows' => $rows,
            'open' => $this->openId !== null ? $query->find($access, $this->openId, $this->own) : null,
        ]);
    }
}
