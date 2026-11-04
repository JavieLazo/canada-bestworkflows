<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * Port of `enum PayrollRunStatus` (prisma/schema.prisma:69-72).
 *
 * Stored as `varchar(20)` on `payroll_runs.status`, default DRAFT, with a
 * `runs_status_chk` CHECK constraint alongside it. A MySQL ENUM was rejected
 * because `ORDER BY status` would sort by declaration ordinal and silently
 * differ from the PostgreSQL system this reconciles against.
 */
final class PayrollRunStatus
{
    public const string DRAFT = 'DRAFT';

    public const string FINALIZED = 'FINALIZED';
}
