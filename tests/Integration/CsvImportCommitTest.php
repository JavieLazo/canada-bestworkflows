<?php

declare(strict_types=1);

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Models\AuditLog;
use App\Models\PayrollDeduction;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Platform\Transfer\ImportReport;
use App\Platform\Transfer\ImportRunner;
use App\Platform\Transfer\TableTransfers;
use App\Support\Access;
use App\Support\Csv;
use Illuminate\Support\Facades\DB;

/**
 * A CSV import is all or nothing on MariaDB itself, not only on sqlite:
 * every row runs in a savepoint of one real InnoDB transaction, a row the
 * database refuses (a unique index) is undone on its own while later rows
 * still run, and the file is committed only when no row failed — with the
 * owner commands' audit rows written after that commit and never for a
 * preview or a refused file. Another connection sees nothing until then.
 */
function csvcommitActor(): Access
{
    $role = Role::create(['name' => 'Importer', 'all_data' => true, 'is_system' => false]);
    foreach ([ModuleCode::PAYROLL] as $module) {
        RoleModuleGrant::create(['role_id' => $role->id, 'module' => $module]);
    }

    return Access::forActor(User::factory()->create(['role_id' => $role->id]));
}

/**
 * @param  list<string>  $headers
 * @param  list<list<string>>  $rows
 */
function csvcommitRun(Access $actor, string $table, array $headers, array $rows, bool $commit): ImportReport
{
    $runner = app(ImportRunner::class);
    $import = app(TableTransfers::class)->import($table);
    $bytes = Csv::build($headers, $rows);

    return $commit ? $runner->commit($actor, $import, '', $bytes) : $runner->preview($actor, $import, '', $bytes);
}

/** What a separate connection sees — committed data only. */
function csvcommitOutsideCount(string $sql): int
{
    $connection = DB::connection();
    $pdo = new PDO('mysql:host=127.0.0.1;port='.$connection->getConfig('port').';dbname=ems_testing', 'ems_testing', $connection->getConfig('password'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $statement = $pdo->query($sql);
    if ($statement === false) {
        throw new RuntimeException('The outside count could not run.');
    }

    return (int) $statement->fetchColumn();
}

it('commits a clean file once and writes its audit rows only after that commit', function () {
    $actor = csvcommitActor();
    PayrollDeduction::query()->delete();
    PayrollDeduction::create(['code' => '1', 'name' => 'Canteen']);

    $report = csvcommitRun($actor, 'records.payroll-deductions', ['ID', 'Name'], [['', 'Union Dues'], ['1', 'Canteen Charges']], commit: true);

    expect($report->saved)->toBeTrue()
        ->and([$report->created, $report->updated])->toBe([1, 1])
        ->and(DB::connection()->transactionLevel())->toBe(0)
        ->and(csvcommitOutsideCount("SELECT COUNT(*) FROM payroll_deductions WHERE name IN ('Union Dues', 'Canteen Charges')"))->toBe(2)
        ->and(AuditLog::query()->whereIn('action', [AuditEvent::REFERENCE_CREATED->value, AuditEvent::REFERENCE_UPDATED->value])->count())->toBe(2);
});

it('undoes a row the unique index refuses, keeps checking the rest, and keeps none of the file', function () {
    $actor = csvcommitActor();
    PayrollDeduction::query()->delete();
    PayrollDeduction::create(['code' => '1', 'name' => 'Canteen']);

    $report = csvcommitRun($actor, 'records.payroll-deductions', ['ID', 'Name'], [
        ['', 'Medical Fund'],
        ['', 'Canteen'],
        ['', 'Union Dues'],
        ['1', ''],
    ], commit: true);

    expect($report->saved)->toBeFalse()
        ->and($report->created)->toBe(2)
        ->and($report->errors)->toBe([
            ['row' => 3, 'message' => '"Canteen" is already on the list.'],
            ['row' => 5, 'message' => 'Name is required.'],
        ])
        ->and(DB::connection()->transactionLevel())->toBe(0)
        ->and(csvcommitOutsideCount('SELECT COUNT(*) FROM payroll_deductions'))->toBe(1)
        ->and(AuditLog::query()->count())->toBe(0);

    // The connection is healthy afterwards: the corrected file lands.
    $retry = csvcommitRun($actor, 'records.payroll-deductions', ['ID', 'Name'], [['', 'Medical Fund'], ['', 'Union Dues']], commit: true);
    expect($retry->saved)->toBeTrue()
        ->and(PayrollDeduction::query()->orderBy('code')->pluck('code', 'name')->all())->toBe(['Canteen' => '1', 'Medical Fund' => '2', 'Union Dues' => '3']);
});

it('previews through the real commands without leaving a row, a code or an audit entry behind', function () {
    $actor = csvcommitActor();
    PayrollDeduction::query()->delete();
    PayrollDeduction::create(['code' => '1', 'name' => 'Canteen']);

    $report = csvcommitRun($actor, 'records.payroll-deductions', ['ID', 'Name'], [['', 'Union Dues'], ['', 'Medical Fund']], commit: false);

    expect([$report->created, $report->saved])->toBe([2, false])
        ->and(csvcommitOutsideCount('SELECT COUNT(*) FROM payroll_deductions'))->toBe(1)
        ->and(AuditLog::query()->count())->toBe(0);

    // The previewed codes were never taken: the next real add mints 2.
    csvcommitRun($actor, 'records.payroll-deductions', ['ID', 'Name'], [['', 'Union Dues']], commit: true);
    expect(PayrollDeduction::query()->where('name', 'Union Dues')->value('code'))->toBe('2');
});
