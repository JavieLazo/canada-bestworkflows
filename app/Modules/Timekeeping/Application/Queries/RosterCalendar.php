<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application\Queries;

use App\Domain\Attendance\CalendarDays;
use App\Domain\Attendance\HolidaySetting;
use App\Domain\Attendance\Shift as DomainShift;
use App\Domain\Enums\ModuleCode;
use App\Domain\Payroll\PayrollPeriod;
use App\Models\LeaveTravelRequest;
use App\Models\LeaveType;
use App\Models\RosterDayOverride;
use App\Models\ShiftType;
use App\Modules\Organization\Contracts\HolidayCalendar;
use App\Modules\Timekeeping\Application\LeaveHalves;
use App\Modules\Timekeeping\Contracts\Views\RosterDayView;
use App\Modules\Timekeeping\Domain\Roster\CustomHours;
use App\Modules\Timekeeping\Domain\Roster\LocationKind;
use App\Modules\Timekeeping\Domain\Roster\RestBetweenShifts;
use App\Modules\Timekeeping\Infrastructure\RosterVersions;
use App\Modules\Timekeeping\Infrastructure\ShiftRoster;
use App\Support\Access;
use App\Support\HrisFormat;

/**
 * The Shift Roster's calendar: every date of a range for a batch of
 * employees — the shift that applies, its hours, where it is worked, the
 * approved leave on it, and a warning where it starts less than eight hours
 * after the previous date's shift ended (`RestBetweenShifts`).
 *
 * SCOPED like the rest of the roster's reads (`RosterShifts`): ROSTER, EDTR
 * or PAYROLL, every employee for an all-data holder of one of them, else
 * the actor's own, and nobody for an account with no employee link.
 *
 * LEAVE is leave-request data, so it is read under Leave Management's own
 * scope, not the roster's: a viewer who may not see an employee's leave
 * sees the schedule of those dates and no leave on them. Only the type is
 * read — never the reason, the pay or who decided it.
 *
 * Batched: the schedule, the leave and the pattern names in a few reads
 * whatever the number of employees.
 *
 * `own()` is My Portal's read of the same days: the actor's own employee
 * alone, with their own leave, even for an all-data holder. Holidays are
 * company-wide and named on each date (`holidays()` for a header).
 */
final class RosterCalendar
{
    /** The longest range answered: two months, more than one screen draws. */
    public const MAX_DAYS = 62;

    /**
     * @param  list<string>  $employeeIds
     * @return array<string, array<string, RosterDayView>> employee id => 'Y-m-d' => day; the dates from `$start` through `$end`
     */
    public function forEmployees(Access $actor, array $employeeIds, string $start, string $end): array
    {
        if (! PayrollPeriod::isPayrollDate($start) || ! PayrollPeriod::isPayrollDate($end) || $start > $end) {
            return [];
        }
        $visible = $this->visible($actor, $employeeIds, ModuleCode::ROSTER, ModuleCode::EDTR, ModuleCode::PAYROLL);

        return $this->build($visible, $this->visible($actor, $visible, ModuleCode::LEAVES), $start, $end);
    }

    /**
     * MY schedule, for My Portal: the actor's own employee's dates, pinned to
     * them whatever else the role may read, with their own approved leave
     * on them. Empty for an account with no employee link or no PORTAL.
     *
     * @return array<string, RosterDayView> 'Y-m-d' => day
     */
    public function own(Access $actor, string $start, string $end): array
    {
        if (! $actor->hasModule(ModuleCode::PORTAL) || ! $actor->hasEmployeeLink()
            || ! PayrollPeriod::isPayrollDate($start) || ! PayrollPeriod::isPayrollDate($end) || $start > $end) {
            return [];
        }
        $own = [$actor->ownEmployeeId()];

        return $this->build($own, $own, $start, $end)[$own[0]] ?? [];
    }

    /**
     * The holidays from `$start` through `$end`, by name — the same for
     * everyone, so the week table names them once, in its header.
     *
     * @return array<string, string> 'Y-m-d' => name
     */
    public function holidays(string $start, string $end): array
    {
        if (! PayrollPeriod::isPayrollDate($start) || ! PayrollPeriod::isPayrollDate($end) || $start > $end) {
            return [];
        }
        $holidays = app(HolidayCalendar::class)->all();
        $out = [];
        foreach (CalendarDays::each($start, min($end, PayrollPeriod::addCalendarDays($start, self::MAX_DAYS - 1))) as $date) {
            $holiday = HolidaySetting::on($date, $holidays);
            if ($holiday !== null) {
                $out[$date] = $holiday->name;
            }
        }

        return $out;
    }

    /**
     * The days of employees already authorized, with the leave of those the
     * viewer may see it for.
     *
     * @param  list<string>  $employeeIds
     * @param  list<string>  $leaveIds
     * @return array<string, array<string, RosterDayView>>
     */
    private function build(array $employeeIds, array $leaveIds, string $start, string $end): array
    {
        if ($employeeIds === []) {
            return [];
        }
        $end = min($end, PayrollPeriod::addCalendarDays($start, self::MAX_DAYS - 1));
        // One date more at the front: the rest before the first date needs
        // the shift of the date before it.
        $before = CalendarDays::previous($start);
        $calendar = app(ShiftRoster::class)->calendarFor($employeeIds, $before, $end);
        $leave = $this->leave($leaveIds, $before, $end);
        $names = ShiftType::query()->pluck('name', 'id')->all();
        $holidays = $this->holidays($before, $end);

        $out = [];
        foreach ($employeeIds as $employeeId) {
            $days = $calendar[$employeeId] ?? [];
            $previous = null;
            foreach ($days as $date => $day) {
                $onLeave = $leave[$employeeId][$date] ?? null;
                if ($date >= $start) {
                    $out[$employeeId][$date] = $this->view($date, $day, $onLeave, $names, $previous, $holidays[$date] ?? null);
                }
                // Leave stands between the two shifts: nothing is judged across it.
                $previous = $day['works'] && $onLeave === null ? $day['shift'] : null;
            }
        }

        return $out;
    }

    /**
     * @param  array{shift: ?DomainShift, works: bool, source: string, usual: ?DomainShift, usual_works: bool, hours_from: ?RosterDayOverride, place_from: ?RosterDayOverride, place: array{kind: string, site: ?string}|null}  $day
     * @param  array{type: string, ob: bool, half: bool}|null  $leave
     * @param  array<string, string>  $names
     */
    private function view(string $date, array $day, ?array $leave, array $names, ?DomainShift $previous, ?string $holiday): RosterDayView
    {
        $shift = $day['shift'];
        $works = $day['works'] && $shift !== null;
        $hoursFrom = $day['hours_from'];
        $placeFrom = $day['place_from'];
        $shortRest = $works && $leave === null && $previous !== null ? RestBetweenShifts::shortfall($previous, $shift) : null;

        $shiftChoice = 'DEFAULT';
        $customHours = null;
        $typed = false;
        if ($hoursFrom !== null) {
            if ($hoursFrom->is_rest_day) {
                $shiftChoice = 'REST';
            } elseif ($hoursFrom->shift_type_id !== null && isset($names[$hoursFrom->shift_type_id])) {
                $shiftChoice = $hoursFrom->shift_type_id;
            } elseif ($hoursFrom->shift_data !== null && CustomHours::isCustom($hoursFrom->shift_type_id, $hoursFrom->shift_name, $hoursFrom->shift_data)) {
                $shiftChoice = 'CUSTOM';
                $customHours = CustomHours::input($hoursFrom->shift_data);
                $typed = true;
            } else {
                // A deleted pattern's copy, kept as it is: custom hours cannot
                // reproduce a flexible copy or a flat break.
                $shiftChoice = 'KEEP';
            }
        }
        $temporary = $hoursFrom ?? $placeFrom;
        $locationChoice = match (true) {
            $placeFrom === null || $placeFrom->location_kind === null => 'DEFAULT',
            $placeFrom->location_kind === LocationKind::WFH => LocationKind::WFH,
            default => (string) $placeFrom->site_id,
        };

        return new RosterDayView(
            date: $date,
            rostered: $shift !== null,
            works: $works,
            label: match (true) {
                $shift === null => 'No shift',
                ! $works => 'Rest day',
                default => self::name($shift, $names) ?? ($typed ? CustomHours::NAME : self::hours($shift)),
            },
            hours: $works ? self::hours($shift) : null,
            starts: $works && ! $shift->isFlexible() ? HrisFormat::hm($shift->startTime) : null,
            ends: $works && ! $shift->isFlexible() ? HrisFormat::hm($shift->endTime) : null,
            custom: $works && ($shift->shiftTypeId === null || ! isset($names[$shift->shiftTypeId])),
            source: $day['source'],
            short_rest_minutes: $shortRest === null ? null : (int) $shortRest,
            leave: $leave['type'] ?? null,
            official_business: $leave['ob'] ?? false,
            half_day: $leave['half'] ?? false,
            place_kind: $day['place']['kind'] ?? null,
            place_site_id: $day['place']['site'] ?? null,
            shift_choice: $shiftChoice,
            custom_hours: $customHours,
            location_choice: $locationChoice,
            usual: match (true) {
                $day['usual'] === null => 'No shift',
                ! $day['usual_works'] => 'Rest day',
                default => trim((self::name($day['usual'], $names) ?? '').' '.self::hours($day['usual'])),
            },
            holiday: $holiday,
            temporary_id: $temporary?->id,
            temporary_from: $temporary?->date->format('Y-m-d'),
            temporary_to: $temporary === null ? null : RosterVersions::appliesThrough($temporary),
        );
    }

    /**
     * The pattern's name, or null for hours with no pattern behind them —
     * typed on the roster, left by a deleted pattern, or saved without one.
     *
     * @param  array<string, string>  $names
     */
    private static function name(DomainShift $shift, array $names): ?string
    {
        return $shift->shiftTypeId !== null && isset($names[$shift->shiftTypeId]) ? $names[$shift->shiftTypeId] : null;
    }

    private static function hours(DomainShift $shift): string
    {
        return $shift->isFlexible()
            ? 'Flexible · '.HrisFormat::hoursPerDay($shift->requiredHoursOrDefault())
            : HrisFormat::hm($shift->startTime).'–'.HrisFormat::hm($shift->endTime);
    }

    /**
     * The approved leave and official business covering each date, by type
     * only, less the dates cancelled out of a request.
     *
     * @param  list<string>  $employeeIds
     * @return array<string, array<string, array{type: string, ob: bool, half: bool}>>
     */
    private function leave(array $employeeIds, string $start, string $end): array
    {
        if ($employeeIds === []) {
            return [];
        }
        $typeNames = [];
        foreach (LeaveType::query()->pluck('name') as $typeName) {
            if (is_string($typeName)) {
                $typeNames[] = $typeName;
            }
        }
        $rows = LeaveTravelRequest::query()->with('cancellations:id,leave_travel_request_id,date')
            ->whereIn('employee_id', $employeeIds)->where('status', 'APPROVED')
            ->whereDate('start_date', '<=', $end)->whereDate('end_date', '>=', $start)
            ->orderBy('start_date')->orderBy('id')
            ->get(LeaveHalves::columns(['id', 'employee_id', 'request_type', 'day_type', 'start_date', 'end_date']));

        $out = [];
        foreach ($rows as $row) {
            $cancelled = [];
            foreach ($row->cancellations as $cancellation) {
                $cancelled[$cancellation->date->format('Y-m-d')] = true;
            }
            $ob = $row->request_type === 'BUSINESS_TRAVEL';
            $type = HrisFormat::requestDescription($row->request_type, $typeNames);
            $from = max($start, $row->start_date->format('Y-m-d'));
            $to = min($end, $row->end_date->format('Y-m-d'));
            foreach ($from <= $to ? CalendarDays::each($from, $to) : [] as $date) {
                if (! isset($cancelled[$date])) {
                    $out[$row->employee_id][$date] = ['type' => $type, 'ob' => $ob, 'half' => $row->day_type === 'HALF_DAY'];
                }
            }
        }

        return $out;
    }

    /**
     * The ids the actor may read under the modules' row scope, in the order
     * given: all of them, the actor's own, or none.
     *
     * @param  list<string>  $employeeIds
     * @return list<string>
     */
    private function visible(Access $actor, array $employeeIds, string $module, string ...$more): array
    {
        $employeeIds = array_values(array_unique(array_filter($employeeIds, static fn (string $id): bool => $id !== '')));
        if ($employeeIds === [] || ! $actor->hasModule($module, ...$more)) {
            return [];
        }

        return match ($actor->rowVisibility($module, ...$more)) {
            Access::ROWS_ALL => $employeeIds,
            Access::ROWS_OWN => array_values(array_intersect($employeeIds, [$actor->ownEmployeeId()])),
            Access::ROWS_NONE => [],
        };
    }
}
