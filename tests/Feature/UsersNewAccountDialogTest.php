<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Livewire\Users\Index;
use App\Models\EmployeeProfile;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\IdentityAccess\Application\Commands\ManageAccounts;
use App\Modules\IdentityAccess\Application\PasswordPolicy;
use App\Modules\IdentityAccess\Application\Queries\GetAccounts;
use App\Modules\Workforce\Application\Queries\AccountEmployees;
use App\Support\Access;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/**
 * The New-account form leads with the employee. A pick offers the record's
 * contact email as the login email — to an actor who may provision the
 * account and nobody else — and names the sign-ins that employee already has
 * WITHOUT refusing another. The temporary password is generated: it satisfies
 * the policy, stays on the form across a refused attempt, and becomes a
 * credential only when the account is saved.
 */
/** @param list<string> $modules ModuleCode values to grant */
function newAccountRole(array $modules, bool $allData): Role
{
    $role = new Role;
    $role->forceFill(['name' => 'New account '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();

    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }

    return $role;
}

/** @param list<string> $modules ModuleCode values to grant */
function newAccountActor(bool $allData = true, array $modules = [ModuleCode::USERS]): User
{
    $user = User::factory()->create(['role_id' => newAccountRole($modules, $allData)->getKey()]);

    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return $user;
}

function newAccountEmployee(string $code, string $first, string $last, ?string $email = null, bool $active = true): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => $code,
        'first_name' => $first,
        'last_name' => $last,
        'email' => $email,
        'employment_status' => 'Regular',
        'date_hired' => '2024-01-01',
        'is_active' => $active,
    ])->save();

    return $employee;
}

/**
 * The existing-accounts note alone: the markup from its status region to the
 * email field. The accounts table above the dialog prints the same emails,
 * roles and states, so an assertion over the whole page proves nothing
 * about the note.
 */
function newAccountNote(string $html): string
{
    $from = strpos($html, 'data-existing-accounts');
    $to = strpos($html, 'wire:model.live.blur="createEmail"');

    if ($from === false || $to === false || $to < $from) {
        throw new RuntimeException('The create dialog did not render the existing-accounts region before the email field.');
    }

    return substr($html, $from, $to - $from);
}

/*
|--------------------------------------------------------------------------
| The generated temporary password
|--------------------------------------------------------------------------
*/

it('opens the form with a generated password and replaces it on request', function () {
    newAccountActor();

    $form = Livewire::test(Index::class)->call('openCreate');
    $first = $form->get('createPassword');

    expect($first)->toBeString()->not->toBe('');
    PasswordPolicy::validate($first);

    $form->call('generateCreatePassword');
    $second = $form->get('createPassword');

    expect($second)->not->toBe('')->not->toBe($first);
    PasswordPolicy::validate($second);

    // Generating stores nothing: only the actor's own account exists.
    expect(User::query()->count())->toBe(1);
});

it('creates the account with the generated password and takes it off the form', function () {
    newAccountActor();
    $role = newAccountRole([ModuleCode::PORTAL], false);

    $form = Livewire::test(Index::class)->call('openCreate');
    $password = $form->get('createPassword');

    $form->set('createEmail', 'generated@example.com')
        ->set('createName', 'Generated Sign-in')
        ->call('createRoleChanged', (string) $role->getKey())
        ->call('createAccount')
        ->assertSet('adding', false)
        ->assertSnapshotSet('createPassword', '')
        ->assertSnapshotSet('createGenerated', '');

    $created = User::query()->where('email', 'generated@example.com')->first();

    expect($created)->not->toBeNull();
    expect(Hash::check($password, $created->password))->toBeTrue();
});

it('keeps the generated password across a refused attempt and drops one typed by hand', function () {
    newAccountActor();
    $before = User::query()->count();

    $form = Livewire::test(Index::class)->call('openCreate');
    $password = $form->get('createPassword');

    // Whoever fills the form may already have copied it: a refusal for
    // another field must not swap it for a different one.
    $form->set('createEmail', 'not-an-email')
        ->call('createAccount')
        ->assertSet('adding', true)
        ->assertSnapshotSet('createPassword', $password);

    $form->set('createPassword', 'Typed-By-Hand#2026!')
        ->call('createAccount')
        ->assertSnapshotSet('createPassword', '');

    expect(User::query()->count())->toBe($before);
});

it('never lets the browser write the generated password or the offered email markers', function (string $property) {
    newAccountActor();

    expect(fn () => Livewire::test(Index::class)->call('openCreate')->set($property, 'Planted-By-Client#2026'))
        ->toThrow(CannotUpdateLockedPropertyException::class);
})->with(['createGenerated', 'createEmailFilled', 'createOpened']);

/*
|--------------------------------------------------------------------------
| The picked employee offers the login email
|--------------------------------------------------------------------------
*/

it('lists the employee picker ahead of the email, role and password fields', function () {
    newAccountActor();

    $html = Livewire::test(Index::class)->call('openCreate')->html();

    $at = static function (string $markup) use ($html): int {
        $position = strpos($html, $markup);

        if ($position === false) {
            throw new RuntimeException("The create dialog did not render {$markup}.");
        }

        return $position;
    };

    $employee = $at('aria-label="Link to employee record (optional)"');
    $email = $at('wire:model.live.blur="createEmail"');
    $role = $at('Select role');
    $password = $at('wire:model="createPassword"');

    expect($email)->toBeGreaterThan($employee)
        ->and($role)->toBeGreaterThan($email)
        ->and($password)->toBeGreaterThan($role);
});

it('offers the picked employee\'s contact email as the login email', function () {
    newAccountActor();
    $employee = newAccountEmployee('COP-00201', 'Liza', 'Domingo', '  liza.domingo@example.com ');

    Livewire::test(Index::class)
        ->call('openCreate')
        ->call('createEmployeeChanged', (string) $employee->getKey())
        ->assertSet('createEmployeeId', (string) $employee->getKey())
        ->assertSet('createEmail', 'liza.domingo@example.com')
        ->assertSet('createEmailError', null);
});

it('follows a changed pick only while the offered email is untouched', function () {
    newAccountActor();
    $first = newAccountEmployee('COP-00202', 'Ana', 'Reyes', 'ana@example.com');
    $second = newAccountEmployee('COP-00203', 'Ben', 'Santos', 'ben@example.com');
    $without = newAccountEmployee('COP-00204', 'Cara', 'Lim');

    Livewire::test(Index::class)
        ->call('openCreate')
        ->call('createEmployeeChanged', (string) $first->getKey())
        ->assertSet('createEmail', 'ana@example.com')
        ->call('createEmployeeChanged', (string) $second->getKey())
        ->assertSet('createEmail', 'ben@example.com')
        // An employee with no email takes the last offer back out, so the
        // account is never created under the previous pick's address.
        ->call('createEmployeeChanged', (string) $without->getKey())
        ->assertSet('createEmail', '')
        ->call('createEmployeeChanged', (string) $first->getKey())
        ->assertSet('createEmail', 'ana@example.com')
        ->call('createEmployeeChanged', '')
        ->assertSet('createEmployeeId', '')
        ->assertSet('createEmail', '')
        // Typed by hand: no later pick replaces it.
        ->set('createEmail', 'typed@example.com')
        ->call('createEmployeeChanged', (string) $second->getKey())
        ->assertSet('createEmployeeId', (string) $second->getKey())
        ->assertSet('createEmail', 'typed@example.com')
        ->call('createEmployeeChanged', '')
        ->assertSet('createEmail', 'typed@example.com');
});

it('keeps an offered email that was edited when another employee is picked', function () {
    newAccountActor();
    $first = newAccountEmployee('COP-00213', 'Ana', 'Reyes', 'ana@example.com');
    $second = newAccountEmployee('COP-00214', 'Ben', 'Santos', 'ben@example.com');

    Livewire::test(Index::class)
        ->call('openCreate')
        ->call('createEmployeeChanged', (string) $first->getKey())
        ->assertSet('createEmail', 'ana@example.com')
        // The offer was corrected by hand: it is no longer the picker's to replace.
        ->set('createEmail', 'anna@example.com')
        ->call('createEmployeeChanged', (string) $second->getKey())
        ->assertSet('createEmployeeId', (string) $second->getKey())
        ->assertSet('createEmail', 'anna@example.com')
        // Emptied, the field takes offers again.
        ->set('createEmail', '')
        ->call('createEmployeeChanged', (string) $first->getKey())
        ->assertSet('createEmail', 'ana@example.com');
});

it('shows why an offered contact email cannot be a login email', function () {
    newAccountActor();
    $employee = newAccountEmployee('COP-00215', 'Kara', 'Uy', 'kara.uy-at-example.com');

    Livewire::test(Index::class)
        ->call('openCreate')
        ->call('createEmployeeChanged', (string) $employee->getKey())
        ->assertSet('createEmail', 'kara.uy-at-example.com')
        ->assertSet('createEmailError', fn (?string $error): bool => $error !== null && $error !== ManageAccounts::EMAIL_TAKEN);
});

it('ignores a pick that names no employee record', function () {
    newAccountActor();
    $employee = newAccountEmployee('COP-00205', 'Dina', 'Cruz', 'dina@example.com');

    Livewire::test(Index::class)
        ->call('openCreate')
        ->call('createEmployeeChanged', (string) $employee->getKey())
        ->call('createEmployeeChanged', 'no-such-employee')
        ->assertSet('createEmployeeId', (string) $employee->getKey())
        ->assertSet('createEmail', 'dina@example.com');
});

it('reads the contact email only for an actor who may provision the account', function () {
    $employee = newAccountEmployee('COP-00206', 'Elena', 'Ramos', 'elena@example.com');
    $blank = newAccountEmployee('COP-00207', 'Felix', 'Tan', '   ');
    $query = app(AccountEmployees::class);

    $manager = Access::forActor(newAccountActor());

    expect($query->contactEmail($manager, (string) $employee->getKey()))->toBe('elena@example.com')
        ->and($query->contactEmail($manager, (string) $blank->getKey()))->toBeNull()
        ->and($query->contactEmail($manager, 'no-such-employee'))->toBeNull();

    // The module without the organization-wide scope opens the page but
    // cannot create an account, so it reads no contact detail either.
    $holder = Access::forActor(newAccountActor(allData: false));
    expect($query->contactEmail($holder, (string) $employee->getKey()))->toBeNull();

    Livewire::test(Index::class)
        ->call('openCreate')
        ->call('createEmployeeChanged', (string) $employee->getKey())
        ->assertSet('createEmployeeId', (string) $employee->getKey())
        ->assertSet('createEmail', '');

    // Organization-wide scope in another module is not the USERS gate.
    $outsider = Access::forActor(newAccountActor(modules: [ModuleCode::EMPLOYEES]));
    expect($query->contactEmail($outsider, (string) $employee->getKey()))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| An employee who already has an account
|--------------------------------------------------------------------------
*/

it('names the accounts the picked employee already has and still creates another', function () {
    newAccountActor();
    $role = newAccountRole([ModuleCode::PORTAL], false);
    $employee = newAccountEmployee('COP-00208', 'Nathaniel', 'Cruz', 'nathaniel@example.com');

    User::factory()->create([
        'email' => 'nathaniel.first@example.com',
        'employee_id' => $employee->getKey(),
        'role_id' => $role->getKey(),
        'is_active' => true,
    ]);
    User::factory()->create([
        'email' => 'nathaniel.old@example.com',
        'employee_id' => $employee->getKey(),
        'role_id' => null,
        'is_active' => false,
    ]);

    $form = Livewire::test(Index::class)
        ->call('openCreate')
        ->assertDontSee('already has')
        ->call('createEmployeeChanged', (string) $employee->getKey())
        ->assertSet('createEmail', 'nathaniel@example.com')
        ->assertSee('Nathaniel Cruz already has 2 accounts.')
        ->assertSee('You can still create another sign-in for this employee. It needs its own login email.');

    // Each account on its own line: its email, its role, whether it is active.
    $note = newAccountNote($form->html());

    expect($note)
        ->toContain('Nathaniel Cruz already has 2 accounts.')
        ->toMatch('/nathaniel\.first@example\.com.*?'.preg_quote($role->name, '/').'.*?Active.*?nathaniel\.old@example\.com.*?Unassigned.*?Inactive/s')
        ->and(substr_count($note, '<li'))->toBe(2);

    // The note informs; it refuses nothing.
    $form->call('createRoleChanged', (string) $role->getKey())
        ->call('createAccount')
        ->assertSet('adding', false)
        ->assertDontSee('already has');

    expect(User::query()->where('employee_id', $employee->getKey())->pluck('email')->all())
        ->toContain('nathaniel.first@example.com', 'nathaniel.old@example.com', 'nathaniel@example.com');
});

it('says "an account" for one existing sign-in and nothing for none', function () {
    newAccountActor();
    $held = newAccountEmployee('COP-00209', 'Gina', 'Flores');
    $free = newAccountEmployee('COP-00210', 'Hugo', 'Navarro');

    User::factory()->create(['email' => 'gina@example.com', 'employee_id' => $held->getKey()]);

    $form = Livewire::test(Index::class)->call('openCreate');

    // The status region is on the page before any pick fills it.
    expect($form->html())->toContain('data-existing-accounts');

    $form->call('createEmployeeChanged', (string) $held->getKey())
        ->assertSee('Gina Flores already has an account.')
        ->call('createEmployeeChanged', (string) $free->getKey())
        ->assertDontSee('already has')
        ->call('createEmployeeChanged', '')
        ->assertDontSee('already has');
});

it('promises nothing and offers no email for an inactive record, which the save refuses', function () {
    $actor = newAccountActor();
    $role = newAccountRole([ModuleCode::PORTAL], false);
    $former = newAccountEmployee('COP-00212', 'Jose', 'Garcia', 'jose.garcia@example.com', active: false);

    User::factory()->create(['email' => 'jose.old@example.com', 'employee_id' => $former->getKey(), 'is_active' => false]);

    expect(app(AccountEmployees::class)->contactEmail(Access::forActor($actor), (string) $former->getKey()))->toBeNull();

    $before = User::query()->count();

    $form = Livewire::test(Index::class)
        ->call('openCreate')
        ->call('createEmployeeChanged', (string) $former->getKey())
        ->assertSet('createEmployeeId', (string) $former->getKey())
        ->assertSet('createEmail', '')
        ->assertDontSee('You can still create another sign-in');

    // The existing account is still named; only the promise is withheld.
    expect(newAccountNote($form->html()))
        ->toContain('Jose Garcia already has an account.')
        ->toContain('jose.old@example.com')
        ->toContain('Inactive');

    $form->set('createEmail', 'jose.new@example.com')
        ->call('createRoleChanged', (string) $role->getKey())
        ->call('createAccount')
        ->assertSet('adding', true);

    expect(User::query()->count())->toBe($before);
});

it('warns at once when the offered email is already a sign-in', function () {
    newAccountActor();
    $employee = newAccountEmployee('COP-00211', 'Ivy', 'Mendoza', 'Ivy.Mendoza@Example.com');

    User::factory()->create(['email' => 'ivy.mendoza@example.com', 'employee_id' => $employee->getKey()]);

    Livewire::test(Index::class)
        ->call('openCreate')
        ->call('createEmployeeChanged', (string) $employee->getKey())
        ->assertSet('createEmail', 'Ivy.Mendoza@Example.com')
        ->assertSet('createEmailError', ManageAccounts::EMAIL_TAKEN)
        ->assertSee(ManageAccounts::EMAIL_TAKEN)
        // A different address typed over it clears the refusal.
        ->set('createEmail', 'ivy.second@example.com')
        ->assertSet('createEmailError', null)
        // Typed, a taken address gets the same refusal as an offered one.
        ->set('createEmail', 'IVY.MENDOZA@example.com')
        ->assertSet('createEmailError', ManageAccounts::EMAIL_TAKEN)
        ->set('createEmail', 'not-an-email')
        ->assertSet('createEmailError', fn (?string $error): bool => $error !== null && $error !== ManageAccounts::EMAIL_TAKEN)
        ->set('createEmail', '')
        ->assertSet('createEmailError', null);
});

it('sends the email when the field is left and locks it while a pick is on its way', function () {
    newAccountActor();

    $html = Livewire::test(Index::class)->call('openCreate')->html();

    // A bare `.blur` only syncs in the browser: without `.live` no request
    // runs the check, and a refusal would stay under a corrected address.
    expect($html)->toMatch('/<input[^>]*wire:model\.live\.blur="createEmail"[^>]*>/');

    preg_match('/<input[^>]*wire:model\.live\.blur="createEmail"[^>]*>/', $html, $input);

    expect($input[0] ?? '')
        ->toContain('wire:loading.attr="readonly"')
        ->toContain('wire:target="createEmployeeChanged"');
});

it('re-keys the password field on every open so a revealed one starts masked again', function () {
    newAccountActor();

    $form = Livewire::test(Index::class)->call('openCreate')->assertSet('createOpened', 1);
    expect($form->html())->toContain('wire:key="create-password-1"');

    // Generating another keeps the field, and whatever the reader revealed.
    $form->call('generateCreatePassword')->assertSet('createOpened', 1);

    $form->call('openCreate')->assertSet('createOpened', 2);
    expect($form->html())->toContain('wire:key="create-password-2"');
});

it('reports an email already in use, without regard to case, to USERS holders only', function () {
    User::factory()->create(['email' => 'Taken@Example.com']);
    $query = app(GetAccounts::class);

    $holder = Access::forActor(newAccountActor(allData: false));

    expect($query->emailTaken($holder, ' taken@example.COM '))->toBeTrue()
        ->and($query->emailTaken($holder, 'free@example.com'))->toBeFalse();

    $outsider = Access::forActor(newAccountActor(modules: [ModuleCode::EMPLOYEES]));

    expect($query->emailTaken($outsider, 'taken@example.com'))->toBeFalse();
});
