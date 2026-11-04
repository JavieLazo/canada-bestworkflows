<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Infrastructure;

use App\Domain\Enums\ModuleCode;
use App\Domain\Enums\PayrollRunKind;
use App\Domain\Enums\PayrollRunStatus;
use App\Domain\Reminders\ReminderSweep;
use App\Models\PayrollPaySchedule;
use App\Models\PayrollRun;
use App\Modules\Payroll\Application\PayrollPeriodText;
use App\Modules\Payroll\Application\PaySchedules;
use App\Modules\Payroll\Domain\Schedules\PayCalendar;
use App\Platform\Notifications\ReminderSource;
use App\Support\Access;

/**
 * The payroll reminders (PAYROLL_DUE): for each pay schedule, the periods
 * whose pay date or deadline is close and that no finalized regular
 * payroll yet covers — handed to the pure rules precomputed. Told only to
 * all-data PAYROLL holders, the people who run payroll; the dataset is
 * pre-scoped here, so the rule never reaches anyone else. The schedule id
 * is the key's record segment, which `visibleIds` authorizes.
 */
final class ReminderData implements ReminderSource
{
    public function groups(): array
    {
        return ['payroll'];
    }

    public function candidates(Access $actor, string $today, string $group): iterable
    {
        if (! $actor->mayReadAllData(ModuleCode::PAYROLL) || ! PaySchedules::ready()) {
            return;
        }
        $payrolls = [];
        // The company's default schedule is the calendar payroll runs on;
        // another schedule reminds nobody.
        $default = PaySchedules::default();
        foreach ($default === null ? [] : [$default] as $row) {
            $schedule = PaySchedules::schedule($row);
            $holidays = PaySchedules::holidayDates(PaySchedules::yearsAround($today));
            foreach (PayCalendar::upcoming($schedule, $today, ReminderSweep::PAYROLL_NOTICE_DAYS, $holidays) as $period) {
                if ($this->finalized($period['start'], $period['end'])) {
                    continue;
                }
                $deadline = null;
                foreach ($period['deadlines'] as $d) {
                    if ($d['date'] >= $today) {
                        $deadline = $d['label'].' by '.PayrollPeriodText::day($d['date']);
                        break;
                    }
                }
                $payrolls[] = [
                    'schedule_id' => (string) $row->id,
                    'start' => $period['start'],
                    'end' => $period['end'],
                    'pay_date' => $period['pay_date'],
                    'label' => PayrollPeriodText::day($period['start']).' – '.PayrollPeriodText::day($period['end']),
                    'deadline' => $deadline,
                ];
            }
        }
        if ($payrolls === []) {
            return;
        }
        yield ReminderSweep::build($today, (string) $actor->userId, $actor->modules, $actor->employeeId, group: 'payroll', payrolls: $payrolls);
    }

    public function visibleIds(Access $actor, string $group, array $ids): array
    {
        if ($ids === [] || ! $actor->mayReadAllData(ModuleCode::PAYROLL) || ! PaySchedules::ready()) {
            return [];
        }

        return array_values(array_map('strval', PayrollPaySchedule::query()->whereKey($ids)->pluck('id')->all()));
    }

    /** Whether a finalized regular payroll covers exactly these dates. */
    private function finalized(string $start, string $end): bool
    {
        return PayrollRun::query()->where('kind', PayrollRunKind::REGULAR)->where('status', PayrollRunStatus::FINALIZED)
            ->whereDate('period_start', $start)->whereDate('period_end', $end)->exists();
    }
}
