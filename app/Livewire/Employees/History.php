<?php

declare(strict_types=1);

namespace App\Livewire\Employees;

use App\Domain\Enums\ModuleCode;
use App\Livewire\Concerns\WithTableFilters;
use App\Modules\Workforce\Application\Queries\EmploymentHistoryRecords;
use App\Platform\Files\PortraitUrl;
use App\Support\Access;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Employment changes have their own filtered view so the employee's current
 * record stays readable and the same selection can become a report.
 *
 * With one employee in view the changes read as that person's timeline, under
 * a header naming them and closed by their starting record; across everyone
 * they stay a plain list of changes. Which of the two is shown is decided by
 * what the owner query lets this viewer see, never by the URL alone.
 */
#[Title('Employment History')]
class History extends Component
{
    use WithTableFilters;

    #[Locked]
    public bool $own = false;

    public function mount(bool $own = false): void
    {
        $this->own = $own;
    }

    /** Not Locked: this URL filter is applied only AFTER the owner query's employee scope. */
    #[Url(as: 'employee')]
    public string $employeeId = '';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    #[Url]
    public string $field = '';

    #[On('kit-employee-picked')]
    public function employeePicked(string $name, string $id): void
    {
        if ($this->own || $name !== 'history-employee') {
            return;
        }

        $this->employeeId = $id;
        $this->resetPage();
    }

    public function updated(string $name): void
    {
        if (in_array($name, ['employeeId', 'from', 'to', 'field'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('employeeId', 'from', 'to', 'field');
        $this->resetValidation();
        $this->resetPage();
    }

    public function render(): View
    {
        $access = app(Access::class);
        abort_unless($access->hasModule($this->own ? ModuleCode::PROFILE : ModuleCode::EMPLOYEES), 403);
        if ($this->own) {
            $this->employeeId = $access->hasEmployeeLink() ? $access->ownEmployeeId() : '__unlinked__';
        }
        $records = app(EmploymentHistoryRecords::class);
        $this->resetValidation(['from', 'to', 'field']);
        $validFilters = true;
        $subject = null;
        $start = null;

        try {
            // The owner validates dates and field names for BOTH this page and
            // the export. Invalid filters must not reveal an unfiltered list.
            $rows = $this->acceptPage($records->page(
                $access,
                $this->employeeId,
                $this->from,
                $this->to,
                $this->field,
                (int) $this->getPage(),
                $this->perPage,
            ));
            // WHO the timeline belongs to comes from the owner's scoped read,
            // never from the URL: a forged or unknown id answers null and the
            // page falls back to the plain, empty list. Read after the
            // filters validated, so an invalid range shows no one's record.
            $subject = $records->subject($access, $this->employeeId);
            if ($subject !== null) {
                $start = $records->start($access, $subject->id);
            }
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->errors());
            $validFilters = false;
            $rows = new LengthAwarePaginator([], 0, max(1, $this->perPage), 1);
        }
        $filtered = $this->from !== '' || $this->to !== '' || $this->field !== '';

        return view('livewire.employees.history', [
            'rows' => $rows,
            'fields' => $records->fields(),
            'validFilters' => $validFilters,
            'filtered' => $filtered,
            'subject' => $subject,
            'subjectPhoto' => PortraitUrl::for($subject?->photo_path),
            // The starting record closes an UNFILTERED timeline only, once,
            // after the oldest change: under a field or date filter the list
            // answers "what changed", and a starting record is not a change.
            'start' => $filtered || $rows->hasMorePages() ? null : $start,
            // It is read even under a filter so the page says one is being
            // withheld ONLY for an employee who has one.
            'startWithheld' => $filtered && $start !== null,
        ]);
    }
}
