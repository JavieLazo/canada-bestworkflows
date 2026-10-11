<?php

declare(strict_types=1);

use App\Livewire\Notifications\Index as NotificationPage;
use App\Livewire\Shell\NotificationBell;
use App\Models\EmployeeDocument;
use App\Models\EmployeeProfile;
use App\Models\LeaveTravelRequest;
use App\Models\Notification;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Platform\Notifications\Inbox;
use App\Platform\Notifications\Notifier;
use App\Platform\Notifications\SweepReminders;
use App\Support\Access;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

/** Reminders must preserve scope, priority and deduplication with bounded reads and truthful outcomes. */

/** @param list<string> $modules */
function reminderSafeUser(array $modules, bool $allData = false, ?EmployeeProfile $employee = null): User
{
    $role = Role::create(['name' => 'Reminder '.Str::random(8), 'all_data' => $allData, 'is_system' => false]);
    foreach ($modules as $module) {
        RoleModuleGrant::create(['role_id' => $role->id, 'module' => $module]);
    }

    return User::factory()->create(['role_id' => $role->id, 'employee_id' => $employee?->id, 'is_active' => true]);
}

function reminderSafeEmployee(string $code, bool $probation = false): EmployeeProfile
{
    return EmployeeProfile::create(['employee_code' => $code, 'first_name' => 'Synthetic', 'last_name' => $code,
        'date_hired' => $probation ? '2026-03-20' : '2020-01-01', 'date_regular' => $probation ? null : '2020-07-01',
        'employment_status' => $probation ? 'Probationary' : 'Regular', 'is_active' => true]);
}

function reminderSafeDocument(EmployeeProfile $employee): EmployeeDocument
{
    return EmployeeDocument::create(['employee_id' => $employee->id, 'name' => $employee->last_name.' confidential document',
        'category' => 'Other', 'expires_on' => '2026-09-01', 'file_name' => 'synthetic.txt',
        'mime' => 'text/plain', 'size' => 1, 'path' => 'employee-documents/'.Str::random(8)]);
}

function reminderSafeLeave(EmployeeProfile $employee): LeaveTravelRequest
{
    $leave = new LeaveTravelRequest;
    $leave->forceFill(['employee_id' => $employee->id, 'code' => 'LV-'.Str::random(8), 'request_type' => 'SICK_LEAVE',
        'day_type' => 'WHOLE_DAY', 'start_date' => '2026-09-20', 'end_date' => '2026-09-20', 'status' => 'PENDING',
        'is_paid' => true, 'created_at' => '2026-09-01 00:00:00'])->save();

    return $leave;
}

function reminderSafeActor(User $user): Access
{
    test()->actingAs($user->fresh());
    app()->forgetInstance(Access::class);

    return app(Access::class);
}

it('sends HR facts only within employee scope and leave reminders only to non-self reviewers', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-17 03:00:00', 'UTC'));
    $own = reminderSafeEmployee('OWN', true);
    $other = reminderSafeEmployee('OTHER', true);
    foreach ([$own, $other] as $employee) {
        reminderSafeDocument($employee);
        reminderSafeLeave($employee);
    }
    $restricted = reminderSafeUser(['EMPLOYEES', 'LEAVES'], false, $own);
    $unlinked = reminderSafeUser(['EMPLOYEES', 'LEAVES']);
    $manager = reminderSafeUser(['EMPLOYEES', 'LEAVES'], true, $own);
    $unrelated = reminderSafeUser(['PORTAL'], true);
    $inactive = reminderSafeUser(['EMPLOYEES', 'LEAVES'], true);
    $inactive->update(['is_active' => false]);
    $result = app(SweepReminders::class)->handle();
    expect($result['errors'])->toBe(0);
    $rows = Notification::query()->where('user_id', $restricted->id)->get();
    expect($rows->pluck('kind')->sort()->values()->all())->toBe(['DOCUMENT_EXPIRED', 'PROBATION_ENDING'])
        ->and($rows->pluck('body')->implode(' '))->not->toContain('OTHER');
    // A document reminder opens the record on its Documents tab, the only
    // place the record lists documents; a probation reminder opens Profile.
    expect($rows->firstWhere('kind', 'DOCUMENT_EXPIRED')->href)->toBe('/employees/'.$own->id.'?tab=documents')
        ->and($rows->firstWhere('kind', 'PROBATION_ENDING')->href)->toBe('/employees/'.$own->id);
    foreach ([$unlinked, $unrelated, $inactive] as $user) {
        expect(Notification::query()->where('user_id', $user->id)->count())->toBe(0);
    }
    $reviews = Notification::query()->where('user_id', $manager->id)->where('kind', 'LEAVE_PENDING')->get();
    expect($reviews->count())->toBe(1)->and($reviews[0]->body)->toContain('OTHER')->not->toContain('OWN');
    expect(app(SweepReminders::class)->handle()['written'])->toBe(0);
});

it('hides existing private reminders after scope or employee-link changes without deleting them', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-17 03:00:00', 'UTC'));
    $own = reminderSafeEmployee('OWN');
    $other = reminderSafeEmployee('OTHER');
    reminderSafeDocument($own);
    reminderSafeDocument($other);
    reminderSafeLeave($other);
    $user = reminderSafeUser(['EMPLOYEES', 'LEAVES'], true, $own);
    app(SweepReminders::class)->handle();
    $actor = reminderSafeActor($user);
    $inbox = app(Inbox::class);
    expect($inbox->latest($actor, 500)->count())->toBe(3);
    $otherRow = Notification::query()->where('user_id', $user->id)->where('title', 'OTHER confidential document has expired')->sole();
    $user->role->update(['all_data' => false]);
    $actor = reminderSafeActor($user);
    expect($inbox->latest($actor, 500)->count())->toBe(1)
        ->and($inbox->summary($actor)['unread'])->toBe(1)
        ->and($inbox->markRead($actor, $otherRow->id))->toBeNull();
    Livewire::test(NotificationPage::class)->assertDontSee('OTHER confidential')->assertDontSee('LV-');
    Livewire::test(NotificationBell::class)->assertDontSee('OTHER confidential');
    $inbox->markAllRead($actor);
    expect($otherRow->fresh()->read_at)->toBeNull()->and(Notification::query()->count())->toBe(3);
    $user->update(['employee_id' => null]);
    expect($inbox->latest(reminderSafeActor($user), 500))->toBeEmpty();
});

it('refreshes the inbox poll when reminder authorization changes without a notification write', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-17 03:00:00', 'UTC'));
    reminderSafeDocument(reminderSafeEmployee('SECRET'));
    $user = reminderSafeUser(['EMPLOYEES'], true);
    app(SweepReminders::class)->handle();
    reminderSafeActor($user);
    $bell = Livewire::test(NotificationBell::class)->assertSee('SECRET confidential');
    $user->role->update(['all_data' => false]);
    reminderSafeActor($user);
    $bell->call('pollForChanges')->assertDontSee('SECRET confidential');
});

it('preserves the global twenty-row cap across batches and rule groups', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-17 03:00:00', 'UTC'));
    $employee = reminderSafeEmployee('BATCH');
    $user = reminderSafeUser(['TASKS', 'PROJECTS'], true, $employee);
    $project = new Project;
    $project->forceFill(['code' => 'PRJ-BATCH', 'name' => 'Synthetic batch project', 'status' => 'ACTIVE',
        'start_date' => '2026-01-01', 'due_date' => '2026-09-10', 'manager_id' => $user->id])->save();
    $keys = [];
    for ($i = 0; $i < 230; $i++) {
        $task = ProjectTask::create(['code' => sprintf('TSK-%04d', $i), 'project_id' => $project->id, 'title' => 'Synthetic task',
            'status' => 'PENDING', 'priority' => 'NORMAL', 'due_date' => '2026-09-10']);
        $task->syncAssignees([$employee->id], $user->id);
        $keys[$task->id] = 'TASK_OVERDUE:'.$task->id.':2026-09-10';
    }
    ksort($keys);
    foreach (array_slice($keys, 0, 205) as $key) {
        $row = new Notification;
        $row->forceFill(['user_id' => $user->id, 'kind' => 'TASK_OVERDUE', 'title' => 'Already seen', 'dedupe_key' => $key])->save();
    }
    $first = app(SweepReminders::class)->handle();
    expect($first['written'])->toBe(20)->and($first['errors'])->toBe(0);
    expect(Notification::query()->where('user_id', $user->id)->where('kind', 'PROJECT_OVERDUE')->count())->toBe(0);
    $actual = Notification::query()->where('user_id', $user->id)->where('title', '!=', 'Already seen')->orderBy('id')->pluck('dedupe_key')->all();
    expect($actual)->toBe(array_values(array_slice($keys, 205, 20)));
    expect(app(SweepReminders::class)->handle()['written'])->toBe(6);
    expect(app(SweepReminders::class)->handle()['written'])->toBe(0);
});

it('continues mixed reminder categories in their original priority on the next sweep', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-17 03:00:00', 'UTC'));
    $own = reminderSafeEmployee('MIXED-OWN');
    $user = reminderSafeUser(['TASKS', 'PROJECTS', 'LEAVES', 'EMPLOYEES'], true, $own);
    for ($i = 0; $i < 4; $i++) {
        $employee = reminderSafeEmployee('MIXED-'.$i, true);
        reminderSafeDocument($employee);
        reminderSafeLeave($employee);
    }
    for ($i = 0; $i < 7; $i++) {
        $project = new Project;
        $project->forceFill(['code' => 'PRJ-MIXED-'.$i, 'name' => 'Synthetic '.$i, 'status' => 'ACTIVE',
            'start_date' => '2026-01-01', 'due_date' => '2026-09-10', 'manager_id' => $user->id])->save();
        foreach (['PENDING', 'SUBMITTED'] as $status) {
            $task = new ProjectTask;
            $task->forceFill(['code' => 'TSK-'.$status.'-'.$i, 'project_id' => $project->id, 'title' => 'Synthetic',
                'status' => $status, 'priority' => 'NORMAL', 'due_date' => '2026-09-10',
                'submitted_at' => $status === 'SUBMITTED' ? '2026-09-10 00:00:00' : null, 'assigned_by' => $user->id])->save();
            $task->syncAssignees([$own->id], $user->id);
        }
    }
    expect(app(SweepReminders::class)->handle()['written'])->toBe(20);
    $first = Notification::query()->where('user_id', $user->id)->orderBy('id')->pluck('kind')->all();
    expect($first)->toBe([...array_fill(0, 7, 'TASK_OVERDUE'), ...array_fill(0, 7, 'TASK_REVIEW_PENDING'), ...array_fill(0, 6, 'PROJECT_OVERDUE')]);
    expect(app(SweepReminders::class)->handle()['written'])->toBe(13);
    $rest = Notification::query()->where('user_id', $user->id)->orderBy('id')->offset(20)->limit(20)->pluck('kind')->all();
    expect($rest)->toBe(['PROJECT_OVERDUE', ...array_fill(0, 4, 'LEAVE_PENDING'), ...array_fill(0, 4, 'DOCUMENT_EXPIRED'), ...array_fill(0, 4, 'PROBATION_ENDING')]);
    expect(app(SweepReminders::class)->handle()['written'])->toBe(0);
});

it('reports failed reminder inserts and retries them without recording false success', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-17 03:00:00', 'UTC'));
    reminderSafeDocument(reminderSafeEmployee('FAILURE'));
    reminderSafeUser(['EMPLOYEES'], true);
    $failure = new class
    {
        public bool $enabled = true;
    };
    DB::connection()->beforeExecuting(static function (string $sql) use ($failure): void {
        if ($failure->enabled && str_starts_with(strtolower($sql), 'insert') && str_contains($sql, 'notifications')) {
            throw new RuntimeException('Synthetic reminder insert failure');
        }
    });
    expect(Artisan::call('reminders:sweep'))->toBe(1)
        ->and(Artisan::output())->toContain('wrote 0 reminders', 'failed 1')
        ->and(Notification::query()->count())->toBe(0);
    $failure->enabled = false;
    expect(app(SweepReminders::class)->handle()['written'])->toBe(1)
        ->and(app(SweepReminders::class)->handle()['written'])->toBe(0)
        ->and(Notification::query()->count())->toBe(1);
});

it('distinguishes duplicate reminders and refuses oversized keys', function () {
    $user = reminderSafeUser([]);
    $rows = [['dedupeKey' => 'TASK_OVERDUE:synthetic:2026-09-01', 'kind' => 'TASK_OVERDUE', 'title' => 'Synthetic', 'body' => null, 'href' => '/my-tasks']];
    expect(Notifier::reminders($user->id, $rows))->toBe(['inserted' => 1, 'duplicates' => 0, 'failed' => 0, 'errors' => 0]);
    expect(Notifier::reminders($user->id, $rows))->toBe(['inserted' => 0, 'duplicates' => 1, 'failed' => 0, 'errors' => 0]);
    $rows[0]['dedupeKey'] = str_repeat('x', 192);
    expect(Notifier::reminders($user->id, $rows)['failed'])->toBe(1)->and(Notification::query()->count())->toBe(1);
});

it('hides a historical task reminder after the employee is removed from the task', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-17 03:00:00', 'UTC'));
    $own = reminderSafeEmployee('WORKER');
    $other = reminderSafeEmployee('REPLACEMENT');
    $user = reminderSafeUser(['TASKS'], false, $own);
    $project = Project::create(['code' => 'PRJ-SCOPE', 'name' => 'Synthetic', 'status' => 'ACTIVE', 'start_date' => '2026-01-01']);
    $task = ProjectTask::create(['code' => 'TSK-SCOPE', 'project_id' => $project->id, 'title' => 'Private task', 'status' => 'PENDING', 'priority' => 'NORMAL', 'due_date' => '2026-09-10']);
    $task->syncAssignees([$own->id], null);
    app(SweepReminders::class)->handle();
    $actor = reminderSafeActor($user);
    $inbox = app(Inbox::class);
    $row = $inbox->latest($actor, 10)->sole();
    $task->syncAssignees([$other->id], null);
    expect($inbox->latest($actor, 10))->toBeEmpty()
        ->and($inbox->summary($actor)['unread'])->toBe(0)
        ->and($inbox->markRead($actor, $row->id))->toBeNull()
        ->and($row->fresh()->read_at)->toBeNull();
});

it('gives a restricted former manager who still leads the project its project reminder, and never a review reminder', function () {
    // DELIBERATE DIVERGENCE: being named a project's Task Leader opens that
    // project to the account (WorkScope), so a PROJECTS holder without
    // all_data who is still `manager_id` hears that it is overdue — off
    // every crew, and with no employee link. The review chase stays with
    // an all-data assigner: `assigned_by` alone earns nothing.
    $this->travelTo(CarbonImmutable::parse('2026-09-17 03:00:00', 'UTC'));
    $user = reminderSafeUser(['PROJECTS']);
    $employee = reminderSafeEmployee('WORKER');
    $project = new Project;
    $project->forceFill(['code' => 'PRJ-PRIVATE', 'name' => 'Synthetic', 'status' => 'ACTIVE', 'start_date' => '2026-01-01',
        'due_date' => '2026-09-10', 'manager_id' => $user->id])->save();
    $task = new ProjectTask;
    $task->forceFill(['code' => 'TSK-PRIVATE', 'project_id' => $project->id, 'title' => 'Synthetic', 'status' => 'SUBMITTED',
        'priority' => 'NORMAL', 'submitted_at' => '2026-09-10 00:00:00', 'assigned_by' => $user->id])->save();
    $task->syncAssignees([$employee->id], $user->id);
    expect(app(SweepReminders::class)->handle()['written'])->toBe(1)
        ->and(Notification::query()->where('user_id', $user->id)->pluck('kind')->all())->toBe(['PROJECT_OVERDUE']);
});

it('stops the project reminders of a leader who was named away, or whose role lost PROJECTS', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-17 03:00:00', 'UTC'));
    $former = reminderSafeUser(['PROJECTS']);
    $unmoduled = reminderSafeUser(['TASKS']);
    $other = reminderSafeUser(['PROJECTS']);
    foreach ([['PRJ-GONE', $other->id], ['PRJ-NOMOD', $unmoduled->id]] as [$code, $leader]) {
        $project = new Project;
        $project->forceFill(['code' => $code, 'name' => $code, 'status' => 'ACTIVE', 'start_date' => '2026-01-01',
            'due_date' => '2026-09-10', 'manager_id' => $leader])->save();
    }
    app(SweepReminders::class)->handle();
    expect(Notification::query()->where('user_id', $former->id)->count())->toBe(0)
        ->and(Notification::query()->where('user_id', $unmoduled->id)->count())->toBe(0)
        ->and(Notification::query()->where('user_id', $other->id)->where('kind', 'PROJECT_OVERDUE')->count())->toBe(1);
});
