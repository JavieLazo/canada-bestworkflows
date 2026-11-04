<?php

declare(strict_types=1);

namespace App\Livewire\Portal;

use App\Domain\Attendance\CalendarDays;
use App\Domain\Enums\ModuleCode;
use App\Domain\Payroll\PayrollPeriod;
use App\Livewire\Edtr\Support\Hris;
use App\Modules\Timekeeping\Application\Queries\CompensatoryHours;
use App\Modules\Timekeeping\Application\Queries\OwnAttendance;
use App\Support\Access;
use App\Support\ManilaClock;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Component;

/** Keeps attendance filters in the UI while Timekeeping resolves the current account's own record on every read. */
class MyAttendance extends Component
{
    public string $start = '';

    public string $end = '';

    public function mount(): void
    {
        $this->thisCutoff();
    }

    public function thisCutoff(): void
    {
        $this->setCutoff(ManilaClock::todayKey());
    }

    public function previousCutoff(): void
    {
        $period = Hris::semiMonthlyPeriod(PayrollPeriod::isPayrollDate($this->start) ? $this->start : null);
        $this->setCutoff(CalendarDays::previous($period['start']));
    }

    public function nextCutoff(): void
    {
        $period = Hris::semiMonthlyPeriod(PayrollPeriod::isPayrollDate($this->end) ? $this->end : null);
        $this->setCutoff(CalendarDays::next($period['end']));
    }

    /** Re-render all attendance inputs, including approved leave and schedule changes. */
    public function refreshAttendance(): void
    {
        abort_unless(app(Access::class)->hasModule(ModuleCode::PORTAL), 403);
    }

    private function setCutoff(string $date): void
    {
        abort_unless(app(Access::class)->hasModule(ModuleCode::PORTAL), 403);
        $period = Hris::semiMonthlyPeriod($date);
        $this->start = $period['start'];
        $this->end = $period['end'];
    }

    public function render(): View
    {
        $access = app(Access::class);
        abort_unless($access->hasModule(ModuleCode::PORTAL), 403);
        $this->resetValidation('period');
        $attendance = null;

        try {
            $attendance = app(OwnAttendance::class)->between($access, $this->start, $this->end);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError($field, $message);
                }
            }
        }

        return view('livewire.portal.my-attendance', [
            'attendance' => $attendance,
            // Compensatory Leave hours earned: the overtime minutes credited instead of paid, the employee's own rows.
            'compMinutes' => $access->hasEmployeeLink() ? app(CompensatoryHours::class)->earnedMinutes($access, $access->ownEmployeeId()) : 0,
            'linked' => $access->hasEmployeeLink(),
            'today' => ManilaClock::todayKey(),
        ]);
    }
}
