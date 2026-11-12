<?php

declare(strict_types=1);

namespace App\Livewire\Records;

use App\Livewire\Records\Support\PayScheduleText;
use App\Modules\Payroll\Application\Commands\DeletePaySchedule;
use App\Modules\Payroll\Application\Commands\SavePaySchedule;
use App\Modules\Payroll\Application\PayrollSettingsAccess;
use App\Modules\Payroll\Application\PaySchedules;
use App\Modules\Payroll\Application\Queries\PayScheduleRecords;
use App\Modules\Payroll\Contracts\Views\PayScheduleView;
use App\Modules\Payroll\Domain\Schedules\PayCalendar;
use App\Modules\Payroll\Domain\Schedules\PaySchedule;
use App\Support\Access;
use App\Support\ManilaClock;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use RuntimeException;

/**
 * Pay Schedules — how a month is cut into salary periods and when each is
 * paid, under Records → Payroll Settings: the list (periods, pay days,
 * the weekend and holiday rule, deadlines, which is the default, how many
 * payrolls took their dates from each), an Add/Edit dialog whose preview
 * is the next months' pay dates from the typed figures (PayCalendar, the
 * Holidays list applied) and a delete confirmation. Whoever manages the
 * Payroll Settings writes; the commands check again.
 */
#[Layout('layouts.app')]
#[Title('Pay Schedules')]
class PaySchedulesPage extends Component
{
    public const string PERIODS_SEMI_MONTHLY = 'SEMI_MONTHLY';

    public const string PERIODS_MONTHLY = 'MONTHLY';

    public string $search = '';

    /** The dialog's visibility. Plain UI state. */
    public bool $dialogOpen = false;

    /** The schedule being edited, or null when adding. Id-bearing → #[Locked]. */
    #[Locked]
    public ?string $editingId = null;

    public string $formName = '';

    public string $formPeriods = self::PERIODS_SEMI_MONTHLY;

    /**
     * Each period's pay day as typed: kind, day and whether it falls in the
     * next month. Client-editable: the command re-checks every value.
     *
     * @var list<array{kind: string, day: string, next_month: bool}>
     */
    public array $formPayDays = [];

    public string $formHolidayRule = PaySchedule::MOVE_KEEP;

    /**
     * The deadlines as typed, up to four rows of label and days before.
     *
     * @var list<array{label: string, days: string}>
     */
    public array $formDeadlines = [];

    public string $formContributionMonth = PaySchedule::MONTH_PERIOD;

    public string $formLag = '0';

    public bool $formIsDefault = false;

    /** The save's inline error, cleared by the next edit. */
    public ?string $formError = null;

    /** The delete confirmation's visibility. Plain UI state. */
    public bool $confirmOpen = false;

    /** The schedule queued for deletion. Id-bearing → #[Locked]. */
    #[Locked]
    public ?string $deletingId = null;

    /** Any edit clears the last refusal. */
    public function updated(string $name): void
    {
        if (str_starts_with($name, 'form')) {
            $this->formError = null;
        }
    }

    /** The number of pay-day rows follows the periods chosen. */
    public function updatedFormPeriods(): void
    {
        $this->formPayDays = array_slice([...$this->formPayDays, self::payDayRow(), self::payDayRow()], 0, $this->formPeriods === self::PERIODS_MONTHLY ? 1 : 2);
    }

    public function openAdd(): void
    {
        if (! PayrollSettingsAccess::mayManage(app(Access::class))) {
            return;
        }
        $this->editingId = null;
        $this->fillFrom(PaySchedule::standard(), '', false);
        $this->dialogOpen = true;
    }

    public function startEdit(string $id): void
    {
        $access = app(Access::class);
        if (! PayrollSettingsAccess::mayManage($access)) {
            return;
        }
        $view = app(PayScheduleRecords::class)->find($access, $id);
        $schedule = $view === null ? null : PayScheduleText::schedule($view);
        if ($view === null || $schedule === null) {
            Flux::toast(variant: 'danger', text: 'That pay schedule no longer exists.');

            return;
        }
        $this->editingId = $view->id;
        $this->fillFrom($schedule, $view->name, (bool) $view->is_default);
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
    }

    public function save(): void
    {
        $access = app(Access::class);
        if (! PayrollSettingsAccess::mayManage($access)) {
            Flux::toast(variant: 'danger', text: 'Your role cannot manage organization-wide data.');

            return;
        }
        $figures = $this->figures();
        try {
            app(SavePaySchedule::class)->handle($access, $this->editingId, $this->formName, $figures, $this->formIsDefault);
        } catch (QueryException $e) {
            report($e);
            Flux::toast(variant: 'danger', text: 'The pay schedule could not be saved. Nothing was changed. Please try again.');

            return;
        } catch (RuntimeException $e) {
            $this->formError = $e->getMessage();

            return;
        }
        Flux::toast(variant: 'success', text: $this->editingId === null ? 'Pay schedule added.' : 'Pay schedule saved.');
        $this->dialogOpen = false;
        $this->editingId = null;
    }

    /** Makes a schedule the default: the same save with the flag on. */
    public function makeDefault(string $id): void
    {
        $access = app(Access::class);
        if (! PayrollSettingsAccess::mayManage($access)) {
            Flux::toast(variant: 'danger', text: 'Your role cannot manage organization-wide data.');

            return;
        }
        $view = app(PayScheduleRecords::class)->find($access, $id);
        if ($view === null) {
            Flux::toast(variant: 'danger', text: 'That pay schedule no longer exists.');

            return;
        }
        try {
            app(SavePaySchedule::class)->handle($access, $view->id, $view->name, $view->params, true);
        } catch (QueryException $e) {
            report($e);
            Flux::toast(variant: 'danger', text: 'The pay schedule could not be saved. Nothing was changed. Please try again.');

            return;
        } catch (RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }
        Flux::toast(variant: 'success', text: "\"{$view->name}\" is now the default schedule.");
    }

    public function confirmDelete(string $id): void
    {
        if (! PayrollSettingsAccess::mayManage(app(Access::class))) {
            return;
        }
        $this->deletingId = $id;
        $this->confirmOpen = true;
    }

    public function deleteConfirmed(): void
    {
        $id = $this->deletingId;
        $this->confirmOpen = false;
        $this->deletingId = null;
        if ($id === null) {
            return;
        }
        $access = app(Access::class);
        try {
            $message = app(DeletePaySchedule::class)->handle($access, $id);
        } catch (QueryException $e) {
            report($e);
            Flux::toast(variant: 'danger', text: 'The pay schedule could not be deleted. Try again.');

            return;
        } catch (RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }
        Flux::toast(variant: 'success', text: $message);
    }

    public function render(): View
    {
        $access = app(Access::class);
        $needle = mb_strtolower(trim($this->search));
        $rows = app(PayScheduleRecords::class)->list($access)
            ->filter(static fn (PayScheduleView $view): bool => $needle === '' || str_contains(mb_strtolower($view->name), $needle))
            ->values();

        return view('livewire.records.pay-schedules-page', [
            'rows' => $rows,
            'ready' => PaySchedules::ready(),
            'canEdit' => PayrollSettingsAccess::mayManage($access),
            'preview' => $this->dialogOpen ? $this->preview() : [],
            'deleting' => $this->deletingId === null ? null : $rows->first(fn (PayScheduleView $view): bool => $view->id === $this->deletingId),
        ]);
    }

    /** The form from a schedule's figures. */
    private function fillFrom(PaySchedule $schedule, string $name, bool $isDefault): void
    {
        $this->formName = $name;
        $this->formPeriods = $schedule->isSemiMonthly() ? self::PERIODS_SEMI_MONTHLY : self::PERIODS_MONTHLY;
        $this->formPayDays = [];
        foreach ($schedule->periods as $period) {
            $this->formPayDays[] = ['kind' => $period['pay_day']['kind'], 'day' => $period['pay_day']['day'] === null ? '' : (string) $period['pay_day']['day'], 'next_month' => $period['pay_day']['next_month']];
        }
        $this->formHolidayRule = $schedule->holidayRule;
        $this->formDeadlines = array_map(static fn (array $d): array => ['label' => $d['label'], 'days' => (string) $d['days_before']], $schedule->deadlines);
        $this->formContributionMonth = $schedule->contributionMonth;
        $this->formLag = (string) $schedule->attendanceLag;
        $this->formIsDefault = $isDefault;
        $this->formError = null;
    }

    /**
     * The typed form as a schedule's figures, read defensively; the
     * command refuses what does not read.
     *
     * @return array<string, mixed>
     */
    private function figures(): array
    {
        $periods = $this->formPeriods === self::PERIODS_MONTHLY ? [[1, null]] : [[1, 15], [16, null]];
        $out = [];
        foreach ($periods as $index => [$start, $end]) {
            $row = $this->formPayDays[$index] ?? self::payDayRow();
            $kind = $row['kind'];
            $day = is_numeric($row['day']) ? (int) $row['day'] : null;
            $out[] = ['start' => $start, 'end' => $end, 'pay_day' => ['kind' => $kind, 'day' => $kind === PaySchedule::PAY_DAY ? $day : null, 'next_month' => $row['next_month']]];
        }
        $deadlines = [];
        foreach ($this->formDeadlines as $row) {
            $label = trim($row['label']);
            $days = trim($row['days']);
            if ($label === '' && $days === '') {
                continue;
            }
            $deadlines[] = ['label' => $label, 'days_before' => is_numeric($days) ? (int) $days : -1];
        }

        return [
            'periods' => $out,
            'holiday_rule' => $this->formHolidayRule,
            'deadlines' => $deadlines,
            'contribution_month' => $this->formContributionMonth,
            'attendance_lag' => is_numeric($this->formLag) ? (int) $this->formLag : -1,
        ];
    }

    /**
     * The next two months' pay dates from the typed figures, with the
     * Holidays list applied; nothing while the figures do not read.
     *
     * @return list<array{start: string, end: string, pay_date: string, deadlines: list<array{label: string, date: string}>}>
     */
    private function preview(): array
    {
        try {
            $schedule = PaySchedule::fromArray($this->figures());
        } catch (InvalidArgumentException) {
            return [];
        }
        $today = ManilaClock::todayKey();
        $year = (int) substr($today, 0, 4);
        $month = (int) substr($today, 5, 2);
        $holidays = PaySchedules::holidayDates(PaySchedules::yearsAround($today));
        $out = [];
        for ($i = 0; $i < 2; $i++) {
            foreach (PayCalendar::month($schedule, $year, $month, $holidays) as $period) {
                $out[] = ['start' => $period['start'], 'end' => $period['end'], 'pay_date' => $period['pay_date'], 'deadlines' => $period['deadlines']];
            }
            if (++$month > 12) {
                $month = 1;
                $year++;
            }
        }

        return $out;
    }

    /** @return array{kind: string, day: string, next_month: bool} */
    private static function payDayRow(): array
    {
        return ['kind' => PaySchedule::PAY_PERIOD_END, 'day' => '', 'next_month' => false];
    }
}
