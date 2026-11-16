<?php

declare(strict_types=1);

use App\Livewire\Projects\Show;
use App\Livewire\Tasks\MyTasksPage;
use App\Models\Project;
use App\Modules\WorkManagement\Application\Commands\SaveClient;
use App\Modules\WorkManagement\Application\Commands\SetClientMember;
use App\Modules\WorkManagement\Application\Queries\WorkRecords;
use App\Support\Access;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

require_once __DIR__.'/../Support/ClientProjectFixtures.php';

afterEach(fn () => CarbonImmutable::setTestNow());

it('shows scoped client context and searches it without revealing revoked or unassigned work', function () {
    $owner = cpaUser();
    $employee = cpaEmployee();
    $worker = cpaUser($employee, ['TASKS']);
    $client = cpaClient($owner, [$worker]);
    $client = app(SaveClient::class)->handle(Access::forUser($owner), $client->id, 'Acme àlpha');
    $project = cpaProject($owner, $client);
    $mine = cpaTask($owner, $project, [$employee], title: 'My client task');
    cpaTask($owner, $project, [], title: 'Unassigned private work');
    $other = cpaUser();
    $hiddenClient = cpaClient($other, [$worker]);
    $hidden = cpaProject($other, $hiddenClient);
    cpaTask($other, $hidden, [$employee], title: 'Revoked task');
    app(SetClientMember::class)->handle(Access::forUser($other), $hiddenClient->id, $worker->id, false);
    $records = app(WorkRecords::class);
    $actor = Access::forUser($worker);
    $rows = $records->ownPage($actor, ['q' => 'ÀLPHA', 'status' => 'ALL', 'project' => 'ALL'], 'focus', 'asc', 1, 20);
    expect($rows->pluck('id')->all())->toBe([$mine->id])->and($rows->first()->project->client_name)->toBe('Acme àlpha');
    cpaLogin($worker);
    Livewire::test(MyTasksPage::class)->assertSee('Acme àlpha')->assertDontSee('Unassigned private work')->assertDontSee('Revoked task');
});

it('starts directly without opening a drawer and keeps submission in the existing private workflow', function () {
    $owner = cpaUser();
    $employee = cpaEmployee();
    $worker = cpaUser($employee, ['TASKS']);
    $project = cpaProject($owner, cpaClient($owner, [$worker]));
    $mine = cpaTask($owner, $project, [$employee]);
    $other = cpaTask($owner, $project, [], title: 'Not mine');
    cpaLogin($worker);
    $page = Livewire::test(MyTasksPage::class)->call('startTask', $mine->id)->assertSet('openId', null)->assertSee('Respond');
    expect($mine->fresh()->status)->toBe('IN_PROGRESS');
    $page->call('startTask', $other->id)->assertSet('openId', null);
    expect($other->fresh()->status)->toBe('PENDING');
    $page->call('setQueue', 'WAITING')->assertSet('status', 'ALL')->assertViewHas('rows', fn ($rows): bool => $rows->isEmpty());
    $page->call('setQueue', 'ACTIONABLE')->set('taskView', 'gantt')->assertSee('Task timeline')->assertDontSee('Schedule task');
});

it('rechecks the module before a viewport lookup can reveal a task date', function (string $component) {
    $owner = cpaUser(allData: true);
    $employee = cpaEmployee();
    $project = new Project;
    $project->forceFill(['code' => 'PRJ-VIEWPORT', 'name' => 'Internal date scope', 'status' => 'ACTIVE', 'start_date' => '2026-01-01', 'manager_id' => $owner->id])->save();
    $task = cpaTask($owner, $project, [$employee]);
    cpaLogin(cpaUser($employee, []));
    Livewire::test($component, $component === Show::class ? ['project' => $project->id] : [])
        ->call('showOnTimeline', $task->id)->assertForbidden();
})->with([MyTasksPage::class, Show::class]);
