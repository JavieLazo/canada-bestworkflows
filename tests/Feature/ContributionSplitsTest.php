<?php

declare(strict_types=1);

use App\Models\Payslip;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Support\Access;
use Illuminate\Support\Str;

/**
 * DELIBERATE DIVERGENCE 19 through the application: a month's posted
 * shares are captured in the evidence (never read from the live table on
 * a replay, never from a draft), the HALF_THEN_REST schedule takes the
 * rest on the month-ending payroll, a payslip records its contribution
 * month and base, and the Contributions & tax version is picked for the
 * run's CONTRIBUTION MONTH.
 */

/** An unlinked all-data PAYROLL officer, signed in. */
function cspActor(): Access
{
    $role = new Role;
    $role->forceFill(['name' => 'Split '.Str::random(6), 'all_data' => true, 'is_system' => false])->save();
    $grant = new RoleModuleGrant;
    $grant->forceFill(['role_id' => $role->getKey(), 'module' => 'PAYROLL'])->save();
    $user = User::factory()->create(['role_id' => $role->getKey()]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return app(Access::class);
}
