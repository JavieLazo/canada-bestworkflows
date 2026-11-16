<?php

declare(strict_types=1);

use App\Livewire\Projects\CapacityOverview;
use App\Livewire\Projects\CapacityWorkspace;
use App\Livewire\Projects\Index;
use App\Livewire\Projects\Show;
use App\Livewire\Tasks\MyTasksPage;
use App\Models\ProjectAvailability;
use App\Models\ProjectTaskEffort;
use App\Modules\WorkManagement\Application\Commands\SetClientMember;
use App\Support\Access;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

require_once __DIR__.'/../Support/ClientProjectFixtures.php';

beforeEach(function (): void {
    $this->travelTo(new DateTimeImmutable('2026-11-03T12:00:00+08:00'));
});

it('opens the shared project and own-only capacity views through their existing workspaces', function () {
    $employee = cpaEmployee();
    $owner = cpaUser($employee);
    $project = cpaProject($owner, cpaClient($owner));
    cpaTask($owner, $project, [$employee]);
    cpaLogin($owner);
    Livewire::test(Show::class, ['project' => $project->id])->set('taskView', 'capacity')->assertSee('Team availability');
    Livewire::test(Index::class)->set('section', 'capacity')->assertSee('Team availability')->assertDontSee('My availability')
        ->assertSee('Availability covers all project work. Planned hours include only work you can access.');
    Livewire::test(CapacityOverview::class, ['projectId' => $project->id])->assertSee('Not set')->assertSee('Need estimates')
        ->assertViewHas('capacityTotals', fn ($totals): bool => $totals->knownWeeks === 0 && $totals->needsEstimate === 1);
    Livewire::test(MyTasksPage::class)->set('taskView', 'capacity')->assertSee('Your availability')->assertDontSee('Search my tasks');
});

it('edits explicit weekly availability and estimates without duplicating or changing task assignments', function () {
    $employee = cpaEmployee('Mara');
    $owner = cpaUser($employee);
    $project = cpaProject($owner, cpaClient($owner));
    $task = cpaTask($owner, $project, [$employee]);
    cpaLogin($owner);
    $page = Livewire::test(CapacityWorkspace::class, ['projectId' => $project->id])->call('openAvailability', '2026-11-02')
        ->set('hours', '30')->set('shared', true)->call('saveAvailability')->assertHasNoErrors()->assertSet('availabilityOpen', false)
        ->call('openWeek', $employee->id, '2026-11-02')->assertSee($task->title)->call('openEffort', $task->id, $employee->id, '2026-11-02')
        ->set('hours', '35')->call('saveEffort')->assertHasNoErrors()->assertSet('effortOpen', false)->assertSee('35');
    expect(ProjectAvailability::query()->sole()->hours_hundredths)->toBe(3000)->and(ProjectTaskEffort::query()->sole()->hours_hundredths)->toBe(3500);
    $page->call('openEffort', $task->id, $employee->id, '2026-11-02')->set('hours', '168.01')->call('saveEffort')->assertHasErrors('hours')->assertSet('effortInline', true);
    expect(ProjectTaskEffort::query()->sole()->hours_hundredths)->toBe(3500)->and($task->fresh()->assigneeIds())->toBe([$employee->id]);
});

it('locks planning identity and refuses a client removal while a form is open', function () {
    $owner = cpaUser();
    $employee = cpaEmployee();
    $worker = cpaUser($employee, ['TASKS']);
    $client = cpaClient($owner, [$worker]);
    $project = cpaProject($owner, $client);
    $task = cpaTask($owner, $project, [$employee]);
    cpaLogin($worker);
    foreach (['own' => false, 'personId' => 'other', 'effortRevision' => 99] as $property => $value) {
        expect(fn () => Livewire::test(CapacityWorkspace::class, ['own' => true])->set($property, $value))->toThrow(CannotUpdateLockedPropertyException::class);
    }
    $page = Livewire::test(CapacityWorkspace::class, ['own' => true])->call('openEffort', $task->id, $employee->id, '2026-11-02')->set('hours', '10');
    app(SetClientMember::class)->handle(Access::forUser($owner), $client->id, $worker->id, false);
    $page->call('saveEffort')->assertForbidden();
    expect(ProjectTaskEffort::query()->count())->toBe(0);
});
