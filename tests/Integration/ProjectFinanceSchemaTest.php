<?php

declare(strict_types=1);

use App\Models\ProjectBudget;
use App\Models\ProjectBudgetLine;
use App\Models\ProjectCashEntry;
use App\Models\ProjectCostCategory;
use App\Modules\WorkManagement\Application\BudgetLineDraft;
use App\Modules\WorkManagement\Application\CashEntryDraft;
use App\Modules\WorkManagement\Application\Commands\ApproveProjectBudget;
use App\Modules\WorkManagement\Application\Commands\RecordCashEntry;
use App\Modules\WorkManagement\Application\Commands\SaveBudgetLine;
use App\Modules\WorkManagement\Application\Commands\SetProjectFinanceMember;
use App\Modules\WorkManagement\Application\Commands\SetProjectMember;
use App\Modules\WorkManagement\Application\Commands\SetupProjectFinance;
use App\Modules\WorkManagement\Application\Commands\StartProjectBudget;
use App\Modules\WorkManagement\Application\Queries\ProjectFinancials;
use App\Modules\WorkManagement\Domain\Projects\FinanceAmount;
use App\Support\Access;
use App\Support\ManilaClock;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../Support/ClientProjectFixtures.php';

afterEach(function (): void {
    if (! app()->environment('testing') || ! in_array(DB::connection()->getDatabaseName(), [':memory:', 'ems_testing'], true)) {
        throw new RuntimeException('Only the guarded synthetic schema may be cleaned.');
    }
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    if (Schema::hasTable('project_cash_entries')) {
        DB::table('project_cash_entries')->update(['replaces_id' => null]);
    }
    foreach (['project_cash_entries', 'project_budget_lines', 'project_budgets', 'project_cost_categories', 'project_finance_members', 'project_finances', 'project_members', 'project_tasks', 'project_task_groups', 'projects'] as $table) {
        if (Schema::hasTable($table)) {
            DB::table($table)->delete();
        }
    }
});

it('reruns and reverses empty finance schema preserves original work and refuses populated rollback', function () {
    $owner = cpaUser();
    $actor = Access::forUser($owner);
    $project = cpaProject($owner, cpaClient($owner));
    cpaTask($owner, $project, []);
    $tables = ['projects', 'project_tasks', 'project_task_assignees', 'project_task_submissions', 'project_task_files', 'task_comments', 'project_clients', 'project_client_members', 'project_members', 'project_task_groups', 'users', 'roles', 'role_module_grants'];
    $snapshot = static fn (): array => array_map(static fn (string $table): string => DB::table($table)->orderBy('id')->get()->toJson(), $tables);
    $before = $snapshot();
    $migration = require database_path('migrations/2026_11_03_0001_add_project_finance.php');
    $migration->down();
    $migration->down();
    $migration->up();
    $migration->up();
    expect($snapshot())->toBe($before);
    app(SetupProjectFinance::class)->handle($actor, $project->id, 'PHP');
    $budget = app(StartProjectBudget::class)->handle($actor, $project->id);
    $category = ProjectCostCategory::query()->sole();
    app(SaveBudgetLine::class)->handle($actor, $project->id, $budget, 1, null, new BudgetLineDraft('Travel', $category->id, '10'));
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'Keep project finance');
    $migration->up();
    expect($snapshot())->toBe($before)->and(ProjectBudgetLine::query()->sole()->total_minor)->toBe(1000);
    $row = (array) DB::table('project_budgets')->first();
    expect(fn () => DB::table('project_budgets')->insert(array_replace($row, ['id' => (string) Str::ulid()])))->toThrow(QueryException::class);
    $other = cpaProject($owner, cpaClient($owner), 'Other');
    app(SetupProjectFinance::class)->handle($actor, $other->id, 'USD');
    $foreign = ProjectCostCategory::query()->where('project_id', $other->id)->sole();
    expect(fn () => DB::table('project_budget_lines')->where('budget_id', $budget)->update(['category_id' => $foreign->id]))->toThrow(QueryException::class);
});

it('aggregates approved budgets and monthly categories under strict SQL grouping', function () {
    $owner = cpaUser();
    $actor = Access::forUser($owner);
    $project = cpaProject($owner, cpaClient($owner));
    app(SetupProjectFinance::class)->handle($actor, $project->id, 'PHP');
    $budget = app(StartProjectBudget::class)->handle($actor, $project->id);
    $category = ProjectCostCategory::query()->sole();
    app(SaveBudgetLine::class)->handle($actor, $project->id, $budget, 1, null, new BudgetLineDraft('Cost', $category->id, '25.50'));
    app(ApproveProjectBudget::class)->handle($actor, $project->id, $budget, 2);
    $queries = app(ProjectFinancials::class);
    expect($queries->totals($actor, '2026-11-03')->sole()->budget())->toBe(2550)
        ->and($queries->categoryTotals($actor, $project->id, '2026-11-03')[0]['budget'])->toBe(2550)
        ->and(count($queries->cashTrend($actor, $project->id, '2026-11-03')))->toBe(6);
});

it('honors committed financial team client revocation and draft edits behind an earlier snapshot', function (string $change) {
    $owner = cpaUser();
    $editor = cpaUser();
    $actor = Access::forUser($owner);
    $client = cpaClient($owner, [$editor]);
    $project = cpaProject($owner, $client);
    app(SetProjectMember::class)->handle($actor, $project->id, $editor->id, 'PARTICIPANT');
    app(SetProjectFinanceMember::class)->handle($actor, $project->id, $editor->id, 'EDITOR');
    app(SetupProjectFinance::class)->handle($actor, $project->id, 'PHP');
    $budget = app(StartProjectBudget::class)->handle($actor, $project->id);
    $category = ProjectCostCategory::query()->sole();
    $config = DB::connection()->getConfig();
    $other = new PDO('mysql:host='.$config['host'].';port='.$config['port'].';dbname=ems_testing', $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $committed = false;
    DB::connection()->beforeExecuting(function (string $sql) use (&$committed, $other, $change, $project, $editor, $client, $budget): void {
        if ($committed || ! str_contains($sql, 'from `projects`') || ! str_contains(strtolower($sql), 'for update')) {
            return;
        }
        $committed = true;
        $other->beginTransaction();
        $write = $other->prepare('SELECT id FROM projects WHERE id = ? FOR UPDATE');
        $write->execute([$project->id]);
        if ($change === 'budget') {
            $write = $other->prepare('UPDATE project_budgets SET revision = revision + 1 WHERE id = ?');
            $write->execute([$budget]);
        } elseif ($change === 'client') {
            $write = $other->prepare('SELECT id FROM project_clients WHERE id = ? FOR UPDATE');
            $write->execute([$client->id]);
            $write = $other->prepare('DELETE FROM project_client_members WHERE client_id = ? AND user_id = ?');
            $write->execute([$client->id, $editor->id]);
        } else {
            $table = $change === 'finance' ? 'project_finance_members' : 'project_members';
            $write = $other->prepare('DELETE FROM '.$table.' WHERE project_id = ? AND user_id = ?');
            $write->execute([$project->id, $editor->id]);
        }
        $other->commit();
    });
    $reader = Access::forUser($editor);
    DB::beginTransaction();
    DB::table('project_budgets')->where('id', $budget)->first();
    DB::table('project_finance_members')->where('project_id', $project->id)->first();
    $failure = null;
    try {
        app(SaveBudgetLine::class)->handle($reader, $project->id, $budget, 1, null, new BudgetLineDraft('Stale write', $category->id, '10'));
        DB::commit();
    } catch (Throwable $error) {
        $failure = $error;
        DB::rollBack();
    }
    expect($committed)->toBeTrue()->and($failure)->not->toBeNull()->and(ProjectBudgetLine::query()->count())->toBe(0)
        ->and(ProjectBudget::query()->findOrFail($budget)->revision)->toBe($change === 'budget' ? 2 : 1);
})->with(['finance', 'team', 'client', 'budget'])->skip(fn (): bool => DB::connection()->getDriverName() !== 'mysql', 'Requires real MariaDB snapshots.');

it('checks current cash totals and accepted retries behind an earlier snapshot', function (string $change) {
    $owner = cpaUser();
    $actor = Access::forUser($owner);
    $project = cpaProject($owner, cpaClient($owner));
    app(SetupProjectFinance::class)->handle($actor, $project->id, 'PHP');
    $category = ProjectCostCategory::query()->sole();
    $date = ManilaClock::todayKey();
    $record = app(RecordCashEntry::class);
    $record->handle($actor, $project->id, new CashEntryDraft('EXPENSE', $date, 'Cash fact', '0.01', $category->id));
    $row = (array) DB::table('project_cash_entries')->first();
    $requestId = (string) Str::ulid();
    $row['id'] = $requestId;
    $row['amount_minor'] = $change === 'cap' ? FinanceAmount::LIMIT - 1 : 1;
    $config = DB::connection()->getConfig();
    $other = new PDO('mysql:host='.$config['host'].';port='.$config['port'].';dbname=ems_testing', $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $committed = false;
    DB::connection()->beforeExecuting(function (string $sql) use (&$committed, $other, $project, $row): void {
        if ($committed || ! str_contains($sql, 'from `projects`') || ! str_contains(strtolower($sql), 'for update')) {
            return;
        }
        $committed = true;
        $other->beginTransaction();
        $lock = $other->prepare('SELECT id FROM projects WHERE id = ? FOR UPDATE');
        $lock->execute([$project->id]);
        $insert = $other->prepare('INSERT INTO project_cash_entries (`'.implode('`,`', array_keys($row)).'`) VALUES ('.implode(',', array_fill(0, count($row), '?')).')');
        $insert->execute(array_values($row));
        $other->commit();
    });
    DB::beginTransaction();
    DB::table('project_cash_entries')->where('project_id', $project->id)->get();
    $failure = null;
    $accepted = null;
    try {
        $accepted = $record->handle($actor, $project->id, new CashEntryDraft('EXPENSE', $date, 'Cash fact', '0.01', $category->id, requestId: $change === 'retry' ? $requestId : null));
        DB::commit();
    } catch (Throwable $error) {
        $failure = $error;
        DB::rollBack();
    }
    expect($committed)->toBeTrue()->and(ProjectCashEntry::query()->count())->toBe(2);
    if ($change === 'cap') {
        expect($failure)->toBeInstanceOf(ValidationException::class)
            ->and((int) ProjectCashEntry::query()->sum('amount_minor'))->toBe(FinanceAmount::LIMIT);
    } else {
        expect($failure)->toBeNull()->and($accepted)->toBe($requestId);
    }
})->with(['cap', 'retry'])->skip(fn (): bool => DB::connection()->getDriverName() !== 'mysql', 'Requires real MariaDB snapshots.');
