<?php

declare(strict_types=1);

use App\Livewire\Projects\ClientsPage;
use App\Livewire\Projects\Index;
use App\Livewire\Projects\PortfoliosPage;
use App\Livewire\Projects\Show;
use App\Livewire\Tasks\MyTasksPage;
use App\Models\ProjectClient;
use App\Models\ProjectPortfolio;
use App\Modules\WorkManagement\Application\Commands\SaveClient;
use App\Modules\WorkManagement\Application\Commands\SavePortfolio;
use App\Support\Access;
use Livewire\Livewire;

require_once __DIR__.'/../Support/ClientProjectFixtures.php';

it('places filters in a named popover without detached counts or scope selectors', function () {
    $owner = cpaUser(cpaEmployee());
    $project = cpaProject($owner, cpaClient($owner));
    cpaTask($owner, $project, [$owner->employee]);
    cpaLogin($owner);
    foreach ([
        Livewire::test(Index::class)->set('section', 'projects')->set('status', 'ACTIVE'),
        Livewire::test(Show::class, ['project' => $project->id])->set('taskView', 'list')->set('priority', 'HIGH'),
        Livewire::test(MyTasksPage::class)->set('status', 'PENDING'),
    ] as $page) {
        $page->assertSeeHtml('popover="auto"')->assertSeeHtml('aria-haspopup="dialog"')
            ->assertDontSee('filters applied')->assertDontSee('Switch work context')->assertDontSeeHtml('ppm-filter-row');
    }
});

it('edits current client and portfolio settings through existing owner commands', function () {
    $owner = cpaUser();
    $actor = Access::forUser($owner);
    $portfolio = app(SavePortfolio::class)->handle($actor, null, 'Old portfolio');
    $client = app(SaveClient::class)->handle($actor, null, 'Old client', $portfolio->id);
    cpaLogin($owner);
    Livewire::test(PortfoliosPage::class, ['toolsFor' => $portfolio->id])->dispatch('edit-current-portfolio')
        ->assertSet('editorOpen', true)->assertSet('name', 'Old portfolio')->set('name', 'Updated portfolio')
        ->set('description', 'Updated brief')->call('savePortfolio')->assertHasNoErrors()->assertDispatched('work-context-updated');
    Livewire::test(ClientsPage::class, ['toolsFor' => $client->id])->dispatch('edit-current-client')
        ->assertSet('editorOpen', true)->set('name', 'Updated client')->call('saveClient')->assertHasNoErrors()->assertDispatched('work-context-updated');
    expect(ProjectPortfolio::query()->sole()->description)->toBe('Updated brief')
        ->and(ProjectClient::query()->sole()->name)->toBe('Updated client');
    $this->get(route('projects.index', ['portfolio' => $portfolio->id]))->assertOk()->assertSee('Updated portfolio')->assertSee('Edit portfolio');
    $this->get(route('projects.index', ['client' => $client->id]))->assertOk()->assertSee('Updated client')->assertSee('Edit client')->assertSee('Move client');
});

it('moves a client from its own context and opens its new context without moving its projects independently', function () {
    $owner = cpaUser();
    $actor = Access::forUser($owner);
    $portfolio = app(SavePortfolio::class)->handle($actor, null, 'Destination');
    $client = cpaClient($owner);
    $project = cpaProject($owner, $client);
    cpaLogin($owner);
    Livewire::test(ClientsPage::class, ['toolsFor' => $client->id])->dispatch('move-current-client')
        ->assertSet('organizeOpen', true)->assertSee('Destination portfolio')
        ->set('destination', $portfolio->id)->call('organizeClient')->assertHasNoErrors()
        ->assertRedirect(route('projects.index', ['client' => $client->id, 'portfolio' => $portfolio->id]));
    expect($project->fresh()->client_id)->toBe($client->id)
        ->and(ProjectClient::query()->sole()->portfolio_id)->toBe($portfolio->id);
});

it('never grants context editing through a tool component', function () {
    $owner = cpaUser();
    $portfolio = app(SavePortfolio::class)->handle(Access::forUser($owner), null, 'Private');
    $client = cpaClient($owner);
    cpaLogin(cpaUser());
    Livewire::test(PortfoliosPage::class, ['toolsFor' => $portfolio->id])->assertForbidden();
    Livewire::test(ClientsPage::class, ['toolsFor' => $client->id])->assertForbidden();
});

it('refreshes renamed context labels without losing the current directory page', function () {
    $owner = cpaUser();
    $actor = Access::forUser($owner);
    $portfolio = app(SavePortfolio::class)->handle($actor, null, 'Delivery');
    foreach (range(1, 21) as $number) {
        $client = app(SaveClient::class)->handle($actor, null, 'Client '.$number, $portfolio->id);
        cpaProject($owner, $client, 'Project '.$number);
    }
    cpaLogin($owner);
    foreach ([Livewire::test(Index::class)->set('portfolio', $portfolio->id)->set('section', 'projects'), Livewire::test(ClientsPage::class)->set('portfolio', $portfolio->id)] as $page) {
        $page->call('nextPage')->assertSet('paginators.page', 2);
        $page->dispatch('work-context-updated')->assertSet('paginators.page', 2);
    }
});
