<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Domain\Absence;

/** Accumulates one leave type's movements without mixing paid, unpaid and future usage. */
final class LeaveLedgerTotals
{
    public float $opening = 0.0;

    public float $accrued = 0.0;

    public float $carried = 0.0;

    public float $adjustments = 0.0;

    public float $paidTaken = 0.0;

    public float $unpaidTaken = 0.0;

    public float $bookedPaid = 0.0;

    public float $bookedUnpaid = 0.0;

    public function credit(string $kind, float $days): void
    {
        match ($kind) {
            'BASELINE', 'OPENING' => $this->opening += $days,
            'ACCRUAL' => $this->accrued += $days,
            'CARRY_FORWARD' => $this->carried += $days,
            default => $this->adjustments += $days,
        };
    }

    public function usage(bool $paid, float $taken, float $booked): void
    {
        if ($paid) {
            $this->paidTaken += $taken;
            $this->bookedPaid += $booked;
        } else {
            $this->unpaidTaken += $taken;
            $this->bookedUnpaid += $booked;
        }
    }
}
