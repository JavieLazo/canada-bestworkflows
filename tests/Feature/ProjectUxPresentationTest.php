<?php

declare(strict_types=1);

use App\Livewire\Projects\ClientsPage;
use App\Livewire\Projects\Index;
use App\Livewire\Projects\PortfoliosPage;
use App\Livewire\Projects\Show;
use App\Models\RoleModuleGrant;
use App\Modules\WorkManagement\Application\Commands\SaveClient;
use App\Modules\WorkManagement\Application\Commands\SavePortfolio;
use App\Modules\WorkManagement\Application\Commands\SetClientMember;
use App\Modules\WorkManagement\Application\Commands\SetPortfolioMember;
use App\Modules\WorkManagement\Application\Commands\SetProjectMember;
use App\Modules\WorkManagement\Application\Queries\ProjectClients;
use App\Modules\WorkManagement\Application\Queries\ProjectPeople;
use App\Modules\WorkManagement\Application\Queries\ProjectPortfolios;
use App\Modules\WorkManagement\Application\Queries\WorkRecords;
use App\Support\Access;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

require_once __DIR__.'/../Support/ClientProjectFixtures.php';

it('keeps omitted client descriptions on old callers and allows explicit edits and clearing', function () {
    $owner = cpaUser();
    $actor = Access::forUser($owner);
    $command = app(SaveClient::class);
    $client = $command->handle($actor, null, 'Client', description: 'Delivery brief');
    expect($command->handle($actor, $client->id, 'Renamed')->description)->toBe('Delivery brief');
    expect(fn () => $command->handle($actor, $client->id, 'Renamed', description: str_repeat('x', 4001)))->toThrow(ValidationException::class);
    cpaLogin($owner);
    Livewire::test(ClientsPage::class, ['toolsFor' => $client->id])->dispatch('edit-current-client')
        ->assertSet('description', 'Delivery brief')->set('description', 'Updated brief')->call('saveClient')->assertHasNoErrors();
    Livewire::test(Index::class)->set('client', $client->id)->assertSee('Updated brief');
    expect($command->handle($actor, $client->id, 'Renamed', description: '')->description)->toBeNull();
    $this->assertDatabaseHas('audit_logs', ['action' => 'CLIENT_RENAMED', 'entity_id' => $client->id]);
    $this->assertDatabaseHas('audit_logs', ['action' => 'CLIENT_UPDATED', 'entity_id' => $client->id]);

});

it('sorts scoped collection counts numerically and never counts inaccessible work', function () {
    $owner = cpaUser();
    $viewer = cpaUser();
    $actor = Access::forUser($owner);
    $portfolio = app(SavePortfolio::class)->handle($actor, null, 'Portfolio');
    app(SetPortfolioMember::class)->handle($actor, $portfolio->id, $viewer->id, true);
    $small = app(SaveClient::class)->handle($actor, null, 'Two', $portfolio->id, 'Small delivery');
    $large = app(SaveClient::class)->handle($actor, null, 'Ten', $portfolio->id);
    foreach ([$small, $large] as $client) {
        app(SetClientMember::class)->handle($actor, $client->id, $viewer->id, true);
        foreach (range(1, $client->id === $small->id ? 2 : 10) as $number) {
            cpaProject($owner, $client, 'Project '.$number);
        }
    }
    $queries = app(ProjectClients::class);
    expect($queries->page($actor, '', 1, 20, sort: 'projects')->pluck('id')->all())->toBe([$small->id, $large->id]);
    expect($queries->page($actor, 'delivery', 1, 20)->pluck('id')->all())->toBe([$small->id]);
    expect($queries->page(Access::forUser($viewer), '', 1, 20, contents: 'EMPTY')->total())->toBe(2);
    $foreign = app(SavePortfolio::class)->handle(Access::forUser($viewer), null, 'Foreign');
    expect(app(ProjectPortfolios::class)->page($actor, '', false, 1, 20)->pluck('id')->all())->toBe([$portfolio->id]);
    expect(app(ProjectPortfolios::class)->page($actor, '', false, 1, 20, contents: 'WITH_WORK')->pluck('id')->all())->toBe([$portfolio->id]);
    expect(app(ProjectPortfolios::class)->page(Access::forUser($viewer), '', false, 1, 20, ownership: 'OWNED')->pluck('id')->all())->toBe([$foreign->id]);
});

it('keeps roster filtering paging and sorting independent of the collection', function (string $component, string $kind) {
    $owner = cpaUser(cpaEmployee('Owner'));
    $shared = cpaUser(cpaEmployee('Zoe'));
    $actor = Access::forUser($owner);
    $container = $kind === 'portfolio' ? app(SavePortfolio::class)->handle($actor, null, 'Delivery') : cpaClient($owner);
    app($kind === 'portfolio' ? SetPortfolioMember::class : SetClientMember::class)->handle($actor, $container->id, $shared->id, true);
    cpaLogin($owner);
    $page = Livewire::test($component);
    $page->set('collectionView', 'list')->set('search', 'Delivery')
        ->call($kind === 'portfolio' ? 'openPeople' : 'openClient', $container->id)->set('peopleSearch', 'Zoe')
        ->assertViewHas('members', fn ($people): bool => $people->total() === 1 && $people->first()->name === 'Zoe Member');
    $page->set('peoplePerPage', 50)->call('sortPeople', 'name')->assertSet('search', 'Delivery')->assertSet('perPage', 20)->assertSet('sortKey', 'name');
    $page->set('peopleAccess', 'OWNER')->assertViewHas('members', fn ($people): bool => $people->total() === 0);
    cpaLogin($shared);
    Livewire::test($component, ['toolsFor' => $container->id])->dispatch('open-current-'.$kind.'-members')
        ->assertSet('membersOpen', true)->assertSee('Owner Member')->assertDontSee('Add a person');
})->with([[PortfoliosPage::class, 'portfolio'], [ClientsPage::class, 'client']]);

it('opens the project roster from the header and edits a task in its existing title and description positions', function () {
    $owner = cpaUser(cpaEmployee('Owner'));
    $assigned = cpaUser(cpaEmployee('Assigned'));
    $project = cpaProject($owner, cpaClient($owner, [$assigned]));
    $task = cpaTask($owner, $project, [$assigned->employee]);
    cpaLogin($owner);
    $page = Livewire::test(Show::class, ['project' => $project->id]);
    $page->call('openPeople')->assertSet('taskView', 'overview')
        ->assertViewHas('peopleCount', 2);
    $page->assertSee('Assigned tasks');
    $page->set('peopleSearch', 'Assigned')
        ->assertViewHas('team', fn ($people): bool => $people->total() === 1 && $people->first()->open_tasks === 1);
    $page->set('membersOpen', false)->call('openTask', $task->id)->call('editContent')->assertSet('editingContent', true);
    $page->assertSeeHtml('form="task-content-form"');
    $page->set('contentTitle', 'Edited in place')->set('contentDescription', 'Updated description')->call('saveContent')->assertHasNoErrors();
    expect($task->fresh()->title)->toBe('Edited in place')->and($task->fresh()->description)->toBe('Updated description');
});

it('labels retained assignments as inactive when client access is removed without changing the crew', function () {
    $owner = cpaUser();
    $assigned = cpaUser(cpaEmployee('Retained'));
    $client = cpaClient($owner, [$assigned]);
    $project = cpaProject($owner, $client);
    $task = cpaTask($owner, $project, [$assigned->employee]);
    $actor = Access::forUser($owner);
    app(SetClientMember::class)->handle($actor, $client->id, $assigned->id, false);
    $people = app(ProjectPeople::class)->roster($actor, $project->id, '2026-11-06');
    expect($people->firstWhere('user_id', $assigned->id)->access)->toBe('INACTIVE')
        ->and($task->fresh()->assigneeIds())->toBe([$assigned->employee_id]);
    expect(app(WorkRecords::class)->project(Access::forUser($assigned), $project->id))->toBeNull();
});

it('does not resolve project people for an account without the Projects module', function (bool $tasksOnly) {
    $owner = cpaUser();
    $assigned = cpaUser(cpaEmployee('Restricted'), $tasksOnly ? ['TASKS'] : []);
    $project = cpaProject($owner, cpaClient($owner));
    expect(app(ProjectPeople::class)->roster(Access::forUser($assigned), $project->id, '2026-11-06'))->toBeEmpty();
})->with([false, true]);

it('distinguishes an inactive project role from retained task access and account activity', function () {
    $owner = cpaUser();
    $assigned = cpaUser(cpaEmployee('Assigned'));
    $client = cpaClient($owner, [$assigned]);
    $project = cpaProject($owner, $client);
    $actor = Access::forUser($owner);
    app(SetProjectMember::class)->handle($actor, $project->id, $assigned->id, 'VIEWER');
    cpaTask($owner, $project, [$assigned->employee]);
    RoleModuleGrant::query()->where('role_id', $assigned->role_id)->where('module', 'PROJECTS')->delete();
    $assigned->employee->update(['is_active' => false]);
    $people = app(ProjectPeople::class)->roster($actor, $project->id, '2026-11-06');
    expect($people->firstWhere('user_id', $assigned->id)->access)->toBe('ASSIGNED')
        ->and($people->firstWhere('user_id', $assigned->id)->active)->toBeTrue();
    app(SetClientMember::class)->handle($actor, $client->id, $assigned->id, false);
    $people = app(ProjectPeople::class)->roster($actor, $project->id, '2026-11-06');
    expect($people->firstWhere('user_id', $assigned->id)->access)->toBe('INACTIVE')
        ->and($people->firstWhere('user_id', $assigned->id)->active)->toBeTrue();
    cpaLogin($owner);
    Livewire::test(Show::class, ['project' => $project->id])->call('openPeople')->assertSee('Access inactive')->assertDontSee('Inactive account');

});

it('keeps a refused project edit visible until its dates are deliberately corrected', function () {
    $owner = cpaUser();
    $project = cpaProject($owner, cpaClient($owner));
    $task = cpaTask($owner, $project, []);
    $before = $project->description;
    $project->update(['due_date' => '2026-11-10']);
    cpaLogin($owner);
    $page = Livewire::test(Show::class, ['project' => $project->id]);
    $page->call('openEdit')->set('editDescription', 'Retain this draft')->call('saveProject')
        ->assertSet('editing', true)->assertSet('editDescription', 'Retain this draft')->assertHasErrors('projectEdit');
    expect($project->fresh()->description)->toBe($before)->and($task->fresh()->due_date->format('Y-m-d'))->toBe('2026-11-20');
    $page->set('editDueDate', '2026-11-30')->call('saveProject')->assertHasNoErrors('projectEdit')->assertSet('editing', false);
    expect($project->fresh()->description)->toBe('Retain this draft')->and($task->fresh()->due_date->format('Y-m-d'))->toBe('2026-11-20');
});
