<?php

declare(strict_types=1);

/**
 * Administrative password forms show owner validation beside their fields.
 * Reset dialogs keep credentials separate from account edits and clear typed
 * secrets on every result, dismissal, target change and rejected permission.
 * The temporary password the New account form generated itself is the
 * exception: a refused save leaves it on the form (UsersNewAccountDialogTest).
 */

use App\Domain\Enums\ModuleCode;
use App\Livewire\Users\Index;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Support\Access;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

function usersPasswordFeedbackActor(): User
{
    $role = Role::query()->create(['name' => 'Password manager', 'all_data' => true, 'is_system' => false]);
    RoleModuleGrant::query()->create(['role_id' => $role->id, 'module' => ModuleCode::USERS]);
    $user = User::factory()->create();
    $user->forceFill(['role_id' => $role->id])->save();
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return $user;
}

it('maps a refused account password onto the create field and clears its response value', function () {
    usersPasswordFeedbackActor();
    $count = User::query()->count();
    Livewire::test(Index::class)
        ->call('openCreate')
        ->set('createName', 'New Account')
        ->set('createEmail', 'new-account@example.test')
        ->set('createPassword', 'too-short')
        ->call('createAccount')
        ->assertHasErrors(['createPassword'])
        ->assertSet('adding', true)
        ->assertSet('createEmail', 'new-account@example.test')
        ->assertSnapshotSet('createPassword', '')
        ->set('adding', false)
        ->assertHasNoErrors();
    expect(User::query()->count())->toBe($count)
        ->and(AuditLog::query()->where('action', 'ACCOUNT_CREATED')->exists())->toBeFalse();
});

it('clears a create password even when the email fails before the command runs', function () {
    usersPasswordFeedbackActor();
    $count = User::query()->count();
    Livewire::test(Index::class)
        ->call('openCreate')
        ->set('createEmail', 'not-an-email')
        ->set('createPassword', 'Another-Password-2026!')
        ->call('createAccount')
        ->assertSet('createEmailError', fn ($error) => is_string($error) && $error !== '')
        ->assertSnapshotSet('createPassword', '');
    expect(User::query()->count())->toBe($count);
});

it('maps refused password resets onto their fields and clears both response values', function (string $password, string $confirmation, string $error) {
    usersPasswordFeedbackActor();
    $target = User::factory()->create(['password' => 'Target-Password-2026!']);
    Livewire::test(Index::class)
        ->call('openAccount', (string) $target->id)
        ->call('openPasswordReset')
        ->set('resetPassword', $password)
        ->set('resetPasswordConfirm', $confirmation)
        ->call('resetAccountPassword')
        ->assertHasErrors([$error])
        ->assertSet('accountDialogOpen', true)
        ->assertSet('passwordResetDialogOpen', true)
        ->assertSet('passwordReset', false)
        ->assertSnapshotSet('resetPassword', '')
        ->assertSnapshotSet('resetPasswordConfirm', '')
        ->set('accountDialogOpen', false)
        ->assertHasNoErrors();
    expect(Hash::check('Target-Password-2026!', $target->fresh()->password))->toBeTrue()
        ->and(AuditLog::query()->where('action', 'USER_PASSWORD_RESET')->exists())->toBeFalse();
})->with([
    'unmet requirements' => ['too-short', 'too-short', 'resetPassword'],
    'confirmation mismatch' => ['Another-Password-2026!', 'Different-Password-2026!', 'resetPasswordConfirm'],
    'current password reuse' => ['Target-Password-2026!', 'Target-Password-2026!', 'resetPassword'],
]);

it('opens a dedicated guided reset dialog with deferred accessible password controls', function () {
    usersPasswordFeedbackActor();
    $target = User::factory()->create();
    Livewire::test(Index::class)
        ->call('openAccount', (string) $target->id)
        ->assertSet('passwordResetDialogOpen', false)
        ->call('openPasswordReset')
        ->assertSet('passwordResetDialogOpen', true)
        ->assertSee('Reset password')
        ->assertSee('Requirements')
        ->assertSee('New password')
        ->assertSee('Confirm new password')
        ->assertSee('Toggle password visibility')
        ->assertSeeHtml('autocomplete="new-password"')
        ->assertSeeHtml('wire:model="resetPassword"')
        ->assertSeeHtml('wire:model="resetPasswordConfirm"')
        ->assertDontSeeHtml('wire:model.live="resetPassword"')
        ->assertDontSeeHtml('wire:model.live="resetPasswordConfirm"');
});

it('clears the reset draft and errors when cancelling closing leaving accounts or selecting another account', function (string $exit) {
    usersPasswordFeedbackActor();
    $target = User::factory()->create(['password' => 'Target-Password-2026!']);
    $other = User::factory()->create(['password' => 'Other-Password-2026!']);
    $page = Livewire::test(Index::class)
        ->call('openAccount', (string) $target->id)
        ->call('openPasswordReset')
        ->set('resetPassword', 'too-short')
        ->set('resetPasswordConfirm', 'too-short')
        ->call('resetAccountPassword')->assertHasErrors(['resetPassword'])
        ->set('resetPassword', 'Draft-Password-2026!')
        ->set('resetPasswordConfirm', 'Draft-Password-2026!');

    match ($exit) {
        'cancel' => $page->call('cancelPasswordReset'),
        'reset dialog close' => $page->set('passwordResetDialogOpen', false),
        'account dialog close' => $page->set('accountDialogOpen', false),
        'tab' => $page->set('tab', 'roles'),
        'account switch' => $page->call('openAccount', (string) $other->id),
        default => throw new LogicException('Unknown reset dialog exit.'),
    };

    $page->assertSet('passwordResetDialogOpen', false)
        ->assertSet('passwordReset', false)
        ->assertHasNoErrors()
        ->assertSnapshotSet('resetPassword', '')
        ->assertSnapshotSet('resetPasswordConfirm', '');
    expect(Hash::check('Target-Password-2026!', $target->fresh()->password))->toBeTrue()
        ->and(Hash::check('Other-Password-2026!', $other->fresh()->password))->toBeTrue()
        ->and(AuditLog::query()->where('action', 'USER_PASSWORD_RESET')->exists())->toBeFalse();
})->with(['cancel', 'reset dialog close', 'account dialog close', 'tab', 'account switch']);

it('opens each reset attempt clean even when the previous dismissal did not reach the server', function () {
    usersPasswordFeedbackActor();
    $target = User::factory()->create();
    Livewire::test(Index::class)
        ->call('openAccount', (string) $target->id)->call('openPasswordReset')
        ->set('resetPassword', 'too-short')->set('resetPasswordConfirm', 'too-short')
        ->call('resetAccountPassword')->assertHasErrors(['resetPassword'])
        ->set('resetPassword', 'Abandoned-Password-2026!')->set('resetPasswordConfirm', 'Abandoned-Password-2026!')
        ->call('openPasswordReset')
        ->assertSet('passwordResetDialogOpen', true)->assertSet('passwordReset', false)
        ->assertHasNoErrors()
        ->assertSnapshotSet('resetPassword', '')->assertSnapshotSet('resetPasswordConfirm', '');
});

it('closes a successful reset without saving the account details draft and clears its confirmation on reopening', function () {
    usersPasswordFeedbackActor();
    $target = User::factory()->create(['email' => 'target@example.test', 'password' => 'Target-Password-2026!', 'is_active' => true]);
    $page = Livewire::test(Index::class)
        ->call('openAccount', (string) $target->id)
        ->set('accountEmail', 'unsaved@example.test')->set('accountIsActive', false)
        ->call('openPasswordReset')
        ->set('resetPassword', 'Another-Password-2026!')->set('resetPasswordConfirm', 'Another-Password-2026!')
        ->call('resetAccountPassword')->assertHasNoErrors()
        ->assertSet('passwordResetDialogOpen', false)->assertSet('passwordReset', true)
        ->assertSet('accountDialogOpen', true)->assertSet('accountEmail', 'unsaved@example.test')->assertSet('accountIsActive', false)
        ->assertSnapshotSet('resetPassword', '')->assertSnapshotSet('resetPasswordConfirm', '');
    expect(Hash::check('Another-Password-2026!', $target->fresh()->password))->toBeTrue()
        ->and($target->fresh()->email)->toBe('target@example.test')->and($target->fresh()->is_active)->toBeTrue()
        ->and(AuditLog::query()->where('action', 'USER_PASSWORD_RESET')->where('entity_id', (string) $target->id)->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'ACCOUNT_UPDATED')->exists())->toBeFalse();

    $page->call('openPasswordReset')->assertSet('passwordReset', false)->assertSet('passwordResetDialogOpen', true)
        ->assertSnapshotSet('resetPassword', '')->assertSnapshotSet('resetPasswordConfirm', '');
});

it('requires a selected account before opening or applying a password reset', function () {
    usersPasswordFeedbackActor();
    $target = User::factory()->create(['password' => 'Target-Password-2026!']);
    Livewire::test(Index::class)
        ->call('openPasswordReset')->assertSet('passwordResetDialogOpen', false)
        ->set('resetPassword', 'Another-Password-2026!')->set('resetPasswordConfirm', 'Another-Password-2026!')
        ->call('resetAccountPassword')
        ->assertSet('passwordReset', false)
        ->assertSnapshotSet('resetPassword', '')->assertSnapshotSet('resetPasswordConfirm', '');
    expect(Hash::check('Target-Password-2026!', $target->fresh()->password))->toBeTrue()
        ->and(AuditLog::query()->where('action', 'USER_PASSWORD_RESET')->exists())->toBeFalse();
});

it('keeps a failed reset open with a form error when the selected account no longer exists', function () {
    usersPasswordFeedbackActor();
    $target = User::factory()->create();
    $page = Livewire::test(Index::class)->call('openAccount', (string) $target->id)->call('openPasswordReset')
        ->set('resetPassword', 'Another-Password-2026!')->set('resetPasswordConfirm', 'Another-Password-2026!');
    $target->delete();
    $page->call('resetAccountPassword')->assertHasErrors(['passwordResetForm'])
        ->assertSet('passwordResetDialogOpen', true)->assertSet('passwordReset', false)
        ->assertSnapshotSet('resetPassword', '')->assertSnapshotSet('resetPasswordConfirm', '');
    expect(AuditLog::query()->where('action', 'USER_PASSWORD_RESET')->exists())->toBeFalse();
});

it('rechecks current management access before opening or applying a reset and clears rejected secrets', function (string $permission, string $action) {
    $manager = usersPasswordFeedbackActor();
    $target = User::factory()->create(['password' => 'Target-Password-2026!']);
    $page = Livewire::test(Index::class)->call('openAccount', (string) $target->id);
    if ($action === 'resetAccountPassword') {
        $page->call('openPasswordReset');
    }
    $page->set('resetPassword', 'Another-Password-2026!')->set('resetPasswordConfirm', 'Another-Password-2026!');
    if ($permission === 'module') {
        RoleModuleGrant::query()->where('role_id', $manager->role_id)->where('module', ModuleCode::USERS)->delete();
    } else {
        Role::query()->whereKey($manager->role_id)->update(['all_data' => false]);
    }
    $this->actingAs($manager->fresh());
    app()->forgetInstance(Access::class);
    $page->call($action)->assertSet('passwordReset', false)
        ->assertSnapshotSet('resetPassword', '')->assertSnapshotSet('resetPasswordConfirm', '');
    if ($action === 'openPasswordReset') {
        $page->assertSet('passwordResetDialogOpen', false);
    }
    expect(Hash::check('Target-Password-2026!', $target->fresh()->password))->toBeTrue()
        ->and(AuditLog::query()->where('action', 'USER_PASSWORD_RESET')->exists())->toBeFalse();
})->with([
    'revoked module before opening' => ['module', 'openPasswordReset'],
    'revoked scope before opening' => ['scope', 'openPasswordReset'],
    'revoked module before saving' => ['module', 'resetAccountPassword'],
    'revoked scope before saving' => ['scope', 'resetAccountPassword'],
]);

it('keeps an administrator signed in when resetting their own account from the dedicated dialog', function () {
    $manager = usersPasswordFeedbackActor();
    session()->put('password-reset-probe', 'kept');
    Livewire::test(Index::class)->call('openAccount', (string) $manager->id)->call('openPasswordReset')
        ->set('resetPassword', 'Another-Password-2026!')->set('resetPasswordConfirm', 'Another-Password-2026!')
        ->call('resetAccountPassword')->assertHasNoErrors()
        ->assertSet('passwordResetDialogOpen', false)->assertSet('passwordReset', true)
        ->assertSnapshotSet('resetPassword', '')->assertSnapshotSet('resetPasswordConfirm', '');

    expect(Hash::check('Another-Password-2026!', $manager->fresh()->password))->toBeTrue()
        ->and(session()->get('password-reset-probe'))->toBe('kept');
    $this->assertAuthenticatedAs($manager);
});
