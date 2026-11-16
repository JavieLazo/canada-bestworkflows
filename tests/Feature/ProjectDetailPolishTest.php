<?php

declare(strict_types=1);

use App\Livewire\Projects\ClientsPage;
use App\Livewire\Projects\Index;
use App\Livewire\Projects\PortfoliosPage;
use App\Livewire\Projects\Show;
use App\Modules\WorkManagement\Application\Commands\SaveClient;
use App\Modules\WorkManagement\Application\Commands\SavePortfolio;
use App\Modules\WorkManagement\Application\Commands\SetClientMember;
use App\Modules\WorkManagement\Application\Commands\SetPortfolioMember;
use App\Modules\WorkManagement\Application\Queries\WorkSummary;
use App\Support\Access;
use Livewire\Livewire;

require_once __DIR__.'/../Support/ClientProjectFixtures.php';

it('separates details and shared project roles from the delivery overview and task assignees', function () {
    $owner = cpaUser(cpaEmployee('Owner'));
    $people = array_map(static fn (string $name) => cpaUser(cpaEmployee($name)), ['Paolo', 'Grace', 'Nathaniel']);
    $project = cpaProject($owner, cpaClient($owner, $people));
    $project->update(['description' => 'Keep the full project description together.']);
    cpaTask($owner, $project, array_map(static fn ($person) => $person->employee, $people));
    cpaLogin($owner);
    $page = Livewire::test(Show::class, ['project' => $project->id])
        ->assertSeeHtml('aria-label="Project overview"')->assertDontSeeHtml('aria-label="Project details"')
        ->assertDontSeeHtml('aria-label="Project members"')->assertDontSeeHtml('data-kit-checklist-summary');
    $page->set('taskView', 'details')->assertSee('Keep the full project description together.')
        ->assertSeeHtml('aria-label="Project details"')->assertSet('detailsOpen', true)->assertSet('editing', false)->assertSet('taskView', 'overview');
    $page->set('taskView', 'members')->assertSee('All tasks access comes from a project role.')->assertSee('Assigned tasks')
        ->assertSee('Client owner')->assertSee('Paolo Member')->assertSee('Grace Member')->assertSee('Nathaniel Member')
        ->assertViewHas('team', fn ($team): bool => $team->total() === 4 && $team->getCollection()->where('access', 'ASSIGNED')->count() === 3);
    $page->call('filterByAssignee', $people[0]->employee_id)->assertSet('taskView', 'list')->assertViewHas('visibleCount', 1);
});

it('keeps task delivery and assigned people inside the current task visibility', function () {
    $owner = cpaUser();
    $viewer = cpaUser(cpaEmployee('Visible'));
    $hidden = cpaUser(cpaEmployee('Hidden'));
    $project = cpaProject($owner, cpaClient($owner, [$viewer, $hidden]));
    $own = cpaTask($owner, $project, [$viewer->employee], title: 'Visible deadline');
    cpaTask($owner, $project, [$hidden->employee], title: 'Hidden deadline');
    $summary = app(WorkSummary::class);
    $data = $summary->projectDelivery(Access::forUser($viewer), $project->id);
    expect($data['states'])->toBe(['PENDING' => 1])->and($data['deadlines']->pluck('id')->all())->toBe([$own->id]);
    $outside = $summary->projectDelivery(Access::forUser(cpaUser()), $project->id);
    expect($outside['states'])->toBe([])->and($outside['deadlines'])->toHaveCount(0);
    cpaLogin($viewer);
    Livewire::test(Show::class, ['project' => $project->id])->set('taskView', 'members')
        ->assertViewHas('team', fn ($people): bool => $people->getCollection()->where('access', 'ASSIGNED')->count() === 1)
        ->assertDontSee('Hidden Member')->assertDontSee('Add member');
});

it('bounds upcoming work to the first four open dated tasks and links each chart state to its filtered list', function () {
    $owner = cpaUser();
    $project = cpaProject($owner, cpaClient($owner));
    $tasks = [];
    foreach (range(1, 6) as $day) {
        $task = cpaTask($owner, $project, [], title: 'Deadline '.$day);
        $task->update(['due_date' => sprintf('2026-11-%02d', $day)]);
        $tasks[] = $task;
    }
    $tasks[0]->update(['status' => 'COMPLETED']);
    $tasks[1]->update(['due_date' => null]);
    cpaLogin($owner);
    Livewire::test(Show::class, ['project' => $project->id])->assertViewHas('delivery', fn ($data): bool => $data['deadlines']->pluck('id')->all() === array_map(static fn ($task) => $task->id, array_slice($tasks, 2)));
    $this->get(route('projects.show', ['project' => $project->id, 'view' => 'list', 'status' => 'COMPLETED']))
        ->assertOk()->assertSee($tasks[0]->code)->assertDontSee($tasks[2]->code);
});

it('centers the list shortcut only on pages with room and leaves the primary create action available', function () {
    $owner = cpaUser();
    $project = cpaProject($owner, cpaClient($owner));
    cpaLogin($owner);
    $page = Livewire::test(Show::class, ['project' => $project->id])->set('taskView', 'list')->assertSeeHtml('data-list-add-task');
    foreach (range(1, 21) as $number) {
        cpaTask($owner, $project, [], title: 'Task '.$number);
    }
    $page->call('$refresh')->assertDontSeeHtml('data-list-add-task')->assertSee('New task');
    $page->call('nextPage')->assertSeeHtml('data-list-add-task')->set('perPage', 50)->assertSeeHtml('data-list-add-task');
    $project->update(['status' => 'CANCELLED']);
    $page->call('$refresh')->assertDontSeeHtml('data-list-add-task')->assertViewHas('canCreateTasks', false);
});

it('opens client and portfolio membership in context and reuses current owner-only writes', function (string $kind, string $component) {
    $owner = cpaUser(cpaEmployee('Owner'));
    $member = cpaUser(cpaEmployee('Shared'));
    $actor = Access::forUser($owner);
    $portfolio = app(SavePortfolio::class)->handle($actor, null, 'Delivery portfolio');
    $client = app(SaveClient::class)->handle($actor, null, 'Delivery client', $portfolio->id);
    $id = $kind === 'client' ? $client->id : $portfolio->id;
    cpaLogin($owner);
    $this->get(route('projects.index', [$kind => $id, 'section' => 'members']))->assertOk()->assertSee(ucfirst($kind).' members')->assertDontSee('Manage clients');
    $page = Livewire::test($component, ['membersFor' => $id])->assertSet('membersOnly', true);
    $document = new DOMDocument;
    @$document->loadHTML($page->html());
    $xpath = new DOMXPath($document);
    expect($xpath->evaluate('count(//*[@*[name()="wire:submit"]="addMember"]/ancestor::*[@*[name()="wire:id"]])'))->toBe(1.0)
        ->and($xpath->evaluate('string(//*[@*[name()="wire:submit"]="addMember"]/ancestor::*[@*[name()="wire:id"]][1]/@*[name()="wire:id"])'))->toBe($page->instance()->getId());
    $page->set('member', (string) $member->id)->call('addMember')->assertHasNoErrors()->assertSee('Shared Member');
    $page->call('removeMember', $member->id)->assertViewHas('members', fn ($people): bool => ! $people->contains('id', $member->id));
    $command = $kind === 'client' ? SetClientMember::class : SetPortfolioMember::class;
    app($command)->handle($actor, $id, $member->id, true);
    cpaLogin($member);
    $viewer = Livewire::test($component, ['membersFor' => $id])->assertSee('Owner Member')->assertDontSee('Add a person');
    $viewer->set('member', (string) $owner->id)->call('addMember')->assertForbidden();
    app($command)->handle($actor, $id, $member->id, false);
    Livewire::test($component, ['membersFor' => $id])->assertNotFound();
})->with([['client', ClientsPage::class], ['portfolio', PortfoliosPage::class]]);

it('does not expose an unscoped members section', function () {
    cpaLogin(cpaUser());
    Livewire::test(Index::class)->set('section', 'members')->assertSet('section', 'overview');
});
