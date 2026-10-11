<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Support\Access;
use App\Support\ModuleRegistry;

/**
 * Community is the first module: it heads the sidebar and is the sign-in
 * landing page of every account that holds it. An account without it still
 * lands on its first work module, PORTAL still precedes TASKS and PROFILE
 * stays last and out of the sidebar list.
 */

/** @param list<string> $modules */
function sidebarOrderActor(array $modules): Access
{
    $role = new Role;
    $role->forceFill(['name' => 'Sidebar order '.implode('-', $modules), 'all_data' => false, 'is_system' => false])->save();
    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }
    test()->actingAs(User::factory()->create(['role_id' => $role->getKey()]));
    app()->forgetInstance(Access::class);

    return app(Access::class);
}

it('puts Community first in the sidebar and keeps every other module in registry order', function () {
    $registry = app(ModuleRegistry::class);
    $granted = [ModuleCode::PROFILE, ModuleCode::COMMUNITY, ModuleCode::PORTAL, ModuleCode::TASKS, ModuleCode::EDTR, ModuleCode::CHAT];

    expect(array_column($registry->navFor($granted), 'code'))
        ->toBe([ModuleCode::COMMUNITY, ModuleCode::EDTR, ModuleCode::PORTAL, ModuleCode::TASKS, ModuleCode::CHAT])
        ->and(array_column($registry->navFor([ModuleCode::PROFILE, ModuleCode::PORTAL]), 'code'))
        ->toBe([ModuleCode::PORTAL]);
});

it('lands every account that holds Community on it, and the rest on their first work module', function () {
    $registry = app(ModuleRegistry::class);

    expect($registry->landingPath([ModuleCode::PORTAL, ModuleCode::COMMUNITY, ModuleCode::PROFILE]))->toBe('/community')
        ->and($registry->landingPath([ModuleCode::EDTR, ModuleCode::COMMUNITY]))->toBe('/community')
        ->and($registry->landingPath([ModuleCode::EDTR, ModuleCode::CHAT, ModuleCode::PROFILE]))->toBe('/edtr')
        ->and($registry->landingPath([ModuleCode::TASKS, ModuleCode::PORTAL, ModuleCode::PROFILE]))->toBe('/portal')
        ->and($registry->landingPath([ModuleCode::PROFILE]))->toBe('/profile')
        ->and($registry->forPath('/community'))->toBe(ModuleCode::COMMUNITY)
        ->and(array_column($registry->all(), 'code'))->toBe(ModuleCode::all());
});

it('sends a signed-in account that holds Community there from the home and dashboard routes', function () {
    sidebarOrderActor([ModuleCode::PORTAL, ModuleCode::COMMUNITY, ModuleCode::PROFILE]);

    $this->get('/')->assertRedirect('/community');
    $this->get('/dashboard')->assertRedirect('/community');
});

it('places Reports after Payroll and before Records without granting it to other roles', function () {
    $registry = app(ModuleRegistry::class);

    expect(array_column($registry->navFor([ModuleCode::RECORDS, ModuleCode::REPORTS, ModuleCode::PAYROLL]), 'code'))
        ->toBe([ModuleCode::PAYROLL, ModuleCode::REPORTS, ModuleCode::RECORDS])
        ->and(array_column($registry->navFor([ModuleCode::PAYROLL, ModuleCode::RECORDS]), 'code'))
        ->not->toContain(ModuleCode::REPORTS)
        ->and($registry->landingPath([ModuleCode::REPORTS, ModuleCode::PROFILE]))->toBe('/reports')
        ->and($registry->landingPath([ModuleCode::REPORTS, ModuleCode::PAYROLL]))->toBe('/payroll')
        ->and($registry->forPath('/reports'))->toBe(ModuleCode::REPORTS)
        ->and($registry->forPath('/reports/payroll-register'))->toBe(ModuleCode::REPORTS);
});

it('renders Community as the first sidebar entry for a signed-in account', function () {
    sidebarOrderActor([ModuleCode::PORTAL, ModuleCode::COMMUNITY, ModuleCode::PROFILE]);

    $html = $this->get('/portal')->assertOk()->getContent();
    expect($html)->toBeString();
    $html = is_string($html) ? $html : '';
    $community = strpos($html, 'href="'.url('/community').'"');
    $portal = strpos($html, 'href="'.url('/portal').'"');
    expect($community !== false && $portal !== false && $community < $portal)->toBeTrue();
});
