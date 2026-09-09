<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * Port of `enum ModuleCode` (prisma/schema.prisma:39-52) and the `ModuleCode`
 * union in `src/lib/hris.ts`.
 *
 * Deliberately a final class of string constants rather than a MySQL ENUM
 * column or a PHP backed enum:
 *
 *  - a MySQL ENUM sorts by declaration ordinal, needs an ALTER TABLE to extend,
 *    and coerces an unknown value to '' outside strict mode;
 *  - the column is `varchar(20)` plus a CHECK constraint, and this list is the
 *    application-level gate.
 *
 * ORDER MATTERS. `all()` returns the twelve codes in `MODULES` order
 * (src/lib/hris.ts:39-120), NOT in the Prisma enum's declaration order, because
 * `landingPath()` picks "the first module a role holds *in MODULES order*" —
 * which is why PORTAL precedes TASKS and PROFILE is last. Reordering this array
 * changes where a user lands after sign-in.
 */
final class ModuleCode
{
    public const string EMPLOYEES = 'EMPLOYEES';

    public const string EDTR = 'EDTR';

    public const string ROSTER = 'ROSTER';

    public const string LEAVES = 'LEAVES';

    public const string PROJECTS = 'PROJECTS';

    public const string PAYROLL = 'PAYROLL';

    public const string RECORDS = 'RECORDS';

    public const string USERS = 'USERS';

    public const string AUDIT = 'AUDIT';

    public const string PORTAL = 'PORTAL';

    public const string TASKS = 'TASKS';

    public const string PROFILE = 'PROFILE';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::EMPLOYEES,
            self::EDTR,
            self::ROSTER,
            self::LEAVES,
            self::PROJECTS,
            self::PAYROLL,
            self::RECORDS,
            self::USERS,
            self::AUDIT,
            self::PORTAL,
            self::TASKS,
            self::PROFILE,
        ];
    }
}
