<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Why an email address is unacceptable, or null when it is fine.
 *
 * TS: `emailError(value)` (hris.ts, below fmtHm). Blank is fine too — whether
 * the field is required is the form's own decision. `EmployeeFields`
 * (employee records) and `Users\Index` (login accounts) both forward here,
 * and `tests/Unit/EmailRuleTest.php` pins the rule and the forwarding.
 *
 * The rule is deliberately loose — something, an `@`, something, no
 * whitespace — because the reference's is, and a stricter one would refuse
 * addresses already stored.
 */
final class EmailRule
{
    public const string MESSAGE = 'An email address needs an "@", like name@company.com.';

    private function __construct() {}

    public static function error(string $value): ?string
    {
        $v = trim($value);

        if ($v === '') {
            return null;
        }

        return preg_match('/^[^\s@]+@[^\s@]+$/u', $v) === 1 ? null : self::MESSAGE;
    }
}
