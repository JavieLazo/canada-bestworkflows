<?php

declare(strict_types=1);

namespace App\Livewire\Projects;

use App\Livewire\Kit\EmployeeChecklist;
use App\Modules\Workforce\Contracts\DirectoryEmployee;
use App\Modules\WorkManagement\Application\Queries\ClientPeople;
use App\Support\Access;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;

class ClientEmployeeChecklist extends EmployeeChecklist
{
    #[Locked]
    public string $clientId;

    #[Locked]
    public ?string $taskId = null;

    /** @param list<string>|null $ids
     * @param  array{position?: string, active?: string, employmentStatus?: string, sort?: string}  $filters
     * @return Collection<int, DirectoryEmployee>
     */
    protected function directoryEmployees(?array $ids = null, string $search = '', array $filters = [], ?Access $actor = null): Collection
    {
        // shortcut: this task chooser supports name and ID search; use the operational directory for reports.
        return app(ClientPeople::class)->employees($actor ?? app(Access::class), $this->clientId, $ids, $search, $this->taskId);
    }
}
