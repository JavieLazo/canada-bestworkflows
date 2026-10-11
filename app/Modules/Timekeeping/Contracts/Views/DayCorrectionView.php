<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Contracts\Views;

use App\Domain\Attendance\DayRecord;

/**
 * Everything the DTR correction dialog shows for one employee on one date:
 * the shift for THAT date, where they work on it, the day as the engine
 * computed it, the stored remark, the slots to prefill with each slot's
 * "Next day" tick, the recorded punches a save would supersede — with their
 * captured location — and the day's overtime decision. The punches listed
 * are the ones the DTR reads as THIS date's row, never the previous night's.
 *
 * Built by Queries\DayCorrections and read on every render while the dialog
 * is open. It is not Livewire state (a DayRecord is not Wireable), which is
 * also why it is always fresh.
 */
final readonly class DayCorrectionView
{
    /**
     * @param  array{time_in: string, break_out: string, break_in: string, time_out: string}  $slots  'HH:mm', or '' for an empty slot (and for a borrowed one)
     * @param  list<CorrectionPunchView>  $punches  oldest first: the rows the DTR reads as this date's row — the date's own, then its next-day ones — never the previous night's
     * @param  array{time_in: bool, break_out: bool, break_in: bool, time_out: bool}  $next_day  per slot, whether the prefilled punch is on the next calendar day
     * @param  array<string, string>  $borrowed  slot key => the stamp the DTR shows there that belongs to the previous date's record, left out of $slots
     */
    public function __construct(
        public string $employee_id,
        public string $employee_name,
        public string $date,
        public ShiftOnDateView $shift,
        /** The place a correction of this date writes onto its inserted punches. */
        public LocationOnDateView $location,
        /** Null for a date after today: the DTR never computes a day that has not happened. */
        public ?DayRecord $day,
        /** The stored note, '' when there is none. */
        public string $remark,
        public array $slots,
        public array $punches,
        /** The day's overtime and its decision, or null when there is neither. */
        public ?OvertimeDayView $overtime = null,
        /** Whether this actor has all-data EDTR access to decide it, including their own day. */
        public bool $can_decide_overtime = false,
        public array $next_day = ['time_in' => false, 'break_out' => false, 'break_in' => false, 'time_out' => false],
        /** Whether the date's shift can hold a next-day time at all: overnight or flexible (CorrectionWindow::allowsNextDay). */
        public bool $next_day_allowed = false,
        public array $borrowed = [],
        /** How many punches on this calendar date belong to the previous date's record (a night's after-midnight punches). */
        public int $previous_day_punches = 0,
    ) {}
}
