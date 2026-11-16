<?php

declare(strict_types=1);

namespace App\Livewire\Projects;

use App\Modules\WorkManagement\Application\Commands\SaveClient;
use App\Modules\WorkManagement\Application\Queries\ProjectPortfolios;
use App\Support\Access;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class NewClient extends Component
{
    #[Locked]
    public string $portfolio = 'ALL';

    public bool $editorOpen = false;

    public string $name = '';

    public string $description = '';

    /** Editable destination, not #[Locked]; SaveClient checks current contribution rights. */
    public string $draftPortfolioId = 'NONE';

    public function mount(): void
    {
        if (request()->routeIs('projects.clients') && request()->boolean('new')) {
            $this->open();
        }
    }

    #[On('new-project-client')]
    public function open(): void
    {
        $actor = app(Access::class);
        $selected = in_array($this->portfolio, ['ALL', 'NONE'], true) ? null : app(ProjectPortfolios::class)->portfolio($actor, $this->portfolio);
        abort_if(! in_array($this->portfolio, ['ALL', 'NONE'], true) && $selected === null, 404);
        abort_if($selected?->archived_at !== null, 403, 'Restore this portfolio before adding clients.');
        $this->resetValidation();
        $this->name = '';
        $this->description = '';
        $this->draftPortfolioId = $selected->id ?? 'NONE';
        $this->editorOpen = true;
    }

    public function save(): void
    {
        try {
            $client = app(SaveClient::class)->handle(app(Access::class), null, $this->name, $this->draftPortfolioId === 'NONE' ? null : $this->draftPortfolioId, $this->description);
        } catch (QueryException $e) {
            report($e);
            $this->addError('name', 'The client could not be saved. Please try again.');

            return;
        }
        $this->editorOpen = false;
        Flux::toast($client->name.' created.');
        $this->redirectRoute('projects.index', ['client' => $client->id, 'portfolio' => $client->portfolio_id ?? 'ALL'], navigate: true);
    }

    public function render(): View
    {
        return view('livewire.projects.new-client', ['destinations' => $this->editorOpen ? app(ProjectPortfolios::class)->options(app(Access::class), activeOnly: true) : collect()]);
    }
}
