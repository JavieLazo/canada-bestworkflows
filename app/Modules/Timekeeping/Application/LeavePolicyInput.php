<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application;

use App\Modules\Timekeeping\Application\Queries\LeaveTypeCreditRecords;
use App\Modules\Timekeeping\Domain\Credits\LeavePolicy;
use RuntimeException;

/**
 * Leave policy params as a preset or an employee's version takes them:
 * validated against the leave types' credit rules — only a type that earns
 * may earn here, only a type that may be charged may be in a charge order —
 * and read into the pure LeavePolicy. Shared by SaveLeavePreset and
 * SaveLeavePolicy, which gate and write; this class only validates.
 */
final class LeavePolicyInput
{
    private function __construct() {}

    /**
     * @param  array<string, mixed>  $params
     *
     * @throws RuntimeException with the first problem, worded for the screen
     */
    public static function validated(array $params): LeavePolicy
    {
        $earning = [];
        foreach (LeaveTypeCreditRecords::rules() as $token => $entry) {
            if ($entry['rules']->earnsCredits) {
                $earning[] = $token;
            }
        }
        $late = LeaveTypeCreditRecords::chargeable('late');
        $absence = LeaveTypeCreditRecords::chargeable('absence');
        $error = LeavePolicy::error($params, $earning, $late, $absence);
        if ($error !== null) {
            throw new RuntimeException($error);
        }

        return LeavePolicy::fromParams($params, $late, $absence);
    }
}
