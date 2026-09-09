<?php

declare(strict_types=1);

namespace App\Domain\Attendance;

/**
 * The six derived numbers computeDtr opens with — dtr.ts:262-273 — hoisted out
 * so `payroll.ts` and the readiness pass can read the same derivation instead
 * of each re-deriving `overnight` from raw clock strings.
 *
 * All minutes-past-midnight, all floats (JS numbers are doubles).
 */
final readonly class ShiftGeometry
{
    public function __construct(
        /** `clockMinutes(start_time)`. */
        public float $start,
        /** `clockMinutes(end_time)`, BEFORE the overnight wrap. */
        public float $rawEnd,
        /**
         * `rawEnd <= start`. Note `<=`, not `<`: a shift whose end equals its
         * start (a 24-hour shift written 08:00–08:00) is treated as overnight
         * and becomes 1440 minutes long, not zero. That is the source's
         * behaviour and it is what enables the carry-over slice.
         */
        public bool $overnight,
        /** `overnight ? rawEnd + 1440 : rawEnd`. */
        public float $end,
        /** `Math.max(end - start, 0)` — the scheduled span, break included. */
        public float $fixedLength,
        /**
         * What the day OWES. FIXED: the scheduled span. FLEXIBLE:
         * `Math.round(required_hours * 60)`, clamped at 0.
         */
        public float $requiredMinutes,
        /**
         * What the day PAYS — the scheduled span less the unpaid break, or the
         * hours a flexible shift owes. This, not `requiredMinutes`, is the
         * overtime threshold and the divisor behind an hourly rate.
         */
        public float $paidMinutes,
    ) {}
}
