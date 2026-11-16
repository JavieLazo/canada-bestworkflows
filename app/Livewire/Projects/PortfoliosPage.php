<?php

declare(strict_types=1);

namespace App\Livewire\Projects;

use App\Livewire\Concerns\WithSorting;
use App\Livewire\Concerns\WithTableFilters;
use App\Livewire\Projects\Support\WithPeopleList;
use App\Modules\IdentityAccess\Contracts\AccountNames;
use App\Modules\WorkManagement\Application\Commands\ArchivePortfolio;
use App\Modules\WorkManagement\Application\Commands\SavePortfolio;
use App\Modules\WorkManagement\Application\Commands\SetPortfolioMember;
use App\Modules\WorkManagement\Application\Queries\ProjectPortfolios;
use App\Modules\WorkManagement\Contracts\Views\PortfolioView;
use App\Modules\WorkManagement\Contracts\Views\WorkMemberView;
use App\Support\Access;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Portfolios')]
class PortfoliosPage extends Component
{
    use WithPeopleList;
    use WithSorting;
    use WithTableFilters;

    #[Locked]
    public ?string $portfolioId = null;

    #[Locked]
    public bool $membersOnly = false;

    #[Locked]
    public bool $toolsOnly = false;

    #[Url(except: false)]
    public bool $archived = false;

    #[Url(as: 'layout', except: 'cards')]
    public string $collectionView = 'cards';

    #[Url(except: 'ALL')]
    public string $ownership = 'ALL';

    #[Url(except: 'ALL')]
    public string $contents = 'ALL';

    /** @return list<string> */
    protected function sortableKeys(): array
    {
        return ['name', 'created', 'members', 'clients', 'projects'];
    }

    public function updatedOwnership(): void
    {
        $this->resetPage();
    }

    public function updatedContents(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->archived = false;
        $this->ownership = $this->contents = 'ALL';
        $this->resetPage();
    }

    public string $name = '';

    public string $description = '';

    /** Member picker draft, revalidated by SetPortfolioMember; not #[Locked]. */
    public string $member = '';

    public bool $editorOpen = false;

    public bool $membersOpen = false;

    public function mount(?string $membersFor = null, ?string $toolsFor = null): void
    {
        $this->sortKey = 'name';
        if ($toolsFor !== null) {
            $portfolio = app(ProjectPortfolios::class)->portfolio($this->access(), $toolsFor);
            abort_unless($portfolio !== null, 403);
            $this->portfolioId = $portfolio->id;
            $this->toolsOnly = true;
        }
        if ($membersFor !== null) {
            $this->openPeople($membersFor);
            $this->membersOnly = true;
        }
        if (! $this->toolsOnly && ! $this->membersOnly && request()->boolean('new')) {
            $this->openCreate();
        }
    }

    #[On('open-current-portfolio-members')]
    public function openCurrentPeople(): void
    {
        if ($this->toolsOnly && $this->portfolioId !== null) {
            $this->openPeople($this->portfolioId);
        }
    }

    #[On('edit-current-portfolio')]
    public function editCurrentPortfolio(): void
    {
        if ($this->toolsOnly && $this->portfolioId !== null) {
            $this->openEdit($this->portfolioId);
        }
    }

    public function updatedArchived(): void
    {
        $this->resetPage();
    }

    public function openCreate(): void
    {
        $this->resetValidation();
        $this->portfolioId = null;
        $this->name = $this->description = '';
        $this->membersOpen = false;
        $this->editorOpen = true;
    }

    public function openEdit(string $portfolioId): void
    {
        $portfolio = app(ProjectPortfolios::class)->portfolio($this->access(), $portfolioId);
        abort_unless($portfolio !== null && $portfolio->can_manage, 403);
        $this->resetValidation();
        $this->portfolioId = $portfolio->id;
        $this->name = $portfolio->name;
        $this->description = $portfolio->description ?? '';
        $this->membersOpen = false;
        $this->editorOpen = true;
    }

    public function savePortfolio(): void
    {
        try {
            $portfolio = app(SavePortfolio::class)->handle($this->access(), $this->portfolioId, $this->name, $this->description);
        } catch (QueryException $error) {
            report($error);
            Flux::toast(variant: 'danger', text: 'The portfolio could not be saved. Please try again.');

            return;
        }
        $this->editorOpen = false;
        if (! $this->toolsOnly) {
            $this->resetPage();
        }
        $this->dispatch('work-context-updated');
        Flux::toast(variant: 'success', text: $portfolio->name.' saved.');
    }

    public function archivePortfolio(string $portfolioId, bool $archive): void
    {
        try {
            $changed = app(ArchivePortfolio::class)->handle($this->access(), $portfolioId, $archive);
        } catch (QueryException $error) {
            report($error);
            Flux::toast(variant: 'danger', text: 'The portfolio could not be updated. Please try again.');

            return;
        }
        if ($changed) {
            Flux::toast(variant: 'success', text: $archive ? 'Portfolio archived. Its work is kept.' : 'Portfolio restored.');
        }
    }

    public function openPeople(string $portfolioId): void
    {
        $portfolio = app(ProjectPortfolios::class)->portfolio($this->access(), $portfolioId);
        abort_if($portfolio === null, 404);
        $this->resetValidation();
        $this->resetPeople();
        $this->portfolioId = $portfolio->id;
        $this->member = '';
        $this->editorOpen = false;
        $this->membersOpen = true;
    }

    public function addMember(): void
    {
        $actor = $this->access();
        abort_if($this->portfolioId === null, 404);
        $this->validate(['member' => ['required', 'integer', 'min:1']]);
        $this->setMember($actor, (int) $this->member, true);
    }

    public function removeMember(int $userId): void
    {
        $this->setMember($this->access(), $userId, false);
    }

    public function render(): View
    {
        $actor = $this->access();
        $queries = app(ProjectPortfolios::class);
        $this->collectionView = $this->collectionView === 'list' ? 'list' : 'cards';
        if ($this->membersOnly || $this->toolsOnly) {
            $selected = $this->portfolioId === null ? null : $queries->portfolio($actor, $this->portfolioId);

            return view('livewire.projects.portfolios-page', [
                'selectedPortfolio' => $selected,
                'owners' => $selected === null ? collect() : app(AccountNames::class)->list($actor, [$selected->owner_id])->keyBy('id'),
                'members' => (! $this->membersOnly && ! $this->membersOpen) || $selected === null ? null : $this->people($queries, $actor, $selected->id),
                'candidates' => ($this->membersOnly || $this->membersOpen) && $selected?->can_manage ? $queries->candidates($actor, $selected->id) : collect(),
            ]);
        }
        $rows = $this->acceptPage($queries->page($actor, $this->search, $this->archived, (int) $this->getPage(), $this->perPage, $this->sortKey, $this->sortDir, $this->ownership, $this->contents));
        $selected = $this->membersOpen && $this->portfolioId !== null ? $queries->portfolio($actor, $this->portfolioId) : null;
        $ownerIds = $rows->getCollection()->map(static fn (PortfolioView $row): int => $row->owner_id)->all();
        if ($selected !== null) {
            $ownerIds[] = $selected->owner_id;
        }

        return view('livewire.projects.portfolios-page', [
            'managementTab' => 'portfolios',
            'rows' => $rows,
            'counts' => $queries->counts($actor, array_values($rows->getCollection()->pluck('id')->all())),
            'owners' => $ownerIds === [] ? collect() : app(AccountNames::class)->list($actor, array_values(array_unique($ownerIds)))->keyBy('id'),
            'selectedPortfolio' => $selected,
            'members' => $selected === null ? null : $this->people($queries, $actor, $selected->id),
            'candidates' => $selected?->can_manage ? $queries->candidates($actor, $selected->id) : collect(),
        ]);
    }

    private function setMember(Access $actor, int $userId, bool $include): void
    {
        abort_if($this->portfolioId === null, 404);
        try {
            $changed = app(SetPortfolioMember::class)->handle($actor, $this->portfolioId, $userId, $include);
        } catch (QueryException $error) {
            report($error);
            Flux::toast(variant: 'danger', text: 'Portfolio access could not be saved. Please try again.');

            return;
        }
        $this->member = '';
        if ($changed) {
            Flux::toast(variant: 'success', text: $include ? 'Portfolio access added.' : 'Portfolio access removed.');
        }
    }

    /** @return LengthAwarePaginator<int, WorkMemberView> */
    private function people(ProjectPortfolios $queries, Access $actor, string $id): LengthAwarePaginator
    {
        return $this->acceptPage($queries->people($actor, $id, (int) $this->getPage('peoplePage'), $this->peoplePerPage, $this->peopleSearch, $this->peopleAccess, $this->peopleSort, $this->peopleDirection), 'peoplePage');
    }

    private function access(): Access
    {
        return Access::forActor(auth()->user());
    }
}
