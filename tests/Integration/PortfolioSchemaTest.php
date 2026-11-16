<?php

declare(strict_types=1);

use App\Models\ProjectClient;
use App\Models\ProjectPortfolio;
use App\Modules\WorkManagement\Application\Commands\OrganizeClient;
use App\Modules\WorkManagement\Application\Commands\SaveClient;
use App\Modules\WorkManagement\Application\Commands\SavePortfolio;
use App\Modules\WorkManagement\Application\Commands\SetPortfolioMember;
use App\Modules\WorkManagement\Application\Queries\ProjectPortfolios;
use App\Modules\WorkManagement\Application\Queries\WorkSummary;
use App\Support\Access;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

require_once __DIR__.'/../Support/ClientProjectFixtures.php';

afterEach(function (): void {
    if (! app()->environment('testing') || ! in_array(DB::connection()->getDatabaseName(), [':memory:', 'ems_testing'], true)) {
        throw new RuntimeException('Only the guarded synthetic schema may be cleaned.');
    }
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    if (Schema::hasColumn('project_clients', 'portfolio_id')) {
        DB::table('project_clients')->update(['portfolio_id' => null]);
    }
    foreach (['project_portfolio_members', 'project_portfolios', 'project_members', 'project_tasks', 'project_task_groups', 'projects'] as $table) {
        if (Schema::hasTable($table)) {
            DB::table($table)->delete();
        }
    }
});

it('reruns the portfolio migration preserves original columns and refuses populated rollback', function () {
    $owner = cpaUser();
    $client = cpaClient($owner);
    $project = cpaProject($owner, $client);
    cpaTask($owner, $project, []);
    $tables = ['project_clients', 'project_client_members', 'projects', 'project_tasks', 'project_task_assignees', 'project_task_submissions', 'project_task_files', 'task_comments', 'project_task_groups', 'project_members', 'users', 'roles', 'role_module_grants'];
    $columns = [];
    foreach ($tables as $table) {
        $columns[$table] = array_values(array_diff(Schema::getColumnListing($table), $table === 'project_clients' ? ['portfolio_id'] : []));
    }
    $snapshot = static fn (): array => array_map(static fn (string $table): string => DB::table($table)->select($columns[$table])->orderBy('id')->get()->toJson(), $tables);
    $before = $snapshot();
    $migration = require database_path('migrations/2026_11_02_0002_add_project_portfolios.php');
    $migration->down();
    $migration->down();
    $migration->up();
    $migration->up();
    expect($snapshot())->toBe($before)->and(ProjectClient::query()->sole()->portfolio_id)->toBeNull();
    $actor = Access::forUser($owner);
    $portfolio = app(SavePortfolio::class)->handle($actor, null, 'Delivery');
    app(OrganizeClient::class)->handle($actor, $client->id, $portfolio->id, null);
    $before = $snapshot();
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'Keep portfolios');
    $migration->up();
    expect($snapshot())->toBe($before)->and(ProjectClient::query()->sole()->portfolio_id)->toBe($portfolio->id);
    $row = (array) DB::table('project_portfolios')->first();
    expect(fn () => DB::table('project_portfolios')->insert(array_replace($row, ['id' => (string) Str::ulid()])))->toThrow(QueryException::class);
    expect(fn () => DB::table('project_clients')->where('id', $client->id)->update(['portfolio_id' => (string) Str::ulid()]))->toThrow(QueryException::class);
    app(OrganizeClient::class)->handle($actor, $client->id, null, $portfolio->id);
    ProjectPortfolio::query()->delete();
    $before = $snapshot();
    $migration->down();
    $migration->down();
    expect(Schema::hasColumn('project_clients', 'portfolio_id'))->toBeFalse()->and($snapshot())->toBe($before);
    $migration->up();
});

it('reads portfolio and client aggregates under strict SQL grouping', function () {
    $owner = cpaUser();
    $actor = Access::forUser($owner);
    $portfolio = app(SavePortfolio::class)->handle($actor, null, 'Delivery');
    $client = app(SaveClient::class)->handle($actor, null, 'Client', $portfolio->id);
    $project = cpaProject($owner, $client);
    cpaTask($owner, $project, []);
    expect(app(ProjectPortfolios::class)->counts($actor, [$portfolio->id])[$portfolio->id])->toBe(['clients' => 1, 'projects' => 1])
        ->and(app(WorkSummary::class)->clients($actor, [$client->id], '2026-11-02')[$client->id]->total)->toBe(1)
        ->and(app(WorkSummary::class)->overview($actor, '2026-11-02', 'ALL', $portfolio->id)['totals']->total)->toBe(1);
});

it('checks committed portfolio revocation archive and client organization behind an earlier snapshot', function (string $change) {
    $owner = cpaUser();
    $member = cpaUser();
    $actor = Access::forUser($owner);
    $portfolio = app(SavePortfolio::class)->handle($actor, null, 'Delivery');
    app(SetPortfolioMember::class)->handle($actor, $portfolio->id, $member->id, true);
    $client = cpaClient($member);
    $config = DB::connection()->getConfig();
    $other = new PDO('mysql:host='.$config['host'].';port='.$config['port'].';dbname=ems_testing', $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $committed = false;
    DB::connection()->beforeExecuting(function (string $sql) use (&$committed, $other, $change, $portfolio, $member, $client): void {
        $target = $change === 'association' ? '`project_clients`' : '`project_portfolios`';
        if ($committed || ! str_contains($sql, $target) || ! str_contains(strtolower($sql), 'for update')) {
            return;
        }
        $committed = true;
        $other->beginTransaction();
        if ($change === 'association') {
            $write = $other->prepare('UPDATE project_clients SET portfolio_id = ? WHERE id = ?');
            $write->execute([$portfolio->id, $client->id]);
        } else {
            $write = $other->prepare('SELECT id FROM project_portfolios WHERE id = ? FOR UPDATE');
            $write->execute([$portfolio->id]);
            if ($change === 'archive') {
                $write = $other->prepare('UPDATE project_portfolios SET archived_at = NOW() WHERE id = ?');
                $write->execute([$portfolio->id]);
            } else {
                $write = $other->prepare('DELETE FROM project_portfolio_members WHERE portfolio_id = ? AND user_id = ?');
                $write->execute([$portfolio->id, $member->id]);
            }
        }
        $other->commit();
    });
    $reader = Access::forUser($member);
    DB::beginTransaction();
    DB::table('project_portfolio_members')->where('portfolio_id', $portfolio->id)->first();
    DB::table('project_clients')->where('id', $client->id)->first();
    $failure = null;
    try {
        app(OrganizeClient::class)->handle($reader, $client->id, $portfolio->id, null);
        DB::commit();
    } catch (Throwable $error) {
        $failure = $error;
        DB::rollBack();
    }
    expect($committed)->toBeTrue()->and($failure)->not->toBeNull()
        ->and(ProjectClient::query()->findOrFail($client->id)->portfolio_id)->toBe($change === 'association' ? $portfolio->id : null);
})->with(['membership', 'archive', 'association'])->skip(fn (): bool => DB::connection()->getDriverName() !== 'mysql', 'Requires real MariaDB snapshots.');
