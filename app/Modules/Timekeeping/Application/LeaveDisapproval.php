<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application;

use Illuminate\Support\Facades\Schema;

/**
 * Why management disapproved a leave or official-business request — the
 * "Disapproved due to" line of the leave application. ReviewAbsence asks for
 * it on EVERY disapproval, keeps it on the request
 * (`leave_travel_requests.disapproval_reason`) and sends it to the employee,
 * who reads it in My Portal: an employee is never told "disapproved"
 * without being told why.
 */
final class LeaveDisapproval
{
    /** The longest reason kept: the column's length. */
    public const int MAX_LENGTH = 1000;

    private function __construct() {}

    /**
     * Whether the request rows carry `disapproval_reason` yet
     * (`2026_11_08_0001`). Until the cron migrates, a disapproval still
     * lands and the employee's notice still carries the reason; only the
     * row cannot keep it.
     */
    public static function ready(): bool
    {
        return once(static fn (): bool => Schema::hasColumn('leave_travel_requests', 'disapproval_reason'));
    }

    /** The refusal for a reason that cannot be given as typed, or null. */
    public static function error(string $reason): ?string
    {
        if ($reason === '') {
            return 'Say why the request is disapproved.';
        }
        if (mb_strlen($reason) > self::MAX_LENGTH) {
            return 'Keep the reason to '.self::MAX_LENGTH.' characters.';
        }

        return null;
    }
}
