<?php

declare(strict_types=1);

namespace App\Livewire\Projects;

use App\Modules\WorkManagement\Application\Commands\AttachRecordEvidence;
use App\Modules\WorkManagement\Application\Commands\RemoveRecordEvidence;
use App\Modules\WorkManagement\Application\Queries\ProjectRecordFiles;
use App\Support\Access;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

class RecordEvidencePanel extends Component
{
    use WithFileUploads;

    #[Locked]
    public string $projectId;

    #[Locked]
    public string $entity;

    #[Locked]
    public string $recordId;

    /** @var list<TemporaryUploadedFile> */
    public array $uploads = [];

    public function attach(): void
    {
        $this->validate(['uploads' => 'required|array|min:1|max:10', 'uploads.*' => 'required|file|max:25600']);
        foreach ($this->uploads as $file) {
            app(AttachRecordEvidence::class)->handle(app(Access::class), $this->projectId, $this->entity, $this->recordId, $file);
        }
        $this->uploads = [];
        $this->dispatch('record-evidence-changed');
        Flux::toast('Evidence attached.');
    }

    public function remove(string $id): void
    {
        $files = app(ProjectRecordFiles::class)->forRecord(app(Access::class), $this->projectId, $this->entity, $this->recordId);
        abort_unless($files->contains('id', $id), 404);
        app(RemoveRecordEvidence::class)->handle(app(Access::class), $this->projectId, $id);
        $this->dispatch('record-evidence-changed');
        Flux::toast('Evidence removed from the current record. Submitted copies are retained.');
    }

    public function render(): View
    {
        $query = app(ProjectRecordFiles::class);
        $files = $query->forRecord(app(Access::class), $this->projectId, $this->entity, $this->recordId);

        return view('livewire.projects.record-evidence-panel', ['files' => $files, 'editable' => $query->canEdit(app(Access::class), $this->projectId, $this->entity, $this->recordId)]);
    }
}
