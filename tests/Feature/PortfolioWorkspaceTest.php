<?php

declare(strict_types=1);

use App\Livewire\Projects\ClientsPage;
use App\Livewire\Projects\Index;
use App\Livewire\Projects\NewClient;
use App\Livewire\Projects\PortfoliosPage;
use App\Livewire\Projects\Show;
use App\Models\ProjectClient;
use App\Models\ProjectPortfolio;
use App\Modules\WorkManagement\Application\Commands\ArchivePortfolio;
use App\Modules\WorkManagement\Application\Commands\OrganizeClient;
use App\Modules\WorkManagement\Application\Commands\SaveClient;
use App\Modules\WorkManagement\Application\Commands\SavePortfolio;
use App\Modules\WorkManagement\Application\Commands\SetPortfolioMember;
use App\Support\Access;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

require_once __DIR__.'/../Support/ClientProjectFixtures.php';

it('creates edits shares archives and restores a portfolio through the workspace', function () {
    $owner = cpaUser();
    $member = cpaUser();
    cpaLogin($owner);
    $this->get(route('projects.portfolios'))->assertOk()->assertSee('New portfolio');
    $page = Livewire::test(PortfoliosPage::class)->call('openCreate')->set('name', 'Delivery')->set('description', 'Client work')
        ->call('savePortfolio')->assertHasNoErrors()->assertSet('editorOpen', false)->assertSee('Delivery');
    $portfolio = ProjectPortfolio::query()->sole();
    $page->call('openEdit', $portfolio->id)->set('name', 'Delivery team')->call('savePortfolio')->assertHasNoErrors()
        ->call('openPeople', $portfolio->id)->set('member', (string) $member->id)->call('addMember')->assertHasNoErrors()->assertSet('member', '');
    expect($portfolio->fresh()->members()->count())->toBe(1);
    $page->set('membersOpen', false)->call('archivePortfolio', $portfolio->id, true)->assertDontSee('Open Delivery team portfolio')
        ->set('archived', true)->assertSee('Delivery team')->call('archivePortfolio', $portfolio->id, false)->set('archived', false)->assertSee('Delivery team');
    expect($portfolio->fresh()->archived_at)->toBeNull();
});

it('reports invalid portfolio drafts beside the fields and preserves the draft', function () {
    cpaLogin(cpaUser());
    Livewire::test(PortfoliosPage::class)->call('openCreate')->set('name', ' ')->call('savePortfolio')
        ->assertHasErrors('name')->assertSet('editorOpen', true)->assertSet('name', ' ');
    expect(ProjectPortfolio::query()->count())->toBe(0);
});

it('creates clients in context and explicitly organizes retained clients without moving projects', function () {
    $owner = cpaUser();
    $actor = Access::forUser($owner);
    $portfolio = app(SavePortfolio::class)->handle($actor, null, 'Delivery');
    $legacy = cpaClient($owner);
    $project = cpaProject($owner, $legacy);
    cpaLogin($owner);
    Livewire::test(NewClient::class, ['portfolio' => $portfolio->id])->call('open')
        ->assertSet('editorOpen', true)->assertSet('draftPortfolioId', $portfolio->id)
        ->set('name', 'New client')->call('save')->assertHasNoErrors();
    expect(ProjectClient::query()->where('name', 'New client')->sole()->portfolio_id)->toBe($portfolio->id);
    Livewire::test(ClientsPage::class)->call('openOrganize', $legacy->id)->set('destination', $portfolio->id)->call('organizeClient')
        ->assertHasNoErrors()->assertSet('organizeOpen', false);
    expect($project->fresh()->client_id)->toBe($legacy->id)->and(ProjectClient::query()->findOrFail($legacy->id)->portfolio_id)->toBe($portfolio->id);
});

it('refuses stale client organization and preserves the requested destination', function () {
    $owner = cpaUser();
    $actor = Access::forUser($owner);
    $first = app(SavePortfolio::class)->handle($actor, null, 'First');
    $second = app(SavePortfolio::class)->handle($actor, null, 'Second');
    $client = cpaClient($owner);
    cpaLogin($owner);
    $page = Livewire::test(ClientsPage::class)->call('openOrganize', $client->id)->set('destination', $first->id);
    app(OrganizeClient::class)->handle($actor, $client->id, $second->id, null);
    $page->call('organizeClient')->assertHasErrors('portfolio')->assertSet('organizeOpen', true)->assertSet('destination', $first->id);
    expect(ProjectClient::query()->sole()->portfolio_id)->toBe($second->id);
});

it('filters overview project lists and exports by the same portfolio and keeps drill links scoped', function () {
    $owner = cpaUser();
    $actor = Access::forUser($owner);
    $first = app(SavePortfolio::class)->handle($actor, null, 'First portfolio');
    $second = app(SavePortfolio::class)->handle($actor, null, 'Second portfolio');
    $client = app(SaveClient::class)->handle($actor, null, 'First client', $first->id);
    $project = cpaProject($owner, $client, 'First project');
    cpaTask($owner, $project, [])->update(['due_date' => '2026-10-01']);
    $other = cpaProject($owner, app(SaveClient::class)->handle($actor, null, 'Second client', $second->id), 'Second project');
    cpaTask($owner, $other, [])->update(['due_date' => '2026-10-01']);
    cpaLogin($owner);
    $page = Livewire::test(Index::class)->set('portfolio', $first->id);
    $page->assertViewHas('visibleCount', 1)
        ->assertViewHas('overdueCount', 1)->assertViewHas('overviewClients', fn ($rows): bool => $rows->pluck('id')->all() === [$client->id])
        ->assertViewHas('overview', fn ($overview): bool => $overview['attention']->pluck('id')->all() === [$project->id])
        ->assertSee('First client')->assertSee('Client delivery');
    $page->call('setQueue', 'OVERDUE')->assertSet('portfolio', $first->id)->assertSet('section', 'projects')
        ->assertSee('First project')->assertDontSee('Second project');
    $csv = $this->get(route('projects.export', ['portfolio' => $first->id]))->assertOk()->streamedContent();
    expect($csv)->toContain('First project')->not->toContain('Second project');
    Livewire::test(Show::class, ['project' => $project->id])->assertSee('First portfolio');
});

it('keeps legacy internal projects and unorganized clients accessible in the compatibility filter', function () {
    $owner = cpaUser(allData: true);
    $client = cpaClient($owner);
    $clientProject = cpaProject($owner, $client, 'Unorganized project');
    $internal = cpaProject($owner, $client, 'Internal project');
    $internal->forceFill(['client_id' => null])->save();
    $portfolio = app(SavePortfolio::class)->handle(Access::forUser($owner), null, 'Delivery');
    cpaProject($owner, app(SaveClient::class)->handle(Access::forUser($owner), null, 'Organized', $portfolio->id), 'Organized project');
    cpaLogin($owner);
    Livewire::test(Index::class)->set('portfolio', 'NONE')->set('section', 'projects')
        ->assertViewHas('visibleCount', 2)->assertSee('Unorganized project')->assertSee('Internal project')->assertDontSee('Organized project');
    expect($clientProject->fresh()->client_id)->toBe($client->id)->and($internal->fresh()->client_id)->toBeNull();
});

it('hides a revoked portfolio immediately while preserving independently granted work', function () {
    $owner = cpaUser();
    $member = cpaUser();
    $actor = Access::forUser($owner);
    $portfolio = app(SavePortfolio::class)->handle($actor, null, 'Shared portfolio');
    app(SetPortfolioMember::class)->handle($actor, $portfolio->id, $member->id, true);
    $client = app(SaveClient::class)->handle(Access::forUser($member), null, 'Own client', $portfolio->id);
    $project = cpaProject($member, $client, 'Keep my project');
    cpaLogin($member);
    $page = Livewire::test(Index::class)->set('portfolio', $portfolio->id);
    $page->assertViewHas('visibleCount', 1);
    $people = Livewire::test(PortfoliosPage::class)->call('openPeople', $portfolio->id);
    app(SetPortfolioMember::class)->handle($actor, $portfolio->id, $member->id, false);
    $page->call('$refresh')->assertViewHas('visibleCount', 0)->assertSee('This portfolio is unavailable.')->assertDontSee('Shared portfolio');
    $people->call('$refresh')->assertViewHas('selectedPortfolio', null)->assertSee('This portfolio is no longer available.');
    $page->set('portfolio', 'ALL')->set('section', 'projects')->assertSee('Keep my project');
    Livewire::test(Show::class, ['project' => $project->id])->assertDontSee('Shared portfolio');
    $csv = $this->get(route('projects.export', ['portfolio' => $portfolio->id]))->assertOk()->streamedContent();
    expect($csv)->not->toContain('Keep my project');
});

it('pages the portfolio roster and does not let its size control the project list page', function () {
    $owner = cpaUser();
    $actor = Access::forUser($owner);
    $portfolio = app(SavePortfolio::class)->handle($actor, null, 'Delivery');
    for ($i = 0; $i < 23; $i++) {
        app(SetPortfolioMember::class)->handle($actor, $portfolio->id, cpaUser()->id, true);
    }
    cpaLogin($owner);
    $page = Livewire::test(PortfoliosPage::class)->set('perPage', 20)->call('openPeople', $portfolio->id);
    $page->assertViewHas('members', fn ($members): bool => $members->count() === 20 && $members->total() === 24);
    $page->call('nextPage', 'peoplePage');
    $page->assertViewHas('members', fn ($members): bool => $members->count() === 4 && $members->currentPage() === 2)
        ->assertViewHas('rows', fn ($rows): bool => $rows->currentPage() === 1);
    $page->set('peoplePerPage', 100)->assertSet('perPage', 20)
        ->assertViewHas('members', fn ($members): bool => $members->count() === 24 && $members->currentPage() === 1);
    $page->set('perPage', 50)->assertSet('peoplePerPage', 100)
        ->assertViewHas('members', fn ($members): bool => $members->count() === 24);
    $page->set('peoplePerPage', 100000)->assertSet('peoplePerPage', 20)->assertSet('perPage', 50);
});

it('protects mounted portfolio and client association identities from hydration', function () {
    cpaLogin(cpaUser());
    expect(fn () => Livewire::test(PortfoliosPage::class)->set('portfolioId', 'tampered'))->toThrow(CannotUpdateLockedPropertyException::class);
    expect(fn () => Livewire::test(ClientsPage::class)->set('expectedPortfolioId', 'tampered'))->toThrow(CannotUpdateLockedPropertyException::class);
});

it('requires the project module at portfolio routes and rechecks ownership on actions', function () {
    $owner = cpaUser();
    $portfolio = app(SavePortfolio::class)->handle(Access::forUser($owner), null, 'Private');
    $this->get(route('projects.portfolios'))->assertRedirect(route('login'));
    cpaLogin(cpaUser(modules: ['TASKS']));
    $this->getJson(route('projects.portfolios'))->assertForbidden();
    cpaLogin(cpaUser(allData: true));
    Livewire::test(PortfoliosPage::class)->call('openEdit', $portfolio->id)->assertForbidden();
    Livewire::test(PortfoliosPage::class)->call('archivePortfolio', $portfolio->id, true)->assertForbidden();
    Livewire::test(PortfoliosPage::class)->call('openPeople', $portfolio->id)->assertNotFound();
});

it('keeps archive visible in the selected overview but refuses a late new-client submission', function () {
    $owner = cpaUser();
    $actor = Access::forUser($owner);
    $portfolio = app(SavePortfolio::class)->handle($actor, null, 'Delivery');
    cpaLogin($owner);
    $page = Livewire::test(NewClient::class, ['portfolio' => $portfolio->id])->call('open')->set('name', 'Late client');
    app(ArchivePortfolio::class)->handle($actor, $portfolio->id, true);
    $page->call('save')->assertHasErrors('portfolio')->assertSet('editorOpen', true)->assertSet('name', 'Late client');
    Livewire::test(Index::class)->set('portfolio', $portfolio->id)->assertSee('Archived');
    expect(ProjectClient::query()->count())->toBe(0);
});

it('defaults new projects to their selected portfolio client even for a broad manager', function () {
    $owner = cpaUser(allData: true);
    $actor = Access::forUser($owner);
    $portfolio = app(SavePortfolio::class)->handle($actor, null, 'Delivery');
    $client = app(SaveClient::class)->handle($actor, null, 'Client', $portfolio->id);
    cpaLogin($owner);
    Livewire::test(Index::class)->set('portfolio', $portfolio->id)->call('openCreateProject')
        ->assertSet('draftClientId', $client->id)->assertViewHas('canCreateInternal', false);
});

it('signs a deactivated account out before it can reach portfolios', function () {
    $user = cpaUser();
    $user->update(['is_active' => false]);
    cpaLogin($user->fresh());
    $this->get(route('projects.portfolios'))->assertRedirect('/');
    $this->assertGuest();
    expect(ProjectPortfolio::query()->count())->toBe(0);
});

it('offers a clear way out of an unavailable portfolio without creating clients outside it', function () {
    cpaLogin(cpaUser());
    Livewire::test(ClientsPage::class)->set('portfolio', 'unavailable')->assertSee('This portfolio is unavailable.')
        ->assertSee('Show my clients')->set('portfolio', 'ALL')->assertSee('No clients yet.');
    Livewire::test(Index::class)->set('portfolio', 'unavailable')->assertSee('This portfolio is unavailable.')
        ->assertDontSeeHtml('wire:click="openCreateProject"');
    expect(ProjectClient::query()->count())->toBe(0);
});
