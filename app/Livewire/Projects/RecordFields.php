<?php

declare(strict_types=1);

namespace App\Livewire\Projects;

use App\Modules\WorkManagement\Application\Commands\SaveRecordValues;
use App\Modules\WorkManagement\Application\Queries\ProjectRecordFiles;
use App\Modules\WorkManagement\Application\Queries\ProjectReports;
use App\Support\Access;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

class RecordFields extends Component
{
    #[Locked]
    public string $projectId;

    #[Locked]
    public string $entity;

    #[Locked]
    public string $recordId;

    #[Locked]
    public string $signature = '';

    /** Editable answers keyed by field; not #[Locked]. SaveRecordValues validates all keys, types and current scope. */
    /** @var array<string, mixed> */
    public array $answers = [];

    public bool $editing = false;

    public function edit(): void
    {
        $data = app(ProjectReports::class)->answers(app(Access::class), $this->projectId, $this->entity, $this->recordId, true);
        $this->answers = $data['values'];
        $this->signature = $data['signature'];
        $this->resetValidation();
        $this->editing = true;
    }

    public function save(): void
    {
        try {
            app(SaveRecordValues::class)->handle(app(Access::class), $this->projectId, $this->entity, $this->recordId, $this->signature, $this->answers);
        } catch (ValidationException $e) {
            $this->addError('fields', implode(' ', array_merge(...array_values($e->errors()))));

            return;
        }
        $this->editing = false;
        $this->dispatch('report-answers-changed');
        Flux::toast('Fields saved.');
    }

    public function render(): View
    {
        $query = app(ProjectReports::class);
        $data = $query->answers(app(Access::class), $this->projectId, $this->entity, $this->recordId);

        return view('livewire.projects.record-fields', $data + ['editable' => $query->canEditRecord(app(Access::class), $this->projectId, $this->entity, $this->recordId), 'evidenceChoices' => app(ProjectRecordFiles::class)->choices(app(Access::class), $this->projectId)]);
    }
}
