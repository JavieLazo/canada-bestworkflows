<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Contracts\Views;

/**
 * One leave type's standing for one employee in one year: what was entered,
 * what the approved with-pay requests of that type have used, and what is
 * left — derived at read time by Queries\LeaveCreditRecords, never stored.
 *
 * `credited` and `remaining` are null when no credit has been entered for
 * the type that year; `used` is counted regardless, so a screen can still say
 * "used 2" beside a blank entitlement. `spoken_for` is what pending or
 * approved-but-unpaid cash-outs have claimed, and `available` is what is
 * left to file or cash out once they are honoured — the figure filing
 * judges by (LeaveBalances). Typed properties rather than a Record, like
 * LocationOnDateView: a misspelt field is an error at render time, not a
 * silently blank cell.
 */
final readonly class LeaveBalanceView
{
    public function __construct(
        /** The leave type's normalised key, the token requests store. */
        public string $request_type,
        /** The Leave Settings name, or the token title-cased when the type is gone. */
        public string $label,
        public int $year,
        public ?float $credited,
        public float $used,
        public ?float $remaining,
        public float $spoken_for = 0.0,
        public ?float $available = null,
    ) {}

    /** Whether a credit was entered for this type and year. */
    public function entered(): bool
    {
        return $this->credited !== null;
    }
}
