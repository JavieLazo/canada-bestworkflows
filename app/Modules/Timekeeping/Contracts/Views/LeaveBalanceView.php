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
 * "used 2" beside a blank entitlement. Typed properties rather than a Record,
 * like LocationOnDateView: a misspelt field is an error at render time, not a
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
    ) {}

    /** Whether a credit was entered for this type and year. */
    public function entered(): bool
    {
        return $this->credited !== null;
    }
}
