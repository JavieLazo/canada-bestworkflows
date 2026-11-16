<?php

declare(strict_types=1);

use App\Models\ProjectClient;
use App\Models\ProjectPortfolio;
use App\Models\ProjectPortfolioMember;
use App\Models\RoleModuleGrant;
use App\Modules\IdentityAccess\Application\AccountRetention;
use App\Modules\WorkManagement\Application\Commands\ArchivePortfolio;
use App\Modules\WorkManagement\Application\Commands\OrganizeClient;
use App\Modules\WorkManagement\Application\Commands\SaveClient;
use App\Modules\WorkManagement\Application\Commands\SavePortfolio;
use App\Modules\WorkManagement\Application\Commands\SetClientMember;
use App\Modules\WorkManagement\Application\Commands\SetPortfolioMember;
use App\Modules\WorkManagement\Application\Commands\SetProjectMember;
use App\Modules\WorkManagement\Application\Queries\ProjectClients;
use App\Modules\WorkManagement\Application\Queries\ProjectPortfolios;
use App\Modules\WorkManagement\Application\Queries\WorkLists;
use App\Modules\WorkManagement\Application\Queries\WorkSummary;
use App\Modules\WorkManagement\Infrastructure\WorkScope;
use App\Support\Access;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/../Support/ClientProjectFixtures.php';

it('creates an owned portfolio without broad data access and keeps legacy work intact', function () {
    $owner = cpaUser();
    $client = cpaClient($owner);
    $project = cpaProject($owner, $client);
    $task = cpaTask($owner, $project, []);
    $before = [$project->getRawOriginal(), $task->getRawOriginal(), RoleModuleGrant::query()->count()];
    $portfolio = app(SavePortfolio::class)->handle(Access::forUser($owner), null, '  Delivery  ', '  Client work  ');
    expect($portfolio->name)->toBe('Delivery')->and($portfolio->description)->toBe('Client work')
        ->and($portfolio->owner_id)->toBe($owner->id)->and($portfolio->people_count)->toBe(1)
        ->and($portfolio->archived_at)->toBeNull()->and(ProjectClient::query()->sole()->portfolio_id)->toBeNull()
        ->and([$project->fresh()->getRawOriginal(), $task->fresh()->getRawOriginal(), RoleModuleGrant::query()->count()])->toBe($before);
    $this->assertDatabaseHas('audit_logs', ['action' => 'PORTFOLIO_CREATED', 'entity_id' => $portfolio->id, 'actor_id' => $owner->id]);
    expect(app(AccountRetention::class)->any($owner->id))->toBeTrue();
});

it('validates portfolio names and descriptions at the command boundary', function (string $name, string $description) {
    expect(fn () => app(SavePortfolio::class)->handle(Access::forUser(cpaUser()), null, $name, $description))->toThrow(ValidationException::class);
    expect(ProjectPortfolio::query()->count())->toBe(0);
})->with([[' ', ''], [str_repeat('n', 201), ''], ['Delivery', str_repeat('d', 4001)]]);

it('retains account attribution when a portfolio is its only owned work', function () {
    $owner = cpaUser();
    expect(app(AccountRetention::class)->any($owner->id))->toBeFalse();
    app(SavePortfolio::class)->handle(Access::forUser($owner), null, 'Delivery');
    expect(app(AccountRetention::class)->any($owner->id))->toBeTrue();
});

it('keeps portfolio names unique per owner and prevents outsiders from discovering them', function () {
    $owner = cpaUser();
    $other = cpaUser(allData: true);
    $first = app(SavePortfolio::class)->handle(Access::forUser($owner), null, 'Delivery');
    expect(fn () => app(SavePortfolio::class)->handle(Access::forUser($owner), null, ' Delivery '))->toThrow(ValidationException::class);
    $second = app(SavePortfolio::class)->handle(Access::forUser($other), null, 'Delivery');
    $queries = app(ProjectPortfolios::class);
    expect($queries->options(Access::forUser($other))->pluck('id')->all())->toBe([$second->id])
        ->and($queries->portfolio(Access::forUser($other), $first->id))->toBeNull();
    expect(fn () => $queries->members(Access::forUser($other), $first->id))->toThrow(HttpException::class);
    expect(fn () => app(SavePortfolio::class)->handle(Access::forUser($other), $first->id, 'Taken'))->toThrow(HttpException::class);
});

it('requires current Project Management access for portfolio creation and contribution', function () {
    $owner = cpaUser();
    $portfolio = app(SavePortfolio::class)->handle(Access::forUser($owner), null, 'Delivery');
    foreach ([Access::forGuest(), Access::forUser(cpaUser(modules: ['TASKS'], allData: true))] as $actor) {
        expect(fn () => app(SavePortfolio::class)->handle($actor, null, 'Blocked'))->toThrow(HttpException::class);
        expect(app(ProjectPortfolios::class)->options($actor))->toBeEmpty();
        expect(fn () => app(SaveClient::class)->handle($actor, null, 'Blocked', $portfolio->id))->toThrow(HttpException::class);
    }
});

it('shares portfolio organization without granting client project task or administration access', function () {
    $owner = cpaUser();
    $member = cpaUser();
    $actor = Access::forUser($owner);
    $portfolio = app(SavePortfolio::class)->handle($actor, null, 'Delivery');
    $client = app(SaveClient::class)->handle($actor, null, 'Private client', $portfolio->id);
    $project = cpaProject($owner, $client);
    cpaTask($owner, $project, []);
    app(SetPortfolioMember::class)->handle($actor, $portfolio->id, $member->id, true);
    $reader = Access::forUser($member);
    $queries = app(ProjectPortfolios::class);
    expect($queries->portfolio($reader, $portfolio->id)?->can_manage)->toBeFalse()
        ->and($queries->counts($reader, [$portfolio->id])[$portfolio->id])->toBe(['clients' => 0, 'projects' => 0])
        ->and(WorkScope::projects($reader, $portfolio->id)->count())->toBe(0)->and(WorkScope::tasks($reader, true)->count())->toBe(0);
    expect(fn () => app(OrganizeClient::class)->handle($reader, $client->id, null, $portfolio->id))->toThrow(HttpException::class);
    expect(fn () => app(ArchivePortfolio::class)->handle($reader, $portfolio->id, true))->toThrow(HttpException::class);
    expect(fn () => app(SetPortfolioMember::class)->handle($reader, $portfolio->id, $owner->id, false))->toThrow(HttpException::class);
    app(SetClientMember::class)->handle($actor, $client->id, $member->id, true);
    expect($queries->counts($reader, [$portfolio->id])[$portfolio->id])->toBe(['clients' => 1, 'projects' => 0]);
    app(SetProjectMember::class)->handle($actor, $project->id, $member->id, 'VIEWER');
    expect($queries->counts($reader, [$portfolio->id])[$portfolio->id])->toBe(['clients' => 1, 'projects' => 1]);
});

it('lets members contribute their own clients and keeps their work when portfolio access is revoked', function () {
    $owner = cpaUser();
    $member = cpaUser();
    $actor = Access::forUser($owner);
    $portfolio = app(SavePortfolio::class)->handle($actor, null, 'Delivery');
    app(SetPortfolioMember::class)->handle($actor, $portfolio->id, $member->id, true);
    $reader = Access::forUser($member);
    $client = app(SaveClient::class)->handle($reader, null, 'Member client', $portfolio->id);
    $project = cpaProject($member, $client);
    $task = cpaTask($member, $project, []);
    expect(app(ProjectPortfolios::class)->counts($actor, [$portfolio->id])[$portfolio->id])->toBe(['clients' => 0, 'projects' => 0]);
    $before = $task->getRawOriginal();
    app(SetPortfolioMember::class)->handle($actor, $portfolio->id, $member->id, false);
    expect(WorkScope::projects($reader, $portfolio->id)->count())->toBe(0)
        ->and(WorkScope::projects($reader)->pluck('projects.id')->all())->toBe([$project->id])
        ->and(app(ProjectClients::class)->client($reader, $client->id)?->portfolio_name)->toBeNull()
        ->and($task->fresh()->getRawOriginal())->toBe($before);
    expect(fn () => app(SaveClient::class)->handle($reader, null, 'Another', $portfolio->id))->toThrow(HttpException::class);
    expect(app(OrganizeClient::class)->handle($reader, $client->id, null, $portfolio->id))->toBeTrue();
});

it('organizes existing clients only with owner consent and refuses stale changes', function () {
    $owner = cpaUser();
    $actor = Access::forUser($owner);
    $first = app(SavePortfolio::class)->handle($actor, null, 'First');
    $second = app(SavePortfolio::class)->handle($actor, null, 'Second');
    $client = cpaClient($owner);
    $project = cpaProject($owner, $client);
    $before = $project->getRawOriginal();
    $command = app(OrganizeClient::class);
    expect($command->handle($actor, $client->id, $first->id, null))->toBeTrue();
    expect(fn () => $command->handle($actor, $client->id, $second->id, null))->toThrow(ValidationException::class);
    expect($command->handle($actor, $client->id, $first->id, $first->id))->toBeFalse();
    expect($command->handle($actor, $client->id, $second->id, $first->id))->toBeTrue()
        ->and(ProjectClient::query()->sole()->portfolio_id)->toBe($second->id)
        ->and($project->fresh()->getRawOriginal())->toBe($before);
});

it('archives and restores a portfolio without changing its clients projects or assignments', function () {
    $owner = cpaUser();
    $actor = Access::forUser($owner);
    $portfolio = app(SavePortfolio::class)->handle($actor, null, 'Delivery');
    $client = app(SaveClient::class)->handle($actor, null, 'Client', $portfolio->id);
    $other = cpaClient($owner);
    $project = cpaProject($owner, $client);
    $before = $project->getRawOriginal();
    $archive = app(ArchivePortfolio::class);
    expect($archive->handle($actor, $portfolio->id, true))->toBeTrue()
        ->and($archive->handle($actor, $portfolio->id, true))->toBeFalse();
    expect(WorkScope::projects($actor, $portfolio->id)->count())->toBe(1)
        ->and(app(ProjectPortfolios::class)->options($actor, true))->toBeEmpty()
        ->and($project->fresh()->getRawOriginal())->toBe($before);
    expect(fn () => app(SaveClient::class)->handle($actor, null, 'New', $portfolio->id))->toThrow(ValidationException::class);
    expect(fn () => app(OrganizeClient::class)->handle($actor, $other->id, $portfolio->id, null))->toThrow(ValidationException::class);
    expect($archive->handle($actor, $portfolio->id, false))->toBeTrue();
    expect(app(OrganizeClient::class)->handle($actor, $other->id, $portfolio->id, null))->toBeTrue();
});

it('rejects invalid portfolio members and keeps ownership and module grants intact', function () {
    $owner = cpaUser();
    $actor = Access::forUser($owner);
    $portfolio = app(SavePortfolio::class)->handle($actor, null, 'Delivery');
    $inactive = cpaUser();
    $inactive->update(['is_active' => false]);
    $tasksOnly = cpaUser(modules: ['TASKS']);
    $before = RoleModuleGrant::query()->count();
    foreach ([$owner->id, $inactive->id, $tasksOnly->id, 999999, -1] as $id) {
        expect(fn () => app(SetPortfolioMember::class)->handle($actor, $portfolio->id, $id, true))->toThrow(ValidationException::class);
    }
    expect(ProjectPortfolioMember::query()->count())->toBe(0)->and(RoleModuleGrant::query()->count())->toBe($before);
});

it('scopes portfolio summaries and queues to authorized projects using the same leaf progress', function () {
    $owner = cpaUser();
    $actor = Access::forUser($owner);
    $first = app(SavePortfolio::class)->handle($actor, null, 'First');
    $second = app(SavePortfolio::class)->handle($actor, null, 'Second');
    $client = app(SaveClient::class)->handle($actor, null, 'Client A', $first->id);
    $project = cpaProject($owner, $client);
    $parent = cpaTask($owner, $project, []);
    $child = cpaTask($owner, $project, []);
    $child->forceFill(['parent_id' => $parent->id, 'status' => 'COMPLETED'])->save();
    cpaTask($owner, $project, [])->update(['due_date' => '2026-10-01']);
    $other = cpaProject($owner, app(SaveClient::class)->handle($actor, null, 'Client B', $second->id));
    cpaTask($owner, $other, [])->update(['due_date' => '2026-10-01']);
    $summary = app(WorkSummary::class);
    $firstSummary = $summary->overview($actor, '2026-11-02', 'ALL', $first->id);
    expect(array_sum($firstSummary['projects']))->toBe(1)->and($firstSummary['totals']->total)->toBe(3)
        ->and($firstSummary['totals']->progress()['percent'])->toBe(50)
        ->and($summary->clients($actor, [$client->id], '2026-11-02')[$client->id]->progress())->toBe($firstSummary['totals']->progress())
        ->and($summary->counters($actor, '2026-11-02', null, $first->id)['overdue'])->toBe(1);
    $filters = ['q' => '', 'client' => 'ALL', 'portfolio' => $first->id, 'status' => 'ALL', 'site' => 'ALL', 'manager' => 'ALL', 'queue' => 'OVERDUE'];
    expect(app(WorkLists::class)->projectPage($actor, $filters, 'code', 'asc', '2026-11-02', 1, 10)->pluck('id')->all())->toBe([$project->id]);
    expect($summary->overview($actor, '2026-11-02', 'ALL', 'not-visible')['totals']->total)->toBe(0);
});

it('keeps portfolio counts bounded across many clients without widening hidden records', function () {
    $owner = cpaUser();
    $actor = Access::forUser($owner);
    $portfolio = app(SavePortfolio::class)->handle($actor, null, 'Delivery');
    for ($i = 0; $i < 12; $i++) {
        $client = app(SaveClient::class)->handle($actor, null, 'Client '.$i, $portfolio->id);
        cpaProject($owner, $client);
    }
    DB::enableQueryLog();
    DB::flushQueryLog();
    $counts = app(ProjectPortfolios::class)->counts($actor, [$portfolio->id]);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();
    expect($counts[$portfolio->id])->toBe(['clients' => 12, 'projects' => 12])->and($queries)->toBe(2);
});
