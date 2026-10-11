<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\Notification;
use App\Models\PayrollFinalizationSnapshot;
use App\Models\Payslip;
use App\Modules\Payroll\Application\Commands\FinalizePayroll;
use App\Modules\Payroll\Application\PayrollReviewRequired;
use App\Modules\Workforce\Contracts\EmployeePayContexts;
use App\Support\Access;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\PayrollEvidenceFixture;
use Tests\Support\PayrollReviewFixture;

/** MariaDB proves storage rollback, repeatable input capture and independently committed edits. */
it('rolls back every finalization write when MariaDB rejects a storage step', function (string $step) {
    ['actor' => $actor, 'run' => $run] = PayrollEvidenceFixture::make();
    $receipt = PayrollReviewFixture::token($actor, $run->id);
    $table = ['evidence' => 'payroll_finalization_snapshots', 'payslip' => 'payslips', 'status' => 'payroll_runs', 'audit' => 'audit_logs'][$step];
    $event = $step === 'status' ? 'UPDATE' : 'INSERT';
    DB::unprepared("CREATE TRIGGER reject_test_posting BEFORE {$event} ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Synthetic posting storage failure'");
    try {
        expect(fn () => app(FinalizePayroll::class)->handle($actor, $run->id, $receipt))->toThrow(RuntimeException::class);
        expect($run->fresh()->status)->toBe('DRAFT')
            ->and(PayrollFinalizationSnapshot::query()->count())->toBe(0)
            ->and(Payslip::query()->count())->toBe(0)
            ->and(AuditLog::query()->count())->toBe(0)
            ->and(Notification::query()->count())->toBe(0);
    } finally {
        DB::unprepared('DROP TRIGGER IF EXISTS reject_test_posting');
    }
    expect(app(FinalizePayroll::class)->handle($actor, $run->id, $receipt))->toBe(1)
        ->and(PayrollFinalizationSnapshot::query()->count())->toBe(1)
        ->and(Notification::query()->count())->toBe(1);
})->with(['evidence', 'payslip', 'status', 'audit']);

it('rejects a source change committed by a different connection after preview', function () {
    ['actor' => $actor, 'employee' => $employee, 'run' => $run] = PayrollEvidenceFixture::make();
    $receipt = PayrollReviewFixture::token($actor, $run->id);
    config(['database.connections.evidence_writer' => config('database.connections.mysql')]);
    try {
        DB::connection('evidence_writer')->table('employee_profiles')->where('id', $employee->id)->update(['monthly_rate' => 45000]);
        expect(fn () => app(FinalizePayroll::class)->handle($actor, $run->id, $receipt))->toThrow(PayrollReviewRequired::class);
        expect($run->fresh()->status)->toBe('DRAFT')->and(PayrollFinalizationSnapshot::query()->count())->toBe(0);
    } finally {
        DB::purge('evidence_writer');
    }
});

it('captures one consistent view when source facts change during finalization', function () {
    ['actor' => $actor, 'employee' => $employee, 'run' => $run] = PayrollEvidenceFixture::make();
    PayrollEvidenceFixture::sourceFacts($employee);
    $receipt = PayrollReviewFixture::token($actor, $run->id);
    $original = app(EmployeePayContexts::class);
    config(['database.connections.evidence_writer' => config('database.connections.mysql')]);
    $wrapped = new class($original, $employee->id) implements EmployeePayContexts
    {
        public function __construct(private EmployeePayContexts $inner, private string $employeeId) {}

        public function get(Access $actor, array $employeeIds): array
        {
            $rows = $this->inner->get($actor, $employeeIds);
            // This commit lands between Workforce and Timekeeping reads in the posting transaction.
            DB::connection('evidence_writer')->transaction(function (): void {
                DB::connection('evidence_writer')->table('employee_profiles')->where('id', $this->employeeId)->update(['monthly_rate' => 45000]);
                DB::connection('evidence_writer')->table('attendance_logs')->where('employee_id', $this->employeeId)->delete();
            });

            return $rows;
        }
    };
    app()->instance(EmployeePayContexts::class, $wrapped);
    try {
        expect(app(FinalizePayroll::class)->handle($actor, $run->id, $receipt))->toBe(1);
        $data = json_decode(PayrollFinalizationSnapshot::query()->sole()->payload, true, 512, JSON_THROW_ON_ERROR);
        expect($data['inputs']['employees'][0]['monthly_rate'])->toBe(30000)
            ->and($data['inputs']['attendance'][$employee->id]['logs'])->toHaveCount(2)
            ->and(EmployeeProfile::findOrFail($employee->id)->monthly_rate)->toBe(45000.0)
            ->and(DB::table('attendance_logs')->count())->toBe(0);
    } finally {
        DB::purge('evidence_writer');
        app()->instance(EmployeePayContexts::class, $original);
    }
});

it('resumes additive evidence DDL without changing drafts or reconstructing old postings', function () {
    ['run' => $run] = PayrollEvidenceFixture::make();
    $before = $run->fresh()->toArray();
    $migration = require database_path('migrations/2026_09_17_0002_create_payroll_finalization_snapshots_table.php');
    $migration->down();
    $migration->up();
    $migration->up();
    // Simulate interruption after table creation, before the two independently guarded DDL steps.
    Schema::table('payroll_finalization_snapshots', function (Blueprint $table): void {
        $table->dropForeign('pfs_run_fk');
        $table->dropUnique('pfs_run_unq');
    });
    $migration->up();
    $migration->up();
    expect(Schema::hasIndex('payroll_finalization_snapshots', 'pfs_run_unq'))->toBeTrue()
        ->and(Schema::hasForeignKey('payroll_finalization_snapshots', ['run_id']))->toBeTrue()
        ->and($run->fresh()->toArray())->toBe($before)
        ->and(PayrollFinalizationSnapshot::query()->count())->toBe(0);
    $run->update(['status' => 'FINALIZED']);
    $migration->up();
    expect(PayrollFinalizationSnapshot::query()->count())->toBe(0);
});
