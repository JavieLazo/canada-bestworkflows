<?php

declare(strict_types=1);

use App\Livewire\Projects\GeneralOverview;
use App\Livewire\Projects\Index;
use App\Livewire\Projects\Show;
use App\Models\Project;
use App\Models\ProjectCostCategory;
use App\Models\TaskComment;
use App\Modules\WorkManagement\Application\BudgetLineDraft;
use App\Modules\WorkManagement\Application\CashEntryDraft;
use App\Modules\WorkManagement\Application\Commands\ApproveProjectBudget;
use App\Modules\WorkManagement\Application\Commands\RecordCashEntry;
use App\Modules\WorkManagement\Application\Commands\SaveBudgetLine;
use App\Modules\WorkManagement\Application\Commands\SetupProjectFinance;
use App\Modules\WorkManagement\Application\Commands\StartProjectBudget;
use App\Modules\WorkManagement\Application\OverviewPeriod;
use App\Modules\WorkManagement\Application\Queries\OverviewAnalytics;
use App\Modules\WorkManagement\Application\Queries\WorkRecords;
use App\Modules\WorkManagement\Application\Queries\WorkSummary;
use App\Support\Access;
use App\Support\LocalClock;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

require_once __DIR__.'/../Support/ClientProjectFixtures.php';

it('aggregates only visible tasks and clients while counting leaf work once', function () {
    $owner = cpaUser();
    $employee = cpaEmployee();
    $member = cpaUser($employee);
    $client = cpaClient($owner, [$member]);
    $project = cpaProject($owner, $client);
    $parent = cpaTask($owner, $project, [$employee], title: 'Parent');
    $parent->update(['due_date' => '2026-10-20']);
    $child = cpaTask($owner, $project, [$employee], title: 'Child');
    $child->forceFill(['parent_id' => $parent->id, 'status' => 'COMPLETED'])->save();
    cpaTask($owner, $project, [], title: 'Hidden task');
    cpaProject($owner, cpaClient($owner), 'Private client');
    $summary = app(WorkSummary::class)->overview(Access::forUser($member), '2026-11-02');
    expect(array_sum($summary['projects']))->toBe(1)->and($summary['totals']->total)->toBe(2)
        ->and($summary['totals']->overdue)->toBe(1)->and($summary['totals']->unassigned)->toBe(0)
        ->and($summary['totals']->progress()['percent'])->toBe(100)
        ->and($summary['states'])->toBe(['COMPLETED' => 1, 'PENDING' => 1])
        ->and($summary['attention']->pluck('id')->all())->toBe([$project->id]);
    $otherScope = app(WorkSummary::class)->overview(Access::forUser($member), '2026-11-02', 'no-access-client');
    expect($otherScope['projects'])->toBe([])->and($otherScope['totals']->total)->toBe(0)->and($otherScope['deadlines'])->toHaveCount(0);
});

it('bounds overview project lists and personalizes activity before pagination', function () {
    $owner = cpaUser();
    $member = cpaUser(cpaEmployee());
    $client = cpaClient($owner, [$member]);
    $project = cpaProject($owner, $client);
    $own = cpaTask($owner, $project, [$member->employee]);
    $hidden = cpaTask($owner, $project, [], title: 'Hidden task');
    for ($n = 0; $n < 25; $n++) {
        foreach ([$own, $hidden] as $task) {
            $comment = new TaskComment;
            $comment->forceFill(['task_id' => $task->id, 'author_id' => $owner->id, 'author_name' => 'Owner', 'side' => 'MANAGEMENT', 'body' => $task->id === $own->id ? 'Visible update '.$n : 'Private hidden update'])->save();
        }
    }
    for ($n = 0; $n < 8; $n++) {
        cpaProject($owner, $client, 'Deadline '.$n);
    }
    $actor = Access::forUser($member);
    $records = app(WorkRecords::class);
    $page = $records->projectActivity($actor, $project->id);
    expect($page->total())->toBe(25)->and($page->count())->toBe(20)
        ->and($page->pluck('task_id')->unique()->all())->toBe([$own->id])
        ->and($records->projectActivity($actor, $project->id, 999)->currentPage())->toBe(2)
        ->and(app(WorkSummary::class)->overview(Access::forUser($owner), '2026-11-02')['deadlines'])->toHaveCount(6);
});

it('opens the module and project with overviews and drills metrics into work', function () {
    $owner = cpaUser(cpaEmployee());
    $project = cpaProject($owner, cpaClient($owner));
    $task = cpaTask($owner, $project, []);
    $task->update(['due_date' => '2026-10-20']);
    cpaLogin($owner);
    Livewire::test(Index::class)->assertSet('section', 'overview')->assertSee('Needs attention')
        ->call('setQueue', 'OVERDUE')->assertSet('section', 'projects')->assertSet('queue', 'OVERDUE')->assertSee($project->name);
    Livewire::test(Show::class, ['project' => $project->id])->assertSet('taskView', 'overview')->assertSee('Task delivery')
        ->assertSeeHtml('aria-label="Project overview"')->assertDontSeeHtml('aria-label="Project details"')->assertDontSee('Task audit log')
        ->set('search', 'old filter')->set('group', 'old-group')->call('setQueue', 'OVERDUE')
        ->assertSet('taskView', 'list')->assertSet('search', '')->assertSet('group', 'ALL')->assertSee($task->title)
        ->set('taskView', 'activity')->assertSee('Task audit log');
});

it('edits team roles through one manager dialog and renders editor task controls without admin actions', function () {
    $owner = cpaUser(cpaEmployee());
    $member = cpaUser(cpaEmployee('Editor'));
    $project = cpaProject($owner, cpaClient($owner, [$member]));
    cpaLogin($owner);
    Livewire::test(Show::class, ['project' => $project->id])->set('taskView', 'members')->call('openMember')
        ->set('memberUserId', (string) $member->id)->set('memberRole', 'EDITOR')->set('memberPosition', 'Designer')
        ->call('saveMember')->assertHasNoErrors()->assertSet('memberEditorOpen', false)->assertSee('Designer');
    cpaLogin($member);
    Livewire::test(Show::class, ['project' => $project->id])->assertSee('New task')->assertDontSee('Add member')->assertDontSee('Delete project')
        ->call('openTaskCreation')->assertSet('adding', true)->assertSee('Title');
});

it('keeps closed unassigned history out of attention without rewriting its existing totals', function () {
    $owner = cpaUser();
    $project = cpaProject($owner, cpaClient($owner));
    $task = cpaTask($owner, $project, []);
    $task->update(['status' => 'COMPLETED']);
    $overview = app(WorkSummary::class)->overview(Access::forUser($owner), '2026-11-02');
    expect($overview['attention'])->toHaveCount(0)->and($overview['totals']->total)->toBe(1)
        ->and($overview['totals']->completed)->toBe(1)->and($overview['totals']->unassigned)->toBe(1);
});

it('keeps scoped sections active across renders and the global sidebar active on navigation', function () {
    $owner = cpaUser();
    $client = cpaClient($owner);
    cpaLogin($owner);
    $active = static function ($component): string {
        $document = new DOMDocument;
        @$document->loadHTML($component->html());

        return trim((new DOMXPath($document))->evaluate('string(//*[@aria-label="Client sections"]//*[@aria-current="page"])'));
    };
    $index = Livewire::test(Index::class)->set('client', $client->id);
    expect($active($index))->toBe('Overview');
    $index->set('section', 'projects');
    expect($active($index))->toBe('Projects');
    foreach (['Clients' => route('projects.clients'), 'Projects' => route('projects.index', ['section' => 'projects'])] as $label => $url) {
        $document = new DOMDocument;
        @$document->loadHTML($this->get($url)->assertOk()->getContent() ?: '');
        expect(trim((new DOMXPath($document))->evaluate('string(//*[@aria-label="Modules"]//*[@aria-current="page"])')))->toBe($label);
    }
});

it('reads trends for a picked period and where work stands today from visible work only', function () {
    $owner = cpaUser(cpaEmployee());
    $client = cpaClient($owner);
    $project = cpaProject($owner, $client);
    cpaTask($owner, $project, [], title: 'Done')->forceFill(['created_at' => '2026-09-01 02:00:00', 'status' => 'COMPLETED', 'completed_at' => '2026-11-10 02:00:00', 'due_date' => '2026-11-10'])->save();
    cpaTask($owner, $project, [], title: 'Slow')->forceFill(['created_at' => '2026-10-20 02:00:00', 'status' => 'COMPLETED', 'completed_at' => '2026-11-12 02:00:00', 'due_date' => '2026-11-03'])->save();
    cpaTask($owner, $project, [], title: 'Late')->forceFill(['created_at' => '2026-11-05 02:00:00', 'due_date' => '2026-11-01'])->save();
    cpaTask($owner, $project, [], title: 'Dropped')->forceFill(['created_at' => '2026-10-01 02:00:00', 'status' => 'CANCELLED'])->save();
    $stranger = cpaUser(cpaEmployee('Stranger'));
    cpaTask($stranger, cpaProject($stranger, cpaClient($stranger), 'Not mine'), []);

    // Twelve weeks to Friday Nov 13, so the last week runs Nov 7 to 13 in Manila.
    $actor = Access::forUser($owner);
    $trends = app(OverviewAnalytics::class)->trends($actor, OverviewPeriod::resolve('12w', '', '', '2026-11-13'));
    expect($trends['created']['current'])->toBe([0, 1, 0, 0, 0, 1, 0, 0, 1, 0, 1, 0])
        ->and($trends['completed']['current'])->toBe([...array_fill(0, 11, 0), 2])
        ->and($trends['open']['current'])->toBe([0, 1, 1, 1, 1, 1, 1, 1, 2, 2, 3, 1])
        ->and($trends['onTime']['current'])->toBe([...array_fill(0, 11, null), 50])
        ->and($trends['open']['previous'])->toBe(array_fill(0, 12, 0));

    $outlook = app(OverviewAnalytics::class)->outlook($actor, '2026-11-13');
    expect([$outlook['totals']->overdue, $outlook['unassigned'], $outlook['statuses'], $outlook['atRisk']])->toBe([1, 1, ['ACTIVE' => 1], 1])
        ->and($outlook['ahead'][0])->toMatchArray(['key' => 'overdue', 'pending' => 1, 'progress' => 0, 'submitted' => 0])
        ->and(array_sum(array_map(fn (array $column): int => $column['pending'] + $column['progress'] + $column['submitted'], $outlook['ahead'])))->toBe(1)
        ->and(array_map(fn (array $row): array => [$row['project']->id, $row['health']], $outlook['attention']))->toBe([[$project->id, 'OVERDUE']]);

    cpaLogin($owner);
    Livewire::test(Index::class)->assertSeeHtml('popovertarget="overview-range"')->assertDontSee('Delivery overview')
        ->set('client', $client->id)->assertSee('Delivery overview')->assertDontSeeHtml('popovertarget="overview-range"');
});

it('switches the overview range to a preset or a checked custom range', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-13 10:00:00', LocalClock::zone()));
    $owner = cpaUser(cpaEmployee());
    cpaTask($owner, cpaProject($owner, cpaClient($owner)), []);
    cpaLogin($owner);
    Livewire::test(GeneralOverview::class)->assertSee('Last 30 days')->assertSee('Every open project is on track.')
        ->call('pickRange', '12w')->assertSet('range', '12w')->assertSee('Previous 12 weeks')
        ->call('pickRange', 'forever')->assertSet('range', '12w')
        ->set('draftFrom', '2026-11-13')->set('draftTo', '2026-11-04')->call('applyRange')->assertHasErrors('range')->assertSet('range', '12w')
        ->set('draftFrom', '2026-11-04')->set('draftTo', '2026-11-13')->call('applyRange')->assertHasNoErrors()
        ->assertSet('range', 'custom')->assertSet('from', '2026-11-04')->assertDispatched('overview-range-picked')->assertSee('Previous 10 days');
});

it('calls a budget at risk when it is over, or when spending runs well ahead of the work', function (int $approved, int $paid, ?int $done, ?string $risk) {
    expect(OverviewAnalytics::budgetRisk($approved, $paid, $done))->toBe($risk);
})->with([
    'over budget' => [100, 120, 90, 'OVER'],
    'spent exactly the budget' => [100, 100, 100, null],
    'spending 40 points ahead' => [100, 60, 20, 'AHEAD'],
    'spending exactly 20 points ahead' => [100, 60, 40, 'AHEAD'],
    'spending 19 points ahead' => [100, 59, 40, null],
    'no tasks to compare with' => [100, 90, null, null],
    'a zero budget with spending' => [0, 5, null, 'OVER'],
    'a zero budget with nothing spent' => [0, 0, 0, null],
]);

it('lists only open projects whose finances the viewer can see, worst first', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-13 10:00:00', LocalClock::zone()));
    $owner = cpaUser(cpaEmployee());
    $actor = Access::forUser($owner);
    $client = cpaClient($owner);
    $fund = function (Project $project, string $approved, string $paid) use ($actor): void {
        app(SetupProjectFinance::class)->handle($actor, $project->id, 'PHP');
        $category = ProjectCostCategory::query()->where('project_id', $project->id)->sole();
        $budget = app(StartProjectBudget::class)->handle($actor, $project->id);
        app(SaveBudgetLine::class)->handle($actor, $project->id, $budget, 1, null, new BudgetLineDraft('Cost', $category->id, $approved));
        app(ApproveProjectBudget::class)->handle($actor, $project->id, $budget, 2);
        app(RecordCashEntry::class)->handle($actor, $project->id, new CashEntryDraft('EXPENSE', '2026-11-10', 'Paid', $paid, $category->id));
    };
    $tasks = function (Project $project, int $open, int $done) use ($owner): void {
        for ($i = 0; $i < $open + $done; $i++) {
            $task = cpaTask($owner, $project, [], title: 'Task '.$i);
            if ($i < $done) {
                $task->forceFill(['status' => 'COMPLETED', 'completed_at' => '2026-11-10 02:00:00'])->save();
            }
        }
    };
    $over = cpaProject($owner, $client, 'Over');
    $fund($over, '100', '120');
    $ahead = cpaProject($owner, $client, 'Ahead');
    $fund($ahead, '100', '60');
    $tasks($ahead, 4, 1);
    $fine = cpaProject($owner, $client, 'Fine');
    $fund($fine, '100', '30');
    $tasks($fine, 1, 1);
    $closed = cpaProject($owner, $client, 'Closed');
    $fund($closed, '100', '500');
    $closed->forceFill(['status' => 'COMPLETED'])->save();
    app(SetupProjectFinance::class)->handle($actor, cpaProject($owner, $client, 'Unbudgeted')->id, 'USD');
    cpaProject($owner, $client, 'Finance not set up');
    $stranger = cpaUser(cpaEmployee('Stranger'));
    $hidden = cpaProject($stranger, cpaClient($stranger), 'Hidden');
    app(SetupProjectFinance::class)->handle(Access::forUser($stranger), $hidden->id, 'PHP');

    $budgets = app(OverviewAnalytics::class)->budgets($actor, '2026-11-13');
    expect([$budgets['budgeted'], $budgets['atRisk']])->toBe([3, 2])
        ->and(array_map(fn ($project): string => $project->name, $budgets['unbudgeted']))->toBe(['Finance not set up', 'Unbudgeted'])
        ->and(array_map(fn (array $row): array => [$row['project']->name, $row['risk'], $row['spent'], $row['done']], $budgets['rows']))
        ->toBe([['Over', 'OVER', 120, null], ['Ahead', 'AHEAD', 60, 20]]);
    expect(app(OverviewAnalytics::class)->budgets(Access::forUser(cpaUser(cpaEmployee('Reader'))), '2026-11-13'))->toBeNull();

    cpaLogin($owner);
    Livewire::test(GeneralOverview::class)->assertSee('Needs a look')->assertSeeHtml('ppm-burn-mark')->assertSee('2 without an approved budget')->assertDontSee('Closed')->assertDontSee('Hidden');
});

it('distinguishes no open projects from healthy open projects', function () {
    $owner = cpaUser();
    $client = cpaClient($owner);
    cpaProject($owner, $client, 'Completed project')->update(['status' => 'COMPLETED']);
    cpaProject($owner, $client, 'Cancelled project')->update(['status' => 'CANCELLED']);
    cpaLogin($owner);
    Livewire::test(GeneralOverview::class)->assertSee('No open projects.')->assertDontSee('Every open project is on track.');
});
