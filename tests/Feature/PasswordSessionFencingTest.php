<?php

declare(strict_types=1);

use App\Livewire\Profile\ProfilePage;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Support\Access;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

/**
 * A password change must sign out every OTHER session — the port of the
 * reference's credential-marker fence (session.ts + guard.ts:63): every cookie
 * is bound to a hash of the password, and auth/password/route.ts re-signs only
 * the caller's, so all other devices die on their next request.
 */
it('deletes other sessions on password change and keeps the current one', function () {
    $role = Role::query()->create(['name' => 'Profile security', 'all_data' => false, 'is_system' => false]);
    RoleModuleGrant::query()->create(['role_id' => $role->id, 'module' => 'PROFILE']);
    $user = User::factory()->create([
        'role_id' => $role->id,
        'password' => 'cyberonions123',
    ]);

    $this->actingAs($user);
    app()->forgetInstance(Access::class);
    session()->put('probe', 1);
    session()->save();
    $own = session()->getId();

    // The array-backed test session needs explicit database rows to exercise the fence.
    foreach ([$own, 'stolen-device-session-000000000000000000'] as $sessionId) {
        DB::table('sessions')->insert([
            'id' => $sessionId,
            'user_id' => $user->getKey(),
            'ip_address' => '10.0.0.9',
            'user_agent' => $sessionId === $own ? 'current-device' : 'other-device',
            'payload' => base64_encode(serialize([])),
            'last_activity' => now()->timestamp,
        ]);
    }

    // Two facade calls rather than a chain: the facade's `test()` is declared
    // non-generic, while chaining through LivewireManager::test() leaves its
    // TComponent template unresolvable for PHPStan. Behaviour is identical.
    Livewire::actingAs($user);
    Livewire::test(ProfilePage::class)
        ->set('tab', 'account')
        ->set('currentPassword', 'cyberonions123')
        ->set('newPassword', 'Changed-Password#2026')
        ->set('confirmPassword', 'Changed-Password#2026')
        ->call('changePassword');

    expect(DB::table('sessions')->where('id', 'stolen-device-session-000000000000000000')->exists())
        ->toBeFalse('the other device\'s session must be gone')
        ->and(DB::table('sessions')->where('id', $own)->exists())->toBeTrue()
        ->and(session()->get('probe'))->toBe(1)
        ->and(session()->getId())->not->toBe($own);

    expect(Hash::check('Changed-Password#2026', $user->refresh()->password))->toBeTrue();
    $this->assertAuthenticatedAs($user);
});
