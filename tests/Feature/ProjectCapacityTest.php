<?php

declare(strict_types=1);

use App\Models\ProjectAvailability;
use App\Models\ProjectClient;
use App\Models\ProjectTaskEffort;
use App\Modules\WorkManagement\Application\Commands\SavePortfolio;
use App\Modules\WorkManagement\Application\Commands\SaveProjectAvailability;
use App\Modules\WorkManagement\Application\Commands\SaveTaskEffort;
use App\Modules\WorkManagement\Application\Commands\SetClientMember;
use App\Modules\WorkManagement\Application\Commands\SetProjectMember;
use App\Modules\WorkManagement\Application\Queries\ProjectCapacity;
use App\Modules\WorkManagement\Application\WorkRules;
use App\Modules\WorkManagement\Infrastructure\AccountReferences;
use App\Modules\WorkManagement\Infrastructure\EmployeeReferences;
use App\Modules\WorkManagement\Infrastructure\PlanningScope;
use App\Support\Access;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/../Support/ClientProjectFixtures.php';

it('keeps weekly availability self-managed private and revision checked without a default week', function () {
    $employee = cpaEmployee();
    $user = cpaUser($employee, ['TASKS']);
    $actor = Access::forUser($user);
    $query = app(ProjectCapacity::class);
    expect($query->ownAvailability($actor, '2026-11-02'))->toBe(['hours' => null, 'shared' => false, 'revision' => 0]);
    $save = app(SaveProjectAvailability::class);
    $save->handle($actor, '2026-11-02', '30', false, 0);
    expect(ProjectAvailability::query()->sole()->hours_hundredths)->toBe(3000);
    expect(fn () => $save->handle($actor, '2026-11-02', '20', true, 0))->toThrow(ValidationException::class);
    $save->handle($actor, '2026-11-02', '0', true, 1);
    expect($query->ownAvailability($actor, '2026-11-02'))->toBe(['hours' => 0, 'shared' => true, 'revision' => 2]);
    $save->handle($actor, '2026-11-02', '', true, 2);
    expect($query->ownAvailability($actor, '2026-11-02'))->toBe(['hours' => null, 'shared' => false, 'revision' => 3]);
    expect(fn () => $save->handle(Access::forUser(cpaUser()), '2026-11-02', '30', true, 0))->toThrow(HttpException::class);
});

it('shares availability only with current planners and keeps viewers and unrelated clients out', function () {
    $owner = cpaUser();
    $person = cpaEmployee();
    $worker = cpaUser($person);
    $viewer = cpaUser();
    $client = cpaClient($owner, [$worker, $viewer]);
    $project = cpaProject($owner, $client);
    cpaTask($owner, $project, [$person]);
    $actor = Access::forUser($owner);
    app(SetProjectMember::class)->handle($actor, $project->id, $viewer->id, 'VIEWER');
    $save = app(SaveProjectAvailability::class);
    $save->handle(Access::forUser($worker), '2026-11-02', '30', false, 0);
    $query = app(ProjectCapacity::class);
    expect($query->people($actor, $project->id, false, '2026-11-02')->first()->weeks['2026-11-02']['available'])->toBeNull();
    $save->handle(Access::forUser($worker), '2026-11-02', '30', true, 1);
    expect($query->people($actor, $project->id, false, '2026-11-02')->first()->weeks['2026-11-02']['available'])->toBe(3000)
        ->and($query->people(Access::forUser($viewer), $project->id, false, '2026-11-02')->first()->weeks['2026-11-02']['available'])->toBeNull();
    expect($query->totals(Access::forUser($viewer), $project->id, false, '2026-11-02')->knownWeeks)->toBe(0);
    app(SetClientMember::class)->handle($actor, $client->id, $viewer->id, false);
    expect($query->totals(Access::forUser($viewer), $project->id, false, '2026-11-02'))->toBeNull();
    expect(fn () => $query->people(Access::forUser($viewer), $project->id, false, '2026-11-02'))->toThrow(HttpException::class);
    expect(fn () => $query->people(Access::forUser(cpaUser(allData: true)), $project->id, false, '2026-11-02'))->toThrow(HttpException::class);
});

it('lets current assignees estimate themselves while project editors plan the existing crew', function () {
    $owner = cpaUser();
    $person = cpaEmployee();
    $peer = cpaEmployee();
    $worker = cpaUser($person, ['TASKS']);
    $editor = cpaUser();
    $project = cpaProject($owner, cpaClient($owner, [$worker, $editor, cpaUser($peer, ['TASKS'])]));
    $task = cpaTask($owner, $project, [$person, $peer]);
    $originalTask = $task->getAttributes();
    $actor = Access::forUser($owner);
    app(SetProjectMember::class)->handle($actor, $project->id, $editor->id, 'EDITOR');
    $save = app(SaveTaskEffort::class);
    $save->handle(Access::forUser($worker), $task->id, $person->id, '2026-11-02', '12.34', 0);
    expect(fn () => $save->handle(Access::forUser($worker), $task->id, $peer->id, '2026-11-02', '8', 0))->toThrow(HttpException::class);
    $save->handle(Access::forUser($editor), $task->id, $peer->id, '2026-11-02', '8', 0);
    expect(fn () => $save->handle($actor, $task->id, $person->id, '2026-11-02', '1', 0))->toThrow(ValidationException::class);
    $row = app(ProjectCapacity::class)->people($actor, $project->id, false, '2026-11-02')->getCollection()->firstWhere('employee_id', $person->id);
    expect($row->weeks['2026-11-02']['planned'])->toBe(1234)->and(ProjectTaskEffort::query()->count())->toBe(2);
    expect($task->fresh()->assigneeIds())->toBe($task->assigneeIds())->and($task->fresh()->getAttributes())->toBe($originalTask);
});

it('counts only open leaf crew effort and retains parent and former crew estimates for review', function () {
    $owner = cpaUser();
    $person = cpaEmployee();
    $project = cpaProject($owner, cpaClient($owner, [cpaUser($person, ['TASKS'])]));
    $parent = cpaTask($owner, $project, [$person]);
    $child = cpaTask($owner, $project, [$person]);
    $actor = Access::forUser($owner);
    $save = app(SaveTaskEffort::class);
    $save->handle($actor, $parent->id, $person->id, '2026-11-02', '10', 0);
    $save->handle($actor, $child->id, $person->id, '2026-11-02', '3', 0);
    $child->forceFill(['parent_id' => $parent->id])->save();
    expect($child->fresh()->parent_id)->toBe($parent->id);
    $query = app(ProjectCapacity::class);
    expect($query->people($actor, $project->id, false, '2026-11-02')->first()->weeks['2026-11-02']['planned'])->toBe(300)
        ->and($query->issues($actor, $project->id, false, '2026-11-02')->total())->toBe(1);
    $child->syncAssignees([], $owner->id);
    expect($query->people($actor, $project->id, false, '2026-11-02')->first()->weeks['2026-11-02']['planned'])->toBe(0)
        ->and($query->issues($actor, $project->id, false, '2026-11-02')->total())->toBe(2);
    expect(fn () => $save->handle($actor, $child->id, $person->id, '2026-11-02', '5', 1))->toThrow(ValidationException::class);
    $save->handle($actor, $child->id, $person->id, '2026-11-02', '', 1);
    expect(ProjectTaskEffort::query()->count())->toBe(2)->and($query->issues($actor, $project->id, false, '2026-11-02')->total())->toBe(1);
});

it('keeps own-only plans and coverage scoped without changing canonical work or inventing hours', function () {
    $owner = cpaUser();
    $person = cpaEmployee();
    $worker = cpaUser($person, ['TASKS']);
    $project = cpaProject($owner, cpaClient($owner, [$worker]));
    $task = cpaTask($owner, $project, [$person]);
    $peer = cpaEmployee();
    app(SetClientMember::class)->handle(Access::forUser($owner), $project->client_id, cpaUser($peer, ['TASKS'])->id, true);
    $other = cpaTask($owner, $project, [$peer]);
    $query = app(ProjectCapacity::class);
    $actor = Access::forUser($worker);
    expect($query->needsEstimate($actor, null, true, '2026-11-02'))->toBe(1)
        ->and($query->tasksForWeek($actor, null, true, $person->id, '2026-11-02')->pluck('id')->all())->toBe([$task->id]);
    app(SaveTaskEffort::class)->handle($actor, $task->id, $person->id, '2026-11-02', '0', 0);
    expect($query->needsEstimate($actor, null, true, '2026-11-02'))->toBe(0)
        ->and($query->people($actor, null, true, '2026-11-02')->first()->weeks['2026-11-02'])->toBe(['planned' => 0, 'available' => null, 'shared' => false]);
    expect(fn () => $query->estimate($actor, null, true, $other->id, $person->id, '2026-11-02'))->toThrow(HttpException::class);
    expect((new AccountReferences)->references($worker->id))->toBeTrue()->and((new EmployeeReferences)->references($person->id))->toBeTrue();
});

it('sums visible projects once and preserves client and portfolio scopes without hidden hours', function () {
    $owner = cpaUser();
    $other = cpaUser();
    $person = cpaEmployee();
    $worker = cpaUser($person);
    $client = cpaClient($owner, [$worker]);
    $second = cpaClient($owner, [$worker]);
    $portfolio = app(SavePortfolio::class)->handle(Access::forUser($owner), null, 'Delivery');
    ProjectClient::query()->findOrFail($client->id)->forceFill(['portfolio_id' => $portfolio->id])->save();
    $firstProject = cpaProject($owner, $client);
    $secondProject = cpaProject($owner, $second);
    $hiddenProject = cpaProject($other, cpaClient($other, [$worker]));
    $save = app(SaveTaskEffort::class);
    foreach ([[$owner, $firstProject, '6'], [$owner, $secondProject, '7'], [$other, $hiddenProject, '9']] as [$creator, $project, $hours]) {
        $task = cpaTask($creator, $project, [$person]);
        $save->handle(Access::forUser($creator), $task->id, $person->id, '2026-11-02', $hours, 0);
    }
    app(SaveProjectAvailability::class)->handle(Access::forUser($worker), '2026-11-02', '20', true, 0);
    $actor = Access::forUser($owner);
    $totals = (new ProjectCapacity)->totals($actor, null, false, '2026-11-02');
    expect($totals->plannedHundredths)->toBe(1300)->and($totals->personWeeks)->toBe(1)->and($totals->knownWeeks)->toBe(1)->and($totals->overloadedPeople)->toBe(0);
    expect((new ProjectCapacity)->people($actor, null, false, '2026-11-02')->first()->weeks['2026-11-02'])->toBe(['planned' => 1300, 'available' => 2000, 'shared' => true])
        ->and((new ProjectCapacity(client: $client->id))->people($actor, null, false, '2026-11-02')->first()->weeks['2026-11-02']['planned'])->toBe(600)
        ->and((new ProjectCapacity(portfolio: $portfolio->id))->people($actor, null, false, '2026-11-02')->first()->weeks['2026-11-02']['planned'])->toBe(600)
        ->and((new ProjectCapacity)->people($actor, $firstProject->id, false, '2026-11-02')->first()->weeks['2026-11-02']['planned'])->toBe(600);
});

it('keeps planning query rights aligned with the canonical task editor gate', function () {
    $owner = cpaUser();
    $editor = cpaUser();
    $viewer = cpaUser();
    $lead = cpaUser();
    $broad = cpaUser(allData: true);
    $outsider = cpaUser(allData: true);
    $project = cpaProject($owner, cpaClient($owner, [$editor, $viewer, $lead, $broad]));
    $project->forceFill(['manager_id' => $lead->id])->save();
    app(SetProjectMember::class)->handle(Access::forUser($owner), $project->id, $editor->id, 'EDITOR');
    app(SetProjectMember::class)->handle(Access::forUser($owner), $project->id, $viewer->id, 'VIEWER');
    foreach ([$owner, $editor, $viewer, $lead, $broad, $outsider] as $user) {
        $actor = Access::forUser($user);
        expect(PlanningScope::projects($actor)->whereKey($project->id)->exists())->toBe(WorkRules::editsWork($actor, $project));
    }
});

it('counts a person once when several weeks exceed their readable availability', function () {
    $owner = cpaUser();
    $employee = cpaEmployee();
    $worker = cpaUser($employee, ['TASKS']);
    $viewer = cpaUser();
    $project = cpaProject($owner, cpaClient($owner, [$worker, $viewer]));
    $actor = Access::forUser($owner);
    app(SetProjectMember::class)->handle($actor, $project->id, $viewer->id, 'VIEWER');
    $task = cpaTask($owner, $project, [$employee]);
    foreach (['2026-11-02', '2026-11-09'] as $week) {
        app(SaveProjectAvailability::class)->handle(Access::forUser($worker), $week, '10', true, 0);
        app(SaveTaskEffort::class)->handle($actor, $task->id, $employee->id, $week, '12', 0);
    }
    $queries = new ProjectCapacity;
    $totals = $queries->totals($actor, $project->id, false, '2026-11-02');
    expect($totals->plannedHundredths)->toBe(2400)->and($totals->personWeeks)->toBe(2)
        ->and($totals->knownWeeks)->toBe(2)->and($totals->overloadedPeople)->toBe(1);
    $private = $queries->totals(Access::forUser($viewer), $project->id, false, '2026-11-02');
    expect($private->knownWeeks)->toBe(0)->and($private->overloadedPeople)->toBe(0);
});
