<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application;

use App\Modules\Timekeeping\Application\Queries\LeaveTypeCreditRecords;
use App\Modules\Timekeeping\Domain\Absence\AbsencePolicy;
use App\Modules\Timekeeping\Domain\Absence\LeaveApplication;
use Illuminate\Support\Facades\Schema;

/**
 * The details of leave and commutation a request keeps (LeaveApplication):
 * whether the request rows carry the columns yet (`2026_11_08_0003`), and
 * which details a request type asks for. Until the cron migrates, nothing
 * is asked, required or kept.
 */
final class LeaveApplicationDetails
{
    private function __construct() {}

    public static function ready(): bool
    {
        return once(static fn (): bool => Schema::hasColumns('leave_travel_requests', ['leave_detail', 'leave_detail_note', 'commutation']));
    }

    /**
     * The section a request type asks: the leave type's Leave Settings
     * choice, NONE for official business, for a token no leave type carries
     * and while either column is missing.
     */
    public static function section(string $token): string
    {
        if ($token === '' || $token === AbsencePolicy::OFFICIAL_BUSINESS || ! self::ready() || ! LeaveTypeCreditRecords::detailsReady()) {
            return LeaveApplication::NONE;
        }

        return LeaveTypeCreditRecords::of($token)->leaveDetails;
    }
}
