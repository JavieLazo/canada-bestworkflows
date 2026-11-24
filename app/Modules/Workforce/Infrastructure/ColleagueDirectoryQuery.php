<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Infrastructure;

use App\Domain\Enums\ModuleCode;
use App\Models\EmployeeProfile;
use App\Modules\Workforce\Contracts\Colleague;
use App\Modules\Workforce\Contracts\ColleagueAnniversary;
use App\Modules\Workforce\Contracts\ColleagueBirthday;
use App\Modules\Workforce\Contracts\ColleagueDirectory;
use App\Platform\ReadModels\Snapshot;
use App\Support\Access;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** The social tier: every Community or Chat holder may read it, so it selects six columns and no more. */
final class ColleagueDirectoryQuery implements ColleagueDirectory
{
    private const array COLUMNS = ['id', 'first_name', 'last_name', 'suffix', 'position', 'photo_path'];

    public function find(Access $actor, array $employeeIds): Collection
    {
        abort_unless($actor->hasModule(ModuleCode::COMMUNITY, ModuleCode::CHAT), 403);

        if ($employeeIds === []) {
            return collect();
        }

        return EmployeeProfile::query()
            ->whereKey(array_values(array_unique($employeeIds)))
            ->get(self::COLUMNS)
            ->mapWithKeys(static fn (EmployeeProfile $row): array => [(string) $row->id => Snapshot::of($row, Colleague::class)]);
    }

    public function anniversaries(Access $actor, array $employeeIds, CarbonImmutable $today, int $days): Collection
    {
        abort_unless($actor->hasModule(ModuleCode::COMMUNITY, ModuleCode::CHAT), 403);

        $employeeIds = array_values(array_unique($employeeIds));
        if ($employeeIds === []) {
            return collect();
        }

        // Compared as calendar days: DATE columns carry no zone, and `$today`
        // is already the local day the caller means.
        $first = CarbonImmutable::createFromFormat('!Y-m-d', $today->format('Y-m-d'), 'UTC');
        if ($first === null) {
            return collect();
        }
        $last = $first->addDays(max(1, $days) - 1);

        $found = [];
        $rows = EmployeeProfile::query()
            ->whereKey($employeeIds)
            ->where('is_active', true)
            ->whereNotNull('date_hired')
            ->get([...self::COLUMNS, 'date_hired']);

        foreach ($rows as $row) {
            $hired = $row->date_hired;

            $date = $this->coming($first, $hired->format('m-d'));
            if ($date === null) {
                continue;
            }

            $years = $date->year - (int) $hired->format('Y');
            if ($years < 1 || $date->gt($last)) {
                continue;
            }

            $row->makeHidden('date_hired');
            $found[] = new ColleagueAnniversary(Snapshot::of($row, Colleague::class), $date, $years);
        }

        usort($found, static fn (ColleagueAnniversary $a, ColleagueAnniversary $b): int => $a->date <=> $b->date);

        return collect($found);
    }

    public function birthdays(Access $actor, array $employeeIds, CarbonImmutable $today, int $days): Collection
    {
        abort_unless($actor->hasModule(ModuleCode::COMMUNITY, ModuleCode::CHAT), 403);

        $employeeIds = array_values(array_unique($employeeIds));
        if ($employeeIds === []) {
            return collect();
        }

        $first = CarbonImmutable::createFromFormat('!Y-m-d', $today->format('Y-m-d'), 'UTC');
        if ($first === null) {
            return collect();
        }
        $last = $first->addDays(max(1, $days) - 1);

        $found = [];
        $rows = EmployeeProfile::query()
            ->whereKey($employeeIds)
            ->where('is_active', true)
            ->whereNotNull('birth_date')
            ->get([...self::COLUMNS, 'birth_date']);

        foreach ($rows as $row) {
            $date = $this->coming($first, $row->birth_date->format('m-d'));
            if ($date === null || $date->gt($last)) {
                continue;
            }

            // The year they were born stops here. Only the day travels on.
            $row->makeHidden('birth_date');
            $found[] = new ColleagueBirthday(Snapshot::of($row, Colleague::class), $date);
        }

        usort($found, static fn (ColleagueBirthday $a, ColleagueBirthday $b): int => $a->date <=> $b->date);

        return collect($found);
    }

    /**
     * The next time a month and day comes around: this year's, or next
     * year's once this year's has passed. A 29 February date rolls over to
     * 1 March in the years that don't have one.
     */
    private function coming(CarbonImmutable $first, string $monthDay): ?CarbonImmutable
    {
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $first->year.'-'.$monthDay, 'UTC');

        if ($date === null) {
            return null;
        }

        return $date->lt($first) ? $date->addYear() : $date;
    }
}
