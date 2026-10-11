<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Contracts\Views;

/**
 * One day's overtime as the Daily Time Record shows it: what the day
 * computed, the most that may be approved, and the decision, if any —
 * with its status read through OvertimeApprovalPolicy (PENDING, APPROVED,
 * PARTIAL, REJECTED, OUTDATED). Detached: no model, no lazy relation.
 */
final readonly class OvertimeDayView
{
    public function __construct(
        public string $date,
        public string $status,
        /** APPROVED | REJECTED, or null while pending. */
        public ?string $decision,
        /** Whole minutes the day computes now. */
        public int $computed_minutes,
        /** Minutes approved (0 when rejected), or null while pending. */
        public ?int $approved_minutes,
        /** The most that may be approved now: computed, capped by the Formulas OT maximum. */
        public int $ceiling,
        /** What payroll pays for the day: OvertimeApprovalPolicy::payable, 0 unless approved. */
        public int $payable_minutes,
        public ?string $note,
        public ?string $reviewer_name,
        /** When it was decided, Manila wall-clock 'M d, Y, g:i A', or null. */
        public ?string $decided_at,
        /** Already covered by posted regular payroll; display only, writes recheck under lock. */
        public bool $posted = false,
    ) {}
}
