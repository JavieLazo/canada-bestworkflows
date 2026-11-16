<?php

declare(strict_types=1);

namespace App\Livewire\Projects;

use App\Modules\WorkManagement\Application\Queries\ProjectCapacity;
use App\Modules\WorkManagement\Domain\Projects\CapacityWindow;
use App\Support\Access;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class CapacityOverview extends Component
{
    #[Locked]
    public ?string $projectId = null;

    #[Locked]
    public string $portfolio = 'ALL';

    #[Locked]
    public string $client = 'ALL';

    public function render(): View
    {
        $queries = new ProjectCapacity($this->portfolio, $this->client);

        return view('livewire.projects.capacity-overview', [
            'capacityTotals' => $queries->totals(Access::forActor(auth()->user()), $this->projectId, false, CapacityWindow::from('')),
            'capacityUrl' => $this->projectId === null
                ? route('projects.index', ['section' => 'capacity', 'portfolio' => $this->portfolio, 'client' => $this->client])
                : route('projects.show', ['project' => $this->projectId, 'view' => 'capacity']),
        ]);
    }
}
