<?php

declare(strict_types=1);

use App\Livewire\Projects\PortfoliosPage;
use App\Livewire\Projects\Show;
use App\Livewire\Projects\WorkbookImport;
use App\Modules\WorkManagement\Application\Commands\CreateTask;
use App\Modules\WorkManagement\Application\Commands\SaveTaskGroup;
use App\Modules\WorkManagement\Application\Queries\WorkLists;
use App\Modules\WorkManagement\Application\TaskDraft;
use App\Support\Access;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;

require_once __DIR__.'/../Support/ClientProjectFixtures.php';

it('opens the existing portfolio creation form from the overview shortcut', function () {
    cpaLogin(cpaUser());
    Livewire::withQueryParams(['new' => 1]);
    Livewire::test(PortfoliosPage::class)->assertSet('editorOpen', true);
});

it('combines selected groups and ungrouped work before paging without escaping the project scope', function () {
    $owner = cpaUser();
    $actor = Access::forUser($owner);
    $client = cpaClient($owner);
    $project = cpaProject($owner, $client);
    $foreign = cpaProject($owner, $client, 'Other project');
    $groups = [];
    foreach (['Design', 'Build', 'Release'] as $name) {
        $group = app(SaveTaskGroup::class)->handle($actor, $project->id, null, $name);
        $groups[] = $group->id;
        app(CreateTask::class)->handle($actor, $project->id, new TaskDraft($name.' work', '', 'NORMAL', '', [], groupId: $group->id));
    }
    cpaTask($owner, $project, [], title: 'Unsorted work');
    cpaTask($owner, $foreign, [], title: 'Foreign ungrouped work');
    $filters = ['q' => '', 'status' => 'ALL', 'priority' => 'ALL', 'assignee' => 'ALL', 'queue' => 'ALL', 'groups' => [$groups[0], $groups[1], 'NONE']];
    $lists = app(WorkLists::class);
    $rows = $lists->taskPage($actor, $project->id, $filters, 'task', 'asc', '2026-11-09', 1, 2);
    expect($rows->total())->toBe(3)->and($rows->count())->toBe(2);
    $board = $lists->taskBoard($actor, $project->id, $filters, '2026-11-09');
    expect($board['PENDING']->pluck('title')->all())->toContain('Design work', 'Build work', 'Unsorted work')->not->toContain('Release work', 'Foreign ungrouped work');
    cpaLogin($owner);
    $page = Livewire::test(Show::class, ['project' => $project->id])->set('taskView', 'list')->set('groupSelection', $filters['groups']);
    $page->assertViewHas('visibleCount', 3);
    $page->call('openTaskCreation')->assertSet('taskGroupId', 'NONE');
    $page->set('groupSelection', [$groups[0]])->call('openTaskCreation')->assertSet('taskGroupId', $groups[0]);
    $csv = $this->get(route('projects.tasks.export', ['project' => $project->id, 'groups' => $filters['groups']]))->assertOk()->streamedContent();
    expect($csv)->toContain('Design work', 'Build work', 'Unsorted work')->not->toContain('Release work', 'Foreign ungrouped work');
    $page->call('clearFilters')->assertSet('groupSelection', [])->assertViewHas('visibleCount', 4);
    $this->getJson(route('projects.tasks.export', ['project' => $project->id, 'groups' => [['nested']]]))->assertUnprocessable();
});

it('keeps workbook menu events inside their project and preserves the standalone trigger', function () {
    $owner = cpaUser();
    $project = cpaProject($owner, cpaClient($owner));
    cpaLogin($owner);
    Livewire::test(WorkbookImport::class, ['projectId' => $project->id, 'showTrigger' => false])
        ->dispatch('open-project-workbook', projectId: 'other')->assertSet('open', false)
        ->dispatch('open-project-workbook', projectId: $project->id)->assertSet('open', true)
        ->assertSee('Matching IDs can update existing records.')->call('close')->assertSet('open', false);
    Livewire::test(WorkbookImport::class, ['projectId' => $project->id])->assertSee('Import XLSX');
});

it('retains group organization for a closed task without reopening its properties', function () {
    $owner = cpaUser();
    $project = cpaProject($owner, cpaClient($owner));
    $task = cpaTask($owner, $project, []);
    $task->update(['status' => 'COMPLETED']);
    $group = app(SaveTaskGroup::class)->handle(Access::forUser($owner), $project->id, null, 'Archive');
    cpaLogin($owner);
    Livewire::test(Show::class, ['project' => $project->id])->call('openTask', $task->id)
        ->assertSeeHtml('aria-label="Move task to activity"')->assertDontSee('Save task details')
        ->call('moveTaskGroup', $group->id);
    expect($task->fresh()->group_id)->toBe($group->id)->and($task->fresh()->status)->toBe('COMPLETED');
});

it('does not mark an assignee waiting on review as personally due soon', function () {
    $task = (object) ['due_date' => CarbonImmutable::parse('2026-11-09'), 'status' => 'IN_PROGRESS', 'my_status' => 'APPROVED'];
    $html = Blade::render('<x-work.task-deadline :task="$task" today="2026-11-09" own />', compact('task'));
    expect($html)->not->toContain('text-danger', 'text-warning', 'Due soon');
});

it('shows task deadlines with a calendar and accessible urgency without coloring closed work as late', function (string $status, string $due, string $color, string $hint) {
    $task = (object) ['due_date' => CarbonImmutable::parse($due), 'status' => $status];
    $html = Blade::render('<x-work.task-deadline :task="$task" today="2026-11-09" />', compact('task'));
    expect($html)->toContain($color, $hint, 'svg');
    if (in_array($status, ['COMPLETED', 'CANCELLED'], true)) {
        expect($html)->not->toContain('text-danger', 'text-warning');
    }
})->with([
    ['PENDING', '2026-11-08', 'text-danger', 'Overdue'],
    ['IN_PROGRESS', '2026-11-09', 'text-warning', 'Due soon'],
    ['IN_PROGRESS', '2026-11-11', 'text-warning', 'Due soon'],
    ['IN_PROGRESS', '2026-11-12', 'text-muted-foreground', 'November 12, 2026'],
    ['COMPLETED', '2026-11-08', 'text-muted-foreground', 'November 8, 2026'],
    ['CANCELLED', '2026-11-08', 'text-muted-foreground', 'November 8, 2026'],
]);
