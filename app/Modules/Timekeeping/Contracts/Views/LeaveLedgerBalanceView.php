<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Contracts\Views;

/**
 * One annual leave ledger, separating credits recorded by HR from approved
 * leave already taken and future bookings. A missing entitlement stays null
 * so the ledger cannot imply that credits were entered when they were not.
 */
final readonly class LeaveLedgerBalanceView
{
    public function __construct(
        public string $request_type,
        public string $label,
        public int $year,
        public float $opening,
        public float $accrued,
        public float $carried,
        public float $adjustments,
        public ?float $credited,
        public float $paid_taken,
        public float $unpaid_taken,
        public float $booked_paid,
        public float $booked_unpaid,
        public ?float $closing,
        public ?float $available,
    ) {}
}
