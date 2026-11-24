<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Platform\Audit\AuditReport;
use App\Platform\Reports\ReportCriteria;
use App\Support\Access;
use App\Support\LocalClock;

/** Audit output keeps event precision and counts the same authorized period as its detail rows. */
it('summarizes the complete audit period and retains millisecond event order', function () {
    $role = Role::create(['name' => 'Audit report output', 'all_data' => true, 'is_system' => false]);
    foreach ([ModuleCode::REPORTS, ModuleCode::AUDIT] as $module) {
        RoleModuleGrant::create(['role_id' => $role->id, 'module' => $module]);
    }
    $user = User::factory()->create(['role_id' => $role->id]);
    foreach ([['2026-09-01T12:00:00.123', 'UPDATE', 'employee'], ['2026-09-01T12:00:00.456', 'CREATE', 'payroll']] as [$at, $action, $entity]) {
        (new AuditLog)->forceFill(['actor_id' => $user->id, 'actor_name' => 'Report actor', 'action' => $action, 'entity' => $entity, 'created_at' => LocalClock::instant($at)->utc()])->save();
    }
    (new AuditLog)->forceFill(['actor_name' => '', 'action' => 'CREATE', 'entity' => 'payroll', 'created_at' => LocalClock::instant('2026-09-01T11:00:00')->utc()])->save();
    (new AuditLog)->forceFill(['actor_name' => 'Excluded', 'action' => 'OUTSIDE', 'entity' => 'excluded', 'created_at' => LocalClock::instant('2026-09-02T00:00:00')->utc()])->save();
    $report = app(AuditReport::class)->generate(Access::forActor($user), ReportCriteria::from(['type' => 'audit-logs', 'from' => '2026-09-01', 'to' => '2026-09-01']));

    expect(array_column($report->rows, 0))->toBe(['2026-09-01 12:00:00.456', '2026-09-01 12:00:00.123', '2026-09-01 11:00:00.000'])
        ->and(array_column($report->summary, 'value', 'label'))->toBe(['Audit entries' => '3', 'Actors' => '2', 'Action types' => '2', 'Entity types' => '2']);
});
