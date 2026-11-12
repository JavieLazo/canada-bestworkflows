<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application;

use Illuminate\Support\Facades\Schema;

/**
 * The leave application's "Approved for: others (specify)" line (section
 * 7.C): an optional note a leave manager adds when approving a leave.
 * Recorded only: no pay, credit or DTR rule reads it.
 */
final class LeaveApprovalNote
{
    /** The longest note kept: the column's length. */
    public const int MAX_LENGTH = 255;

    private function __construct() {}

    /** Whether the request rows carry the note yet (`2026_11_11_0003`). */
    public static function ready(): bool
    {
        return once(static fn (): bool => Schema::hasColumn('leave_travel_requests', 'approval_note'));
    }

    /** The refusal for a note that cannot be kept as typed, or null. */
    public static function error(string $note): ?string
    {
        return mb_strlen($note) > self::MAX_LENGTH ? 'Keep the note to '.self::MAX_LENGTH.' characters.' : null;
    }
}
