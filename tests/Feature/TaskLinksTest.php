<?php

declare(strict_types=1);

use App\Livewire\Projects\Show;
use App\Livewire\Tasks\MyTasksPage;
use App\Modules\WorkManagement\Application\Commands\CreateTask;
use App\Modules\WorkManagement\Application\Commands\SetClientMember;
use App\Modules\WorkManagement\Application\Commands\SetProjectMember;
use App\Modules\WorkManagement\Application\TaskDraft;
use App\Support\Access;
use Livewire\Livewire;

require_once __DIR__.'/../Support/ClientProjectFixtures.php';

it('opens a linked task directly in the project and clears its address when closed', function () {
    $owner = cpaUser();
    $project = cpaProject($owner, cpaClient($owner));
    $task = cpaTask($owner, $project, []);
    cpaLogin($owner);
    Livewire::withQueryParams(['task' => $task->id]);
    Livewire::test(Show::class, ['project' => $project->id])
        ->assertSet('openTaskId', $task->id)->assertSet('linkedTask', $task->id)->assertSet('reviewOpen', true)
        ->set('reviewOpen', false)->assertSet('linkedTask', null)->assertSet('openTaskId', null);
});

it('opens only personal work from My Tasks links even for a project viewer', function () {
    $owner = cpaUser();
    $worker = cpaUser(cpaEmployee());
    $peer = cpaUser(cpaEmployee('Peer'), ['TASKS']);
    $project = cpaProject($owner, cpaClient($owner, [$worker, $peer]));
    $own = cpaTask($owner, $project, [$worker->employee], title: 'Own linked task');
    $other = cpaTask($owner, $project, [$peer->employee], title: 'Peer private task');
    app(SetProjectMember::class)->handle(Access::forUser($owner), $project->id, $worker->id, 'VIEWER');
    cpaLogin($worker);
    Livewire::withQueryParams(['task' => $own->id]);
    Livewire::test(MyTasksPage::class)
        ->assertSet('openId', $own->id)->assertSet('linkedTask', $own->id)->call('dialogClosed')->assertSet('linkedTask', null);
    Livewire::withQueryParams(['task' => $other->id]);
    Livewire::test(MyTasksPage::class)
        ->assertSet('openId', null)->assertSet('linkedTask', null)->assertDontSee('Peer private task');
});

it('refuses cross-project or client-revoked links without changing selection authority', function () {
    $owner = cpaUser();
    $member = cpaUser(cpaEmployee());
    $client = cpaClient($owner, [$member]);
    $project = cpaProject($owner, $client);
    $other = cpaProject($owner, $client, 'Other');
    $task = cpaTask($owner, $other, [$member->employee]);
    cpaLogin($owner);
    Livewire::withQueryParams(['task' => $task->id]);
    Livewire::test(Show::class, ['project' => $project->id])
        ->assertSet('reviewOpen', false)->assertSet('openTaskId', null)->assertSet('linkedTask', null);
    app(SetClientMember::class)->handle(Access::forUser($owner), $client->id, $member->id, false);
    cpaLogin($member);
    Livewire::withQueryParams(['task' => $task->id]);
    Livewire::test(MyTasksPage::class)->assertSet('openId', null)->assertSet('linkedTask', null);
});

it('revalidates browser navigation and closes the drawer when the task parameter is cleared', function () {
    $owner = cpaUser();
    $worker = cpaUser(cpaEmployee(), ['TASKS']);
    $peer = cpaUser(cpaEmployee('Peer'), ['TASKS']);
    $project = cpaProject($owner, cpaClient($owner, [$worker, $peer]));
    $own = cpaTask($owner, $project, [$worker->employee]);
    $other = cpaTask($owner, $project, [$peer->employee]);
    cpaLogin($worker);
    Livewire::test(MyTasksPage::class)->set('linkedTask', $own->id)->assertSet('openId', $own->id)
        ->set('linkedTask', $other->id)->assertSet('openId', null)->assertSet('linkedTask', null)
        ->set('linkedTask', $own->id)->set('linkedTask', null)->assertSet('openId', null);
    cpaLogin($owner);
    Livewire::test(Show::class, ['project' => $project->id])->set('linkedTask', $own->id)->assertSet('reviewOpen', true)
        ->set('linkedTask', null)->assertSet('reviewOpen', false)->assertSet('openTaskId', null);
});

it('ignores malformed task navigation input', function (mixed $value) {
    $owner = cpaUser();
    $project = cpaProject($owner, cpaClient($owner));
    cpaLogin($owner);
    Livewire::withQueryParams(['task' => $value]);
    $page = Livewire::test(Show::class, ['project' => $project->id]);
    $page->assertStatus(200);
    $page->assertSet('openTaskId', null)->assertSet('linkedTask', null);
})->with([null, '', ['not', 'an', 'id'], str_repeat('x', 100)]);

it('clears task navigation when an action moves away from the task panel', function (string $action) {
    $owner = cpaUser();
    $project = cpaProject($owner, cpaClient($owner));
    $task = cpaTask($owner, $project, []);
    app(CreateTask::class)->handle(Access::forUser($owner), $project->id,
        new TaskDraft('Child', '', 'NORMAL', '', [], parentId: $task->id));
    cpaLogin($owner);
    $page = Livewire::test(Show::class, ['project' => $project->id])->call('openTask', $task->id);
    $page->call($action, $action === 'review' ? 'CANCELLED' : $task->id);
    $page->assertSet('reviewOpen', false)->assertSet('openTaskId', null)->assertSet('linkedTask', null);
})->with(['review', 'showSubtasks', 'openSubtaskCreation', 'openSchedule']);

it('keeps the brief and task link open after starting from the drawer', function () {
    $owner = cpaUser();
    $worker = cpaUser(cpaEmployee(), ['TASKS']);
    $project = cpaProject($owner, cpaClient($owner, [$worker]));
    $task = cpaTask($owner, $project, [$worker->employee]);
    cpaLogin($worker);
    Livewire::withQueryParams(['task' => $task->id]);
    Livewire::test(MyTasksPage::class)->call('startTask')->assertSet('openId', $task->id)->assertSet('linkedTask', $task->id)->assertSee('Submit for Review');
    expect($task->fresh()->status)->toBe('IN_PROGRESS');
});
