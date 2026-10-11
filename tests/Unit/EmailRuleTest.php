<?php

declare(strict_types=1);

use App\Livewire\Employees\EmployeeFields;
use App\Livewire\Users\Index as UsersIndex;
use App\Support\EmailRule;

/**
 * EmailRule is the ONE email rule, and both places that validate an address —
 * employee records (EmployeeFields) and login accounts (Users\Index) —
 * forward to it. Users\Index keeps its copy private, so it is reached by
 * reflection here.
 *
 * Pure PHP; no container, so this runs under tests/Unit.
 */
it('accepts blank and anything with an @ between two non-blank parts', function () {
    expect(EmailRule::error(''))->toBeNull()
        ->and(EmailRule::error('   '))->toBeNull()
        ->and(EmailRule::error('name@company.com'))->toBeNull()
        ->and(EmailRule::error(' a@b '))->toBeNull()
        ->and(EmailRule::error('no-at-sign'))->toBe(EmailRule::MESSAGE)
        ->and(EmailRule::error('@company.com'))->toBe(EmailRule::MESSAGE)
        ->and(EmailRule::error('two words@x'))->toBe(EmailRule::MESSAGE);
});

it('is the rule Employees and Users both apply', function () {
    $users = new ReflectionMethod(UsersIndex::class, 'emailError');

    foreach (['', 'name@company.com', 'no-at-sign', 'two words@x'] as $value) {
        $expected = EmailRule::error($value);

        expect(EmployeeFields::emailError($value))->toBe($expected)
            ->and($users->invoke(null, $value))->toBe($expected);
    }
});
