<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Support\Access;

it('serves the preserved BestWorkflows landing and working sign-in destination to guests', function () {
    $this->get(route('home'))->assertOk()
        ->assertSee('BestWorkflows')
        ->assertSee('Schedule an Executive Systems Consultation')
        ->assertSee('href="'.route('login').'"', false)
        ->assertSee('data-kit-select', false)
        ->assertSee('Hybrid Sovereign (Recommended)');
    $this->get(route('login'))->assertOk()
        ->assertSee('bestworkflows-horizontal-view.png')
        ->assertDontSee('CyberOnions');
});

it('keeps the module landing for signed-in users', function () {
    $role = Role::query()->create(['name' => 'Portal member', 'all_data' => false, 'is_system' => false]);
    RoleModuleGrant::query()->create(['role_id' => $role->id, 'module' => ModuleCode::PORTAL]);
    $this->actingAs(User::factory()->create(['role_id' => $role->id]));
    app()->forgetInstance(Access::class);

    $this->get(route('home'))->assertRedirect('/portal');
    $this->get(route('dashboard'))->assertRedirect('/portal');
});

it('keeps the no-modules shell for accounts without grants', function () {
    $this->actingAs(User::factory()->create());
    app()->forgetInstance(Access::class);

    $this->get(route('home'))->assertOk()
        ->assertSee('No modules assigned')
        ->assertSee('BestWorkflows')
        ->assertDontSee('Schedule an Executive Systems Consultation');
});
