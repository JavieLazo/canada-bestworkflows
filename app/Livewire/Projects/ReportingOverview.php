<?php

declare(strict_types=1);

namespace App\Livewire\Projects;

use App\Livewire\Concerns\WithTableFilters;
use App\Modules\WorkManagement\Application\Queries\ProjectFinancials;
use App\Modules\WorkManagement\Application\Queries\ProjectFundingSummary;
use App\Modules\WorkManagement\Application\Queries\ProjectReports;
use App\Modules\WorkManagement\Application\Queries\ProjectResults;
use App\Modules\WorkManagement\Domain\Projects\FinanceAmount;
use App\Support\Access;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ReportingOverview extends Component
{
    use WithTableFilters;

    #[Locked]
    public ?string $projectId = null;

    #[Locked]
    public string $portfolio = 'ALL';

    #[Locked]
    public string $client = 'ALL';

    public bool $sourcesOpen = false;

    #[Locked]
    public string $sourceKind = 'results';

    #[Locked]
    public string $sourceCurrency = 'CAD';

    public function openSource(string $kind, string $currency = 'CAD'): void
    {
        abort_unless(in_array($kind, ['results', 'reports', 'funding'], true) && isset(FinanceAmount::CURRENCIES[$currency]), 404);
        if ($this->projectId !== null) {
            $this->redirectRoute('projects.show', ['project' => $this->projectId, 'view' => $kind === 'funding' ? 'finance' : 'reporting'], navigate: true);

            return;
        }
        $this->sourceKind = $kind;
        $this->sourceCurrency = $currency;
        $this->resetPage($kind === 'funding' ? 'financeSourcePage' : 'reportingSourcePage');
        $this->sourcesOpen = true;
    }

    public function render(): View
    {
        $actor = app(Access::class);

        return view('livewire.projects.reporting-overview', [
            'results' => app(ProjectResults::class)->totals($actor, $this->projectId, $this->portfolio, $this->client),
            'reports' => app(ProjectReports::class)->totals($actor, $this->projectId, $this->portfolio, $this->client),
            'funding' => app(ProjectFundingSummary::class)->totals($actor, $this->projectId, $this->portfolio, $this->client),
            'sourceProjects' => ! $this->sourcesOpen ? null : ($this->sourceKind === 'funding'
                ? $this->acceptPage(app(ProjectFinancials::class)->sourceProjects($actor, $this->sourceCurrency, $this->portfolio, $this->client, (int) $this->getPage('financeSourcePage')), 'financeSourcePage')
                : $this->acceptPage(app(ProjectReports::class)->sourceProjects($actor, $this->sourceKind, $this->portfolio, $this->client, (int) $this->getPage('reportingSourcePage')), 'reportingSourcePage')),
        ]);
    }
}
