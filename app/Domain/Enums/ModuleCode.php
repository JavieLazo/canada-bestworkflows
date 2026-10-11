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
 * ORDER MATTERS. `all()` returns the codes in `MODULES` order
 * (src/lib/hris.ts:39-120), NOT in the Prisma enum's declaration order, because
 * `landingPath()` picks "the first module a role holds *in MODULES order*" —
 * which is why PORTAL precedes TASKS and PROFILE is last. Reordering this array
 * changes where a user lands after sign-in.
 *
 * COMMUNITY and CHAT extend the reference's twelve codes; any role can hold
 * them (a fresh install seeds them into every role; an existing one grants
 * them per role in User Management). COMMUNITY is FIRST: it heads the
 * sidebar and is the page every account that holds it lands on at sign-in.
 * CHAT sits after every work module but PROFILE, so it never becomes a
 * landing page while a work module is held, and PROFILE stays last.
 * REPORTS sits after PAYROLL and before RECORDS; existing roles receive it
 * only through an explicit grant in User Management.
 */
final class ModuleCode
{
    public const string EMPLOYEES = 'EMPLOYEES';

    public const string EDTR = 'EDTR';

    public const string ROSTER = 'ROSTER';

    public const string LEAVES = 'LEAVES';

    public const string PROJECTS = 'PROJECTS';

    public const string INVENTORY = 'INVENTORY';

    public const string PAYROLL = 'PAYROLL';

    public const string REPORTS = 'REPORTS';

    public const string RECORDS = 'RECORDS';

    public const string USERS = 'USERS';

    public const string AUDIT = 'AUDIT';

    public const string PORTAL = 'PORTAL';

    public const string TASKS = 'TASKS';

    public const string COMMUNITY = 'COMMUNITY';

    public const string CHAT = 'CHAT';

    public const string PROFILE = 'PROFILE';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::COMMUNITY,
            self::EMPLOYEES,
            self::EDTR,
            self::ROSTER,
            self::LEAVES,
            self::PROJECTS,
            self::INVENTORY,
            self::PAYROLL,
            self::REPORTS,
            self::RECORDS,
            self::USERS,
            self::AUDIT,
            self::PORTAL,
            self::TASKS,
            self::CHAT,
            self::PROFILE,
        ];
    }
}
