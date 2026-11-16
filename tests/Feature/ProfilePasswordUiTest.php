<?php

declare(strict_types=1);

/** Profile password changes give field feedback without retaining submitted secrets in response snapshots. */

use App\Domain\Enums\ModuleCode;
use App\Livewire\Profile\ProfilePage;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Support\Access;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

function profilePasswordUiActor(): User
{
    $role = Role::query()->create(['name' => 'Profile password', 'all_data' => false, 'is_system' => false]);
    RoleModuleGrant::query()->create(['role_id' => $role->id, 'module' => ModuleCode::PROFILE]);
    $user = User::factory()->create(['password' => 'Current-Password-2026!']);
    $user->forceFill(['role_id' => $role->id])->save();
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return $user;
}

it('opens a guided password dialog with local requirements and accessible password controls', function () {
    profilePasswordUiActor();
    Livewire::test(ProfilePage::class)
        ->set('tab', 'account')
        ->assertSet('passwordDialogOpen', false)
        ->call('openPasswordChange')
        ->assertSet('passwordDialogOpen', true)
        ->assertSee('Requirements')
        ->assertSee('Current Password')
        ->assertSee('New Password')
        ->assertSee('Confirm New Password')
        ->assertSee('Toggle password visibility')
        ->assertSee('your other sessions will be signed out')
        ->assertSeeHtml('autocomplete="current-password"')
        ->assertSeeHtml('autocomplete="new-password"')
        ->assertDontSeeHtml('wire:model.live="newPassword"');
});

it('keeps the dialog open with field errors and clears rejected passwords from the response', function (string $current, string $next, string $confirmation, string $error) {
    $user = profilePasswordUiActor();
    Livewire::test(ProfilePage::class)
        ->set('tab', 'account')
        ->call('openPasswordChange')
        ->set('currentPassword', $current)
        ->set('newPassword', $next)
        ->set('confirmPassword', $confirmation)
        ->call('changePassword')
        ->assertHasErrors([$error])
        ->assertSet('passwordDialogOpen', true)
        ->assertSet('passwordChanged', false)
        ->assertSnapshotSet('currentPassword', '')
        ->assertSnapshotSet('newPassword', '')
        ->assertSnapshotSet('confirmPassword', '');
    expect(Hash::check('Current-Password-2026!', $user->fresh()->password))->toBeTrue()
        ->and(AuditLog::query()->where('action', 'PASSWORD_CHANGED')->exists())->toBeFalse();
})->with([
    'invalid current credential' => ['Wrong-Password-2026!', 'Another-Password-2026!', 'Another-Password-2026!', 'currentPassword'],
    'unmet requirements' => ['Current-Password-2026!', 'too-short', 'too-short', 'newPassword'],
    'confirmation mismatch' => ['Current-Password-2026!', 'Another-Password-2026!', 'Different-Password-2026!', 'confirmPassword'],
    'current password reuse' => ['Current-Password-2026!', 'Current-Password-2026!', 'Current-Password-2026!', 'newPassword'],
]);

it('clears the password draft and errors when cancelling closing or leaving security', function (string $exit) {
    $user = profilePasswordUiActor();
    $page = Livewire::test(ProfilePage::class)
        ->set('tab', 'account')
        ->call('openPasswordChange')
        ->set('currentPassword', 'Current-Password-2026!')
        ->set('newPassword', 'too-short')
        ->set('confirmPassword', 'too-short')
        ->call('changePassword')
        ->assertHasErrors(['newPassword'])
        ->set('currentPassword', 'Current-Password-2026!')
        ->set('newPassword', 'Draft-Password-2026!')
        ->set('confirmPassword', 'Draft-Password-2026!');

    match ($exit) {
        'cancel' => $page->call('cancelPasswordChange'),
        'close' => $page->set('passwordDialogOpen', false),
        'tab' => $page->set('tab', 'requests'),
        default => throw new LogicException('Unknown exit.'),
    };

    $page->assertSet('passwordDialogOpen', false)
        ->assertHasNoErrors()
        ->assertSnapshotSet('currentPassword', '')
        ->assertSnapshotSet('newPassword', '')
        ->assertSnapshotSet('confirmPassword', '');
    expect(Hash::check('Current-Password-2026!', $user->fresh()->password))->toBeTrue();
})->with(['cancel', 'close', 'tab']);

it('closes a successful password change with clear fields and visible confirmation', function () {
    $user = profilePasswordUiActor();
    Livewire::test(ProfilePage::class)
        ->set('tab', 'account')
        ->call('openPasswordChange')
        ->set('currentPassword', 'Current-Password-2026!')
        ->set('newPassword', 'Another-Password-2026!')
        ->set('confirmPassword', 'Another-Password-2026!')
        ->call('changePassword')
        ->assertHasNoErrors()
        ->assertSet('passwordDialogOpen', false)
        ->assertSet('passwordChanged', true)
        ->assertSnapshotSet('currentPassword', '')
        ->assertSnapshotSet('newPassword', '')
        ->assertSnapshotSet('confirmPassword', '')
        ->assertSee('Password changed. Your other sessions have been signed out. You are still signed in here.');
    expect(Hash::check('Another-Password-2026!', $user->fresh()->password))->toBeTrue();
    $this->assertAuthenticatedAs($user);
});
