<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Application\PasswordPolicy;
use App\Modules\IdentityAccess\Application\TemporaryPassword;

/**
 * TemporaryPassword::generate() never asks PasswordPolicy itself — in
 * production that is a network call, and the New account dialog generates on
 * every open — so THIS is what keeps the two in step: every draw must pass
 * the policy the account's save applies. A rule added to the policy that the
 * generator does not meet fails here, not on the first account created.
 *
 * The shape is pinned too, because the password is handed over by people:
 * four hyphen-joined groups of five, no look-alike characters, and no symbol
 * but the hyphen.
 */
it('generates temporary passwords that always satisfy the password policy', function () {
    $drawn = [];

    for ($i = 0; $i < 300; $i++) {
        $password = TemporaryPassword::generate();

        // Throws on the first draw the policy would refuse at the save.
        PasswordPolicy::validate($password);

        $drawn[$password] = true;
    }

    expect(count($drawn))->toBe(300);
});

it('generates four hyphen-joined groups of five, without look-alike characters', function () {
    for ($i = 0; $i < 300; $i++) {
        $password = TemporaryPassword::generate();

        expect($password)->toMatch('/^[A-HJ-NP-Za-km-np-z2-9]{5}(?:-[A-HJ-NP-Za-km-np-z2-9]{5}){3}$/')
            ->and($password)->toMatch('/[A-Z]/')
            ->and($password)->toMatch('/[a-z]/')
            ->and($password)->toMatch('/[0-9]/');
    }

    expect(TemporaryPassword::GROUPS)->toBe(4)->and(TemporaryPassword::GROUP_LENGTH)->toBe(5);
});
