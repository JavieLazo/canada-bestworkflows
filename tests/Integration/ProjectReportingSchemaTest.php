<?php

declare(strict_types=1);

use App\Models\ProjectFunding;
use App\Models\ProjectReportSubmission;
use App\Modules\WorkManagement\Application\Commands\SaveFunding;
use App\Modules\WorkManagement\Application\Commands\SaveIndicator;
use App\Modules\WorkManagement\Application\Commands\SaveProjectReport;
use App\Modules\WorkManagement\Application\Commands\SaveReportingPeriod;
use App\Modules\WorkManagement\Application\Commands\SaveReportTemplate;
use App\Modules\WorkManagement\Application\Commands\SetProjectMember;
use App\Modules\WorkManagement\Application\Commands\SetupProjectFinance;
use App\Modules\WorkManagement\Application\Commands\SubmitProjectReport;
use App\Modules\WorkManagement\Application\IndicatorDraft;
use App\Support\Access;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../Support/ClientProjectFixtures.php';

afterEach(function (): void {
    if (! app()->environment('testing') || ! in_array(DB::connection()->getDatabaseName(), [':memory:', 'ems_testing'], true)) {
        throw new RuntimeException('Only isolated reporting fixtures may be cleared.');
    }
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    Schema::withoutForeignKeyConstraints(static function (): void {
        foreach (['project_record_values', 'project_record_files', 'project_report_submissions', 'project_reports', 'project_report_templates', 'project_report_fields', 'project_reporting_periods', 'project_result_entries', 'project_indicators', 'project_outcomes', 'project_cash_allocations', 'project_contribution_deliveries', 'project_contributions', 'project_funding_installments', 'project_fundings', 'project_budget_lines', 'project_budgets', 'project_cash_entries', 'project_cost_categories', 'project_finance_members', 'project_finances', 'project_task_assignees', 'project_tasks', 'project_members', 'projects', 'project_client_members', 'project_clients'] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->delete();
            }
        }
    });
});

it('reruns and reverses empty reporting schema keeps work and cash intact and refuses retained report and funding loss', function () {
    $owner = cpaUser();
    $actor = Access::forUser($owner);
    $project = cpaProject($owner, cpaClient($owner));
    $task = cpaTask($owner, $project, []);
    app(SetupProjectFinance::class)->handle($actor, $project->id, 'PHP');
    $tables = ['projects', 'project_tasks', 'project_finances', 'project_cost_categories', 'project_clients', 'users'];
    $columns = [];
    foreach ($tables as $table) {
        $columns[$table] = Schema::getColumnListing($table);
    }
    $snapshot = static fn (): array => array_map(static fn (string $table): string => DB::table($table)->select($columns[$table])->orderBy('id')->get()->toJson(), $tables);
    $before = $snapshot();
    $migrations = array_map(static fn (string $name) => require database_path('migrations/'.$name.'.php'), ['2026_11_03_0003_add_project_results', '2026_11_03_0004_add_project_reporting', '2026_11_03_0005_add_project_funding']);
    foreach (array_reverse($migrations) as $migration) {
        $migration->down();
        $migration->down();
    }
    foreach ($migrations as $migration) {
        $migration->up();
        $migration->up();
    }
    expect($snapshot())->toBe($before);
    app(SaveIndicator::class)->handle($actor, $project->id, null, 0, new IndicatorDraft('Indicator', unit: 'sites'));
    app(SaveReportingPeriod::class)->handle($actor, $project->id, null, 0, 'Reporting period', '2026-01-01', '2026-12-31');
    app(SaveFunding::class)->handle($actor, $project->id, null, 0, 'Funding', 'Partner', '100');
    foreach ($migrations as $migration) {
        expect(fn () => $migration->down())->toThrow(RuntimeException::class);
        $migration->up();
    }
    expect(ProjectFunding::query()->sole()->committed_minor)->toBe(10000)->and($snapshot())->toBe($before);
});

it('refuses a nominated reviewer removed behind an older transaction snapshot', function (string $change) {
    $owner = cpaUser();
    $reviewer = cpaUser();
    $actor = Access::forUser($owner);
    $client = cpaClient($owner, [$reviewer]);
    $project = cpaProject($owner, $client);
    app(SetProjectMember::class)->handle($actor, $project->id, $reviewer->id, 'VIEWER');
    $period = app(SaveReportingPeriod::class)->handle($actor, $project->id, null, 0, 'November', '2026-11-01', '2026-11-30');
    $template = app(SaveReportTemplate::class)->handle($actor, $project->id, null, 0, 'Period report', 'PERIOD_RESULTS', ['sections' => ['profile'], 'fields' => []]);
    $report = app(SaveProjectReport::class)->handle($actor, $project->id, null, 0, $template->id, $period->id, 'Report', 'Actual progress');
    $config = DB::connection()->getConfig();
    $other = new PDO('mysql:host='.$config['host'].';port='.$config['port'].';dbname=ems_testing', $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $changed = false;
    DB::connection()->beforeExecuting(function (string $sql) use (&$changed, $other, $change, $project, $reviewer, $client): void {
        if ($changed || ! str_contains($sql, 'from `projects`') || ! str_contains(strtolower($sql), 'for update')) {
            return;
        }
        $changed = true;
        $other->beginTransaction();
        $lock = $other->prepare('SELECT id FROM projects WHERE id = ? FOR UPDATE');
        $lock->execute([$project->id]);
        [$statement, $bindings] = match ($change) {
            'member' => ['UPDATE project_members SET role = ? WHERE project_id = ? AND user_id = ?', ['PARTICIPANT', $project->id, $reviewer->id]],
            'client' => ['DELETE FROM project_client_members WHERE client_id = ? AND user_id = ?', [$client->id, $reviewer->id]],
            'module' => ['DELETE FROM role_module_grants WHERE role_id = ? AND module = ?', [$reviewer->role_id, 'PROJECTS']],
            default => ['UPDATE users SET is_active = 0 WHERE id = ?', [$reviewer->id]],
        };
        $write = $other->prepare($statement);
        $write->execute($bindings);
        $other->commit();
    });
    DB::beginTransaction();
    foreach (['project_members', 'project_client_members', 'role_module_grants', 'users'] as $table) {
        DB::table($table)->get();
    }
    $failure = null;
    try {
        app(SubmitProjectReport::class)->handle($actor, $project->id, $report->id, 1, $reviewer->id);
        DB::commit();
    } catch (Throwable $e) {
        $failure = $e;
        DB::rollBack();
    }
    expect($changed)->toBeTrue()->and($failure)->toBeInstanceOf(ValidationException::class)->and(ProjectReportSubmission::query()->count())->toBe(0);
})->with(['member', 'client', 'module', 'active'])->skip(fn (): bool => DB::connection()->getDriverName() !== 'mysql', 'Requires MariaDB snapshots.');

it('prevents currency changes after a funding commitment appears behind an older snapshot', function () {
    $owner = cpaUser();
    $actor = Access::forUser($owner);
    $project = cpaProject($owner, cpaClient($owner));
    app(SetupProjectFinance::class)->handle($actor, $project->id, 'PHP');
    $config = DB::connection()->getConfig();
    $other = new PDO('mysql:host='.$config['host'].';port='.$config['port'].';dbname=ems_testing', $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $changed = false;
    DB::connection()->beforeExecuting(function (string $sql) use (&$changed, $other, $project, $owner): void {
        if ($changed || ! str_contains($sql, 'from `projects`') || ! str_contains(strtolower($sql), 'for update')) {
            return;
        }
        $changed = true;
        $write = $other->prepare('INSERT INTO project_fundings (id,project_id,name,provider,committed_minor,status,revision,created_by,updated_by,created_at) VALUES (?,?,?,?,?,?,?,?,?,?)');
        $write->execute([(string) Str::ulid(), $project->id, 'Funding', 'Partner', 10000, 'ACTIVE', 1, $owner->id, $owner->id, '2026-11-03 10:00:00']);
    });
    DB::beginTransaction();
    DB::table('project_fundings')->get();
    $failure = null;
    try {
        app(SetupProjectFinance::class)->handle($actor, $project->id, 'USD');
        DB::commit();
    } catch (Throwable $e) {
        $failure = $e;
        DB::rollBack();
    }
    expect($changed)->toBeTrue()->and($failure)->toBeInstanceOf(ValidationException::class)
        ->and(DB::table('project_finances')->where('project_id', $project->id)->value('currency'))->toBe('PHP');
})->skip(fn (): bool => DB::connection()->getDriverName() !== 'mysql', 'Requires MariaDB snapshots.');
