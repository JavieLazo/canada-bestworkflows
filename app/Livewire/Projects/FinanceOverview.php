<?php

declare(strict_types=1);

namespace App\Livewire\Projects;

use App\Livewire\Concerns\WithTableFilters;
use App\Modules\WorkManagement\Application\Queries\ProjectFinancials;
use App\Modules\WorkManagement\Domain\Projects\FinanceAmount;
use App\Support\Access;
use App\Support\ManilaClock;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class FinanceOverview extends Component
{
    use WithTableFilters;

    #[Locked]
    public ?string $projectId = null;

    /** A scoped filter, not an access grant. */
    public string $portfolio = 'ALL';

    /** A scoped filter, not an access grant. */
    public string $client = 'ALL';

    public bool $sourcesOpen = false;

    #[Locked]
    public string $sourceCurrency = 'PHP';

    public function openSource(string $currency): void
    {
        abort_unless(isset(FinanceAmount::CURRENCIES[$currency]), 404);
        if ($this->projectId !== null) {
            $this->redirectRoute('projects.show', ['project' => $this->projectId, 'view' => 'finance'], navigate: true);

            return;
        }
        $this->sourceCurrency = $currency;
        $this->resetPage('financeSourcePage');
        $this->sourcesOpen = true;
    }

    public function render(): View
    {
        return view('livewire.projects.finance-overview', [
            'financialTotals' => app(ProjectFinancials::class)->totals(Access::forActor(auth()->user()), ManilaClock::todayKey(), $this->projectId, $this->portfolio, $this->client),
            'sourceProjects' => $this->sourcesOpen ? $this->acceptPage(app(ProjectFinancials::class)->sourceProjects(app(Access::class), $this->sourceCurrency, $this->portfolio, $this->client, (int) $this->getPage('financeSourcePage')), 'financeSourcePage') : null,
        ]);
    }
}
