<?php

declare(strict_types=1);

namespace App\Livewire\Records;

use App\Livewire\Records\Support\PayScheduleText;
use App\Modules\Payroll\Application\Commands\SavePaySchedule;
use App\Modules\Payroll\Application\PayrollSettingsAccess;
use App\Modules\Payroll\Application\PaySchedules;
use App\Modules\Payroll\Application\Queries\PayScheduleRecords;
use App\Modules\Payroll\Contracts\Views\PayScheduleView;
use App\Modules\Payroll\Domain\Schedules\PayCalendar;
use App\Modules\Payroll\Domain\Schedules\PaySchedule;
use App\Support\Access;
use App\Support\LocalClock;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use RuntimeException;

/**
 * Pay Schedule — how the year is divided into pay periods and when each is
 * paid, under Records → Payroll Settings. The page reads first: the
 * company's schedule (the default one, which every new payroll takes its
 * period and pay date from) as six plain figures, and the pay dates it
 * gives for the coming periods with the Holidays list applied. Edit opens
 * a dialog on the same figures — every week, every two weeks, twice a month
 * or once a month — with the coming pay dates worked out from what is
 * typed. Whoever manages the Payroll Settings writes; the command checks
 * again.
 */
#[Layout('layouts.app')]
#[Title('Pay Schedule')]
class PaySchedulesPage extends Component
{
    public const string EVERY_WEEK = 'WEEKLY';

    public const string EVERY_TWO_WEEKS = 'BIWEEKLY';

    public const string TWICE_A_MONTH = 'SEMI_MONTHLY';

    public const string ONCE_A_MONTH = 'MONTHLY';

    /** How often the company pays, as the dialog offers it. */
    public const array FREQUENCIES = [
        self::EVERY_WEEK => 'Every Week',
        self::EVERY_TWO_WEEKS => 'Every Two Weeks',
        self::TWICE_A_MONTH => 'Twice a Month',
        self::ONCE_A_MONTH => 'Once a Month',
    ];

    /** How many coming periods the page and the dialog work out. */
    private const int COMING = 4;

    /** The Edit dialog. Plain UI state. */
    public bool $dialogOpen = false;

    public string $formFrequency = self::EVERY_TWO_WEEKS;

    /** The first day of a period, 'Y-m-d', on a schedule that runs by weeks. */
    public string $formAnchor = '';

    /** Days from a period's last day to its pay day, on a schedule that runs by weeks. */
    public string $formPayAfter = '6';

    /**
     * One row per period of a month: how its pay day is set.
     *
     * @var list<array{kind: string, day: string, next_month: bool}>
     */
    public array $formPayDays = [];

    public string $formHolidayRule = PaySchedule::MOVE_BEFORE;

    /**
     * The reminders before a pay day, as typed.
     *
     * @var list<array{label: string, days: string}>
     */
    public array $formDeadlines = [];

    /** Shown under the form; cleared as the form changes. */
    public ?string $formError = null;

    public function updated(string $name): void
    {
        if (str_starts_with($name, 'form')) {
            $this->formError = null;
        }
    }

    /** The number of pay-day rows follows how often the month is paid. */
    public function updatedFormFrequency(): void
    {
        $rows = match ($this->formFrequency) {
            self::ONCE_A_MONTH => 1,
            self::TWICE_A_MONTH => 2,
            default => 0,
        };
        $this->formPayDays = array_slice([...$this->formPayDays, self::payDayRow(), self::payDayRow()], 0, $rows);
    }

    /** Opens the dialog on the company's schedule, or on the standard one when none is set. */
    public function startEdit(): void
    {
        $access = app(Access::class);
        if (! PayrollSettingsAccess::mayManage($access)) {
            return;
        }
        $view = app(PayScheduleRecords::class)->default($access);
        $schedule = $view === null ? null : PayScheduleText::schedule($view);
        $this->fillFrom($schedule ?? PaySchedule::everyTwoWeeks(self::lastSunday(LocalClock::todayKey()), 6));
        $this->dialogOpen = true;
    }

    public function addDeadline(): void
    {
        if (count($this->formDeadlines) < PaySchedule::MAX_DEADLINES) {
            $this->formDeadlines[] = ['label' => '', 'days' => ''];
        }
    }

    public function removeDeadline(int $index): void
    {
        if (isset($this->formDeadlines[$index])) {
            array_splice($this->formDeadlines, $index, 1);
        }
        $this->formError = null;
    }

    /** Saves the typed figures as the company's schedule through the audited command. */
    public function save(): void
    {
        $access = app(Access::class);
        if (! PayrollSettingsAccess::mayManage($access)) {
            Flux::toast(variant: 'danger', text: 'Your role cannot manage organization-wide data.');

            return;
        }
        $records = app(PayScheduleRecords::class);
        $current = $records->default($access);
        $figures = $this->figures($current === null ? null : PayScheduleText::schedule($current));
        try {
            $schedule = PaySchedule::fromArray($figures);
        } catch (InvalidArgumentException $e) {
            $this->formError = $e->getMessage();

            return;
        }
        // The name is the schedule in words; a number is added when another
        // schedule on record already reads the same.
        $taken = $records->list($access)
            ->reject(static fn (PayScheduleView $view): bool => $view->id === $current?->id)
            ->map(static fn (PayScheduleView $view): string => mb_strtolower($view->name))->all();
        $name = $base = PayScheduleText::name($schedule);
        for ($n = 2; in_array(mb_strtolower($name), $taken, true); $n++) {
            $name = "{$base} ({$n})";
        }
        try {
            app(SavePaySchedule::class)->handle($access, $current?->id, $name, $figures, true);
        } catch (QueryException $e) {
            report($e);
            Flux::toast(variant: 'danger', text: 'The pay schedule could not be saved. Nothing was changed. Please try again.');

            return;
        } catch (RuntimeException $e) {
            $this->formError = $e->getMessage();

            return;
        }
        Flux::toast(variant: 'success', text: 'Pay schedule saved. New payrolls take their dates from it.');
        $this->dialogOpen = false;
    }

    public function render(): View
    {
        $access = app(Access::class);
        $view = app(PayScheduleRecords::class)->default($access);
        $schedule = $view === null ? null : PayScheduleText::schedule($view);
        $today = LocalClock::todayKey();
        $year = (int) substr($today, 0, 4);
        $holidays = PaySchedules::ready() ? PaySchedules::holidayNames([$year - 1, $year, $year + 1, $year + 2]) : [];

        return view('livewire.records.pay-schedules-page', [
            'ready' => PaySchedules::ready(),
            'canEdit' => PayrollSettingsAccess::mayManage($access),
            'row' => $view,
            'schedule' => $schedule,
            'coming' => $schedule === null ? [] : self::coming($schedule, $today, $holidays),
            'extraPayYears' => $schedule === null ? [] : self::extraPayYears($schedule, $year, array_keys($holidays)),
            'preview' => $this->dialogOpen ? $this->preview($today, $holidays) : [],
        ]);
    }

    /** The form from a schedule's figures. */
    private function fillFrom(PaySchedule $schedule): void
    {
        $this->formFrequency = match (true) {
            $schedule->isByWeeks() && $schedule->periodDays() === 7 => self::EVERY_WEEK,
            $schedule->isByWeeks() => self::EVERY_TWO_WEEKS,
            count($schedule->periods) === 1 => self::ONCE_A_MONTH,
            default => self::TWICE_A_MONTH,
        };
        $this->formAnchor = (string) ($schedule->cycle['anchor'] ?? self::lastSunday(LocalClock::todayKey()));
        $this->formPayAfter = (string) ($schedule->cycle['pay_after_days'] ?? 6);
        $this->formPayDays = [];
        foreach ($schedule->periods as $period) {
            $this->formPayDays[] = ['kind' => $period['pay_day']['kind'], 'day' => $period['pay_day']['day'] === null ? '' : (string) $period['pay_day']['day'], 'next_month' => $period['pay_day']['next_month']];
        }
        $this->formHolidayRule = $schedule->holidayRule;
        $this->formDeadlines = array_map(static fn (array $d): array => ['label' => $d['label'], 'days' => (string) $d['days_before']], $schedule->deadlines);
        $this->formError = null;
    }

    /**
     * The typed form as a schedule's figures, read defensively; the
     * command refuses what does not read. What the dialog does not offer
     * stays as the schedule on record holds it.
     *
     * @return array<string, mixed>
     */
    private function figures(?PaySchedule $current): array
    {
        $deadlines = [];
        foreach ($this->formDeadlines as $row) {
            $label = trim($row['label']);
            $days = trim($row['days']);
            if ($label === '' && $days === '') {
                continue;
            }
            $deadlines[] = ['label' => $label, 'days_before' => is_numeric($days) ? (int) $days : -1];
        }
        $shared = [
            'holiday_rule' => $this->formHolidayRule,
            'deadlines' => $deadlines,
            // A pay counts in the month and the year of its pay day.
            'contribution_month' => PaySchedule::MONTH_PAY_DATE,
            'attendance_lag' => $current === null ? 0 : $current->attendanceLag,
        ];

        if (in_array($this->formFrequency, [self::EVERY_WEEK, self::EVERY_TWO_WEEKS], true)) {
            return [
                'periods' => [],
                ...$shared,
                'cycle' => [
                    'weeks' => $this->formFrequency === self::EVERY_WEEK ? 1 : 2,
                    'anchor' => trim($this->formAnchor),
                    'pay_after_days' => preg_match('/^\d{1,3}$/', trim($this->formPayAfter)) === 1 ? (int) trim($this->formPayAfter) : -1,
                ],
            ];
        }

        $periods = $this->formFrequency === self::ONCE_A_MONTH ? [[1, null]] : [[1, 15], [16, null]];
        $out = [];
        foreach ($periods as $index => [$start, $end]) {
            $row = $this->formPayDays[$index] ?? self::payDayRow();
            $kind = $row['kind'];
            $day = is_numeric($row['day']) ? (int) $row['day'] : null;
            $out[] = ['start' => $start, 'end' => $end, 'pay_day' => ['kind' => $kind, 'day' => $kind === PaySchedule::PAY_DAY ? $day : null, 'next_month' => $row['next_month']]];
        }

        return ['periods' => $out, ...$shared];
    }

    /**
     * The coming pay dates from the typed figures; nothing while they do
     * not read.
     *
     * @param  array<string, string>  $holidays
     * @return list<array{start: string, end: string, pay_date: string, moved_from: ?string, new_year: ?int, deadlines: list<array{label: string, date: string}>}>
     */
    private function preview(string $today, array $holidays): array
    {
        try {
            $schedule = PaySchedule::fromArray($this->figures(null));
        } catch (InvalidArgumentException) {
            return [];
        }

        return self::coming($schedule, $today, $holidays);
    }

    /**
     * The period today falls in and the ones after it, each with what its
     * pay day was moved off and whether it is the first pay of a new year.
     *
     * @param  array<string, string>  $holidays  date => holiday name
     * @return list<array{start: string, end: string, pay_date: string, moved_from: ?string, new_year: ?int, deadlines: list<array{label: string, date: string}>}>
     */
    private static function coming(PaySchedule $schedule, string $today, array $holidays): array
    {
        $dates = array_keys($holidays);
        $unmoved = PaySchedule::fromArray([...$schedule->toArray(), 'holiday_rule' => PaySchedule::MOVE_KEEP]);
        $out = [];
        $before = PayCalendar::following($schedule, $today, self::COMING + 1, $dates);
        // The pay year of the period before the first one shown is not known
        // here, so the first row never reads as a new year's first pay.
        $lastYear = null;
        foreach (array_slice($before, 0, self::COMING) as $period) {
            $plain = PayCalendar::containing($unmoved, $period['start'], $dates)['pay_date'];
            $payYear = (int) substr($period['pay_date'], 0, 4);
            $out[] = [
                'start' => $period['start'],
                'end' => $period['end'],
                'pay_date' => $period['pay_date'],
                'moved_from' => $plain === $period['pay_date'] ? null : ($holidays[$plain] ?? 'the weekend'),
                'new_year' => $lastYear !== null && $payYear > $lastYear ? $payYear : null,
                'deadlines' => $period['deadlines'],
            ];
            $lastYear = $payYear;
        }

        return $out;
    }

    /**
     * This year and the next, where the schedule pays one more time than
     * in a usual year: year => pay days.
     *
     * @param  list<string>  $holidays
     * @return array<int, int>
     */
    private static function extraPayYears(PaySchedule $schedule, int $year, array $holidays): array
    {
        if (! $schedule->isByWeeks()) {
            return [];
        }
        $out = [];
        foreach ([$year, $year + 1] as $each) {
            $count = PayCalendar::payDatesInYear($schedule, $each, $holidays);
            if ($count > $schedule->usualPaysPerYear()) {
                $out[$each] = $count;
            }
        }

        return $out;
    }

    /** The Sunday on or before a day: where a week of work starts. */
    private static function lastSunday(string $day): string
    {
        $stamp = (int) strtotime($day.' 00:00:00 UTC');

        return gmdate('Y-m-d', $stamp - ((int) gmdate('w', $stamp)) * 86400);
    }

    /** @return array{kind: string, day: string, next_month: bool} */
    private static function payDayRow(): array
    {
        return ['kind' => PaySchedule::PAY_PERIOD_END, 'day' => '', 'next_month' => false];
    }
}
