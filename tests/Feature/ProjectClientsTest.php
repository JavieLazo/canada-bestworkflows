<?php

declare(strict_types=1);

use App\Livewire\Projects\ClientsPage;
use App\Livewire\Projects\NewClient;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\Project;
use App\Models\ProjectClient;
use App\Models\ProjectClientMember;
use App\Models\ProjectTask;
use App\Models\ProjectTaskSubmission;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\IdentityAccess\Application\AccountRetention;
use App\Modules\WorkManagement\Application\Commands\SaveClient;
use App\Modules\WorkManagement\Application\Commands\SetClientMember;
use App\Modules\WorkManagement\Application\Queries\ProjectClients;
use App\Modules\WorkManagement\Contracts\Views\ClientView;
use App\Support\Access;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** @param list<string> $modules */
function pclientUser(array $modules = ['PROJECTS'], bool $allData = false, bool $active = true): User
{
    $role = new Role;
    $role->forceFill(['name' => 'Client '.Str::random(8), 'all_data' => $allData, 'is_system' => false])->save();
    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->id, 'module' => $module])->save();
    }

    return User::factory()->create(['role_id' => $role->id, 'is_active' => $active, 'employee_id' => null]);
}

function pclientLogin(User $user): void
{
    test()->actingAs($user);
    app()->forgetInstance(Access::class);
}

function pclientCreate(User $owner, string $name = 'Acme'): ClientView
{
    return app(SaveClient::class)->handle(Access::forUser($owner), null, $name);
}

it('lets a permitted user create a private client without all-data access or an employee record', function () {
    $owner = pclientUser();
    pclientLogin($owner);
    $this->get(route('projects.clients'))->assertOk()->assertSee('No clients yet.');
    Livewire::test(NewClient::class)->call('open')->set('name', '  Acme  ')->call('save')
        ->assertHasNoErrors()->assertSet('editorOpen', false);

    $client = ProjectClient::query()->sole();
    expect($client->name)->toBe('Acme')->and($client->owner_id)->toBe($owner->id)
        ->and($client->members()->count())->toBe(0);
    $this->assertDatabaseHas('audit_logs', ['action' => 'CLIENT_CREATED', 'actor_id' => $owner->id, 'entity_id' => $client->id]);
    $this->get('/projects')->assertOk()->assertSee(route('projects.clients'));
});

it('requires Project Management access on reads and commands', function () {
    $this->get(route('projects.clients'))->assertRedirect(route('login'));
    $user = pclientUser(['TASKS'], true);
    pclientLogin($user);
    $this->getJson(route('projects.clients'))->assertForbidden();
    expect(fn () => pclientCreate($user))->toThrow(HttpException::class);
    expect(fn () => app(SaveClient::class)->handle(Access::forGuest(), null, 'Acme'))->toThrow(HttpException::class);
    expect(ProjectClient::query()->count())->toBe(0);
});

it('validates names at the command boundary', function (string $name) {
    $owner = pclientUser();
    pclientLogin($owner);
    Livewire::test(NewClient::class)->call('open')->set('name', $name)->call('save')->assertHasErrors('name');
    expect(fn () => pclientCreate($owner, $name))->toThrow(ValidationException::class);
    expect(ProjectClient::query()->count())->toBe(0);
})->with(['empty' => '', 'spaces' => '   ', 'too long' => str_repeat('x', 201)]);

it('keeps names unique per owner without revealing another owners client', function () {
    $first = pclientUser();
    $second = pclientUser();
    pclientCreate($first);
    expect(fn () => pclientCreate($first, ' Acme '))->toThrow(ValidationException::class);
    $other = pclientCreate($second);
    $page = app(ProjectClients::class)->page(Access::forUser($second), '', 1, 20);
    expect($page->total())->toBe(1)->and($page->first()->id)->toBe($other->id);
});

it('hides clients and people from outsiders even when they have all-data access', function () {
    $owner = pclientUser();
    $client = pclientCreate($owner, 'Hidden client');
    $outsider = pclientUser(allData: true);
    pclientLogin($outsider);
    $queries = app(ProjectClients::class);
    $actor = Access::forUser($outsider);
    expect($queries->client($actor, $client->id))->toBeNull()
        ->and($queries->page($actor, 'Hidden', 1, 20)->total())->toBe(0);
    expect(fn () => $queries->members($actor, $client->id))->toThrow(HttpException::class);
    expect(fn () => $queries->candidates($actor, $client->id))->toThrow(HttpException::class);
    Livewire::test(ClientsPage::class)->assertDontSee('Hidden client')->call('openClient', $client->id)->assertNotFound();
});

it('allows only the owner to rename a client and keeps its id and access', function () {
    $owner = pclientUser();
    $member = pclientUser(allData: true);
    $client = pclientCreate($owner);
    $access = Access::forUser($owner);
    app(SetClientMember::class)->handle($access, $client->id, $member->id, true);
    pclientLogin($member);
    Livewire::test(ClientsPage::class)->call('openRename', $client->id)->assertForbidden();
    expect(fn () => app(SaveClient::class)->handle(Access::forUser($member), $client->id, 'Changed'))->toThrow(HttpException::class);

    pclientLogin($owner);
    Livewire::test(ClientsPage::class)->call('openRename', $client->id)->set('name', 'Renamed')->call('saveClient')->assertHasNoErrors();
    expect(ProjectClient::query()->sole()->id)->toBe($client->id)
        ->and(ProjectClient::query()->sole()->name)->toBe('Renamed')
        ->and(ProjectClientMember::query()->where('client_id', $client->id)->sole()->user_id)->toBe($member->id);
    $this->assertDatabaseHas('audit_logs', ['action' => 'CLIENT_RENAMED', 'actor_id' => $owner->id, 'entity_id' => $client->id]);
});

it('shares with an existing permitted account and revokes access on its next request', function () {
    $owner = pclientUser();
    $member = pclientUser();
    $client = pclientCreate($owner);
    pclientLogin($owner);
    $page = Livewire::test(ClientsPage::class)->call('openClient', $client->id)->set('member', (string) $member->id);
    $page->call('addMember')->assertHasNoErrors()->assertSet('member', '')
        ->assertViewHas('members', fn ($members): bool => $members->pluck('id')->sort()->values()->all() === [$owner->id, $member->id]);
    expect(ProjectClientMember::query()->count())->toBe(1);
    pclientLogin($member);
    $open = Livewire::test(ClientsPage::class)->assertSee('Acme')->call('openClient', $client->id);
    expect(app(ProjectClients::class)->client(Access::forUser($member), $client->id)?->can_manage)->toBeFalse();

    app(SetClientMember::class)->handle(Access::forUser($owner), $client->id, $member->id, false);
    $open->set('search', 'Acme')->assertViewHas('client', null)->assertDontSee('Account #'.$owner->id);
    expect(app(ProjectClients::class)->page(Access::forUser($member), '', 1, 20)->total())->toBe(0);
    Livewire::test(ClientsPage::class)->call('openClient', $client->id)->assertNotFound();
    expect(ProjectClient::query()->count())->toBe(1);
    $this->assertDatabaseHas('audit_logs', ['action' => 'CLIENT_MEMBER_REMOVED', 'actor_id' => $owner->id, 'entity_id' => $client->id]);
});

it('refuses invalid, inactive and unpermitted membership candidates without changing roles', function () {
    $owner = pclientUser();
    $client = pclientCreate($owner);
    $inactive = pclientUser(active: false);
    $unpermitted = pclientUser(['LEAVES']);
    $before = RoleModuleGrant::query()->count();
    foreach ([$inactive->id, $unpermitted->id, 999999, -1] as $candidate) {
        expect(fn () => app(SetClientMember::class)->handle(Access::forUser($owner), $client->id, $candidate, true))->toThrow(ValidationException::class);
    }
    pclientLogin($owner);
    Livewire::test(ClientsPage::class)->call('openClient', $client->id)->set('member', 'invalid')->call('addMember')->assertHasErrors('member');
    expect(ProjectClientMember::query()->count())->toBe(0)->and(RoleModuleGrant::query()->count())->toBe($before);
});

it('refuses member changes by ordinary members, outsiders and all-data administrators', function () {
    $owner = pclientUser();
    $member = pclientUser();
    $outsider = pclientUser(allData: true);
    $client = pclientCreate($owner);
    app(SetClientMember::class)->handle(Access::forUser($owner), $client->id, $member->id, true);
    foreach ([$member, $outsider] as $actor) {
        foreach ([true, false] as $include) {
            expect(fn () => app(SetClientMember::class)->handle(Access::forUser($actor), $client->id, $member->id, $include))->toThrow(HttpException::class);
        }
    }
    expect(ProjectClientMember::query()->count())->toBe(1);
});

it('keeps the owners access and makes duplicate additions and removals harmless', function () {
    $owner = pclientUser();
    $member = pclientUser();
    $client = pclientCreate($owner);
    $actor = Access::forUser($owner);
    $command = app(SetClientMember::class);
    foreach ([true, false] as $include) {
        expect(fn () => $command->handle($actor, $client->id, $owner->id, $include))->toThrow(ValidationException::class);
    }
    expect($command->handle($actor, $client->id, $member->id, true))->toBeTrue()
        ->and($command->handle($actor, $client->id, $member->id, true))->toBeFalse()
        ->and($command->handle($actor, $client->id, $member->id, false))->toBeTrue()
        ->and($command->handle($actor, $client->id, $member->id, false))->toBeFalse();
    expect(AuditLog::query()->where('action', 'CLIENT_MEMBER_ADDED')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'CLIENT_MEMBER_REMOVED')->count())->toBe(1);
});

it('retains the owner account and permits cleanup of a removed membership', function () {
    $owner = pclientUser();
    $member = pclientUser();
    $client = pclientCreate($owner);
    app(SetClientMember::class)->handle(Access::forUser($owner), $client->id, $member->id, true);
    expect(app(AccountRetention::class)->any($owner->id))->toBeTrue()
        ->and(app(AccountRetention::class)->any($member->id))->toBeFalse();
    $member->delete();
    expect(ProjectClientMember::query()->count())->toBe(0);
    expect(fn () => $owner->delete())->toThrow(QueryException::class);
    expect(ProjectClient::query()->count())->toBe(1);
});

it('removes module access on the next request without removing client records', function () {
    $owner = pclientUser();
    $client = pclientCreate($owner);
    pclientLogin($owner);
    $page = Livewire::test(ClientsPage::class)->call('openRename', $client->id);
    RoleModuleGrant::query()->where('role_id', $owner->role_id)->where('module', 'PROJECTS')->delete();
    pclientLogin($owner->fresh());
    $page->set('name', 'Changed')->call('saveClient')->assertForbidden();
    $this->getJson(route('projects.clients'))->assertForbidden();
    expect(ProjectClient::query()->sole()->name)->toBe('Acme');
});

it('signs a deactivated owner out on their next request', function () {
    $owner = pclientUser();
    pclientCreate($owner);
    pclientLogin($owner);
    $owner->forceFill(['is_active' => false])->save();
    $this->get(route('projects.clients'))->assertRedirect('/');
    $this->assertGuest();
    expect(ProjectClient::query()->count())->toBe(1);
});

it('locks the selected client against browser tampering', function () {
    $owner = pclientUser();
    $client = pclientCreate($owner);
    $hidden = pclientCreate(pclientUser(), 'Hidden');
    pclientLogin($owner);
    expect(fn () => Livewire::test(ClientsPage::class)->call('openClient', $client->id)->set('clientId', $hidden->id))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('paginates and searches only visible clients with detached view data', function () {
    $owner = pclientUser();
    $actor = Access::forUser($owner);
    for ($i = 1; $i <= 21; $i++) {
        pclientCreate($owner, sprintf('Client %02d', $i));
    }
    pclientCreate(pclientUser(), 'Client hidden');
    $literal = pclientCreate($owner, '100%_Ready');
    $queries = app(ProjectClients::class);
    $first = $queries->page($actor, '', 1, 20);
    $last = $queries->page($actor, '', 999, 20);
    expect($first->total())->toBe(22)->and($first->count())->toBe(20)
        ->and($first->first())->toBeInstanceOf(ClientView::class)
        ->and($last->currentPage())->toBe(2)->and($last->count())->toBe(2)
        ->and($queries->page($actor, '%_', 1, 20)->pluck('id')->all())->toBe([$literal->id]);
    pclientLogin($owner);
    Livewire::test(ClientsPage::class)->set('perPage', 99999)->assertSet('perPage', 20);
});

it('escapes client names in the list and dialog', function () {
    $owner = pclientUser();
    $client = pclientCreate($owner, '<script>unsafe()</script>');
    pclientLogin($owner);
    Livewire::test(ClientsPage::class)->assertDontSeeHtml('<script>unsafe()</script>')
        ->assertSee('&lt;script&gt;unsafe()&lt;/script&gt;', false);
    Livewire::test(ClientsPage::class)->call('openClient', $client->id)->assertDontSeeHtml('<script>unsafe()</script>');
});

it('keeps legacy projects, assignments and reviewed submissions intact through client actions and migration reruns', function () {
    $employee = new EmployeeProfile;
    $employee->forceFill(['employee_code' => 'CLIENT-LEGACY', 'first_name' => 'Legacy', 'last_name' => 'Member', 'date_hired' => '2026-01-01', 'is_active' => true])->save();
    $project = Project::create(['code' => 'PRJ-LEGACY', 'name' => 'Legacy work', 'status' => 'ACTIVE', 'start_date' => '2026-01-01']);
    $task = ProjectTask::create(['project_id' => $project->id, 'code' => 'TSK-LEGACY', 'title' => 'Reviewed work', 'status' => 'COMPLETED', 'priority' => 'NORMAL', 'submission_mode' => 'INDIVIDUAL']);
    $task->syncAssignees([$employee->id], null);
    $response = new ProjectTaskSubmission;
    $response->forceFill(['task_id' => $task->id, 'employee_id' => $employee->id, 'status' => 'APPROVED', 'remarks' => 'Original hand-in', 'review_remarks' => 'Accepted', 'revision' => 3, 'submitted_at' => now()])->save();
    $tables = ['projects', 'project_tasks', 'project_task_assignees', 'project_task_submissions'];
    $before = array_map(fn (string $table): string => DB::table($table)->orderBy('id')->get()->toJson(), $tables);
    $owner = pclientUser();
    $member = pclientUser();
    $client = pclientCreate($owner);
    app(SetClientMember::class)->handle(Access::forUser($owner), $client->id, $member->id, true);
    app(SaveClient::class)->handle(Access::forUser($owner), $client->id, 'Renamed');
    app(SetClientMember::class)->handle(Access::forUser($owner), $client->id, $member->id, false);
    $clientsMigration = require database_path('migrations/2026_10_31_0001_create_project_clients_table.php');
    $membersMigration = require database_path('migrations/2026_10_31_0002_create_project_client_members_table.php');
    $clientsMigration->up();
    $membersMigration->up();
    expect(ProjectClient::query()->sole()->id)->toBe($client->id);
    $associationMigration = require database_path('migrations/2026_10_31_0003_add_client_to_projects_table.php');
    if (DB::connection()->getDriverName() === 'sqlite') {
        expect(fn () => $associationMigration->down())->toThrow(RuntimeException::class);
    }
    $after = array_map(fn (string $table): string => DB::table($table)->orderBy('id')->get()->toJson(), $tables);
    expect($after)->toBe($before);
});
