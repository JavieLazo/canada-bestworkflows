<?php

declare(strict_types=1);

namespace App\Domain\Attendance;

/**
 * TS: `summarize(days)` at copems/src/lib/dtr.ts:449-468.
 *
 * Consumed by payroll.ts:393 and the eDTR screen. Nothing here rounds, sums in
 * a different order, or short-circuits: the reference walks the whole array
 * once per field and so does this.
 */
final readonly class DtrSummary
{
    public function __construct(
        /** PRESENT + LATE + OFFICIAL_BUSINESS. */
        public int $present,
        public int $officialBusiness,
        /** SUMMED, not counted: a half day is half a day of pay. */
        public float $paidLeave,
        public float $unpaidLeave,
        public int $lates,
        public float $lateMinutes,
        public float $undertimeMinutes,
        public float $overtimeMinutes,
        /**
         * Minutes, like every other total here. Decimal hours read as a time to
         * anyone scanning a timesheet — 7.62 looks like 7:62 rather than 7h 37m.
         */
        public float $workedMinutes,
        public int $absences,
        /** EXCUSED days that carry an excuse type — a rest day alone is not counted. */
        public int $excused,
    ) {}

    /**
     * @param  list<DayRecord>  $days
     */
    public static function of(array $days): self
    {
        $present = 0;
        $officialBusiness = 0;
        $paidLeave = 0.0;
        $unpaidLeave = 0.0;
        $lates = 0;
        $lateMinutes = 0.0;
        $undertimeMinutes = 0.0;
        $overtimeMinutes = 0.0;
        $workedMinutes = 0.0;
        $absences = 0;
        $excused = 0;

        foreach ($days as $day) {
            if ($day->status === DayRecord::PRESENT
                || $day->status === DayRecord::LATE
                || $day->status === DayRecord::OFFICIAL_BUSINESS) {
                $present++;
            }
            if ($day->status === DayRecord::OFFICIAL_BUSINESS) {
                $officialBusiness++;
            }
            // Strict `=== true` / `=== false` in the source: null (not on
            // leave) contributes to neither total.
            if ($day->excusePaid === true) {
                $paidLeave += $day->excuseDays;
            }
            if ($day->excusePaid === false) {
                $unpaidLeave += $day->excuseDays;
            }
            if ($day->lateMinutes > 0) {
                $lates++;
            }
            $lateMinutes += $day->lateMinutes;
            $undertimeMinutes += $day->undertimeMinutes;
            $overtimeMinutes += $day->overtimeMinutes;
            $workedMinutes += $day->workedMinutes;
            if ($day->status === DayRecord::ABSENT) {
                $absences++;
            }
            // `d.excuseType` is the truthiness test from the source: null and
            // the empty string both fail it.
            if ($day->status === DayRecord::EXCUSED && $day->excuseType !== null && $day->excuseType !== '') {
                $excused++;
            }
        }

        return new self(
            present: $present,
            officialBusiness: $officialBusiness,
            paidLeave: $paidLeave,
            unpaidLeave: $unpaidLeave,
            lates: $lates,
            lateMinutes: $lateMinutes,
            undertimeMinutes: $undertimeMinutes,
            overtimeMinutes: $overtimeMinutes,
            workedMinutes: $workedMinutes,
            absences: $absences,
            excused: $excused,
        );
    }

    /**
     * Period total of minutes worked inside the paid shift, overtime removed.
     *
     * The per-day identity `worked - overtime` sums cleanly, so this is just
     * the two totals subtracted. See DayRecord::regularMinutes() for why the
     * engine keeps overtime inside `workedMinutes`.
     *
     * Deliberately NOT in toArray(), which mirrors the summary literal in
     * dtr.ts.
     */
    public function regularMinutes(): float
    {
        return max(0.0, $this->workedMinutes - $this->overtimeMinutes);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'present' => $this->present,
            'officialBusiness' => $this->officialBusiness,
            'paidLeave' => $this->paidLeave,
            'unpaidLeave' => $this->unpaidLeave,
            'lates' => $this->lates,
            'lateMinutes' => $this->lateMinutes,
            'undertimeMinutes' => $this->undertimeMinutes,
            'overtimeMinutes' => $this->overtimeMinutes,
            'workedMinutes' => $this->workedMinutes,
            'absences' => $this->absences,
            'excused' => $this->excused,
        ];
    }
}
