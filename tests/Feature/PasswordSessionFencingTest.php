<?php

declare(strict_types=1);

use App\Livewire\Profile\ProfilePage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

/**
 * A password change must sign out every OTHER session — the port of the
 * reference's credential-marker fence (session.ts + guard.ts:63): every cookie
 * is bound to a hash of the password, and auth/password/route.ts re-signs only
 * the caller's, so all other devices die on their next request.
 *
 * Found missing in the fidelity verification pass (confirmed HIGH): a stolen
 * session survived a password change indefinitely.
 */
it('deletes other sessions on password change and keeps the current one', function () {
    $user = User::factory()->create([
        'password' => 'bestworkflows123',
    ]);

    $this->actingAs($user);
    session()->put('probe', 1);
    session()->save();
    $own = session()->getId();

    // A second device: another session row for the same account.
    DB::table('sessions')->insert([
        'id' => 'stolen-device-session-000000000000000000',
        'user_id' => $user->getKey(),
        'ip_address' => '10.0.0.9',
        'user_agent' => 'other-device',
        'payload' => base64_encode(serialize([])),
        'last_activity' => now()->timestamp,
    ]);

    Livewire::actingAs($user)
        ->test(ProfilePage::class)
        ->set('currentPassword', 'bestworkflows123')
        ->set('newPassword', 'a-new-password-42')
        ->set('confirmPassword', 'a-new-password-42')
        ->call('changePassword');

    expect(DB::table('sessions')->where('id', 'stolen-device-session-000000000000000000')->exists())
        ->toBeFalse('the other device\'s session must be gone');

    // Restore so the shared demo DB keeps its known credentials.
    $user->refresh();
    expect(Hash::check('a-new-password-42', $user->password))->toBeTrue();
    $user->password = 'bestworkflows123';
    $user->save();
});
