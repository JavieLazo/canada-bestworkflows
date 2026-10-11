<?php

declare(strict_types=1);

namespace App\Modules\Organization\Application\Commands;

use App\Domain\Enums\ModuleCode;
use App\Models\Holiday;
use App\Modules\Organization\Application\HolidayDraft;
use App\Platform\Transfer\ImportLayout;
use App\Platform\Transfer\ImportOutcome;
use App\Platform\Transfer\ImportRow;
use App\Platform\Transfer\TableImport;
use App\Support\Access;
use Illuminate\Database\UniqueConstraintViolationException;
use RuntimeException;

/**
 * The holiday calendar read back from CSV, one row at a time through
 * {@see SaveHoliday} — its validation, its all_data + RECORDS gate, its
 * minted id and audit row. ID is the id the calendar shows: kept, the row
 * updates that holiday; blank, it adds one; unknown, it is refused.
 *
 * Schedule and Type are the labels the table prints ("Every year",
 * "Regular Holiday"); a blank one is refused rather than defaulted, since
 * the type decides the day's pay.
 */
final class ImportHolidays implements TableImport
{
    /** The columns an import reads — the holidays export's own. */
    public const array COLUMNS = ['ID', 'Name', 'Date', 'Schedule', 'Type', 'Percent'];

    public function layout(Access $actor, string $context): ImportLayout
    {
        return new ImportLayout('holidays', 'ID', self::COLUMNS, [
            'Schedule is '.implode(' or ', SaveHoliday::SCHEDULES).'. Type is '.implode(' or ', SaveHoliday::TYPES).'.',
            'Percent is the share of the daily rate paid for the day, e.g. 200.',
        ]);
    }

    public function allows(Access $actor, string $context): bool
    {
        return $actor->mayReadAllData(ModuleCode::RECORDS);
    }

    public function row(Access $actor, string $context, ImportRow $row): ImportOutcome
    {
        abort_unless($this->allows($actor, $context), 403);
        $code = $row->identifier('ID');
        $holiday = null;
        if ($code !== '') {
            $holiday = Holiday::query()->where('code', $code)->first();
            if ($holiday === null) {
                throw new RuntimeException("No holiday has ID {$code}. Leave ID blank to add a new holiday.");
            }
        }

        $schedule = $row->choice('Schedule', SaveHoliday::SCHEDULES);
        $type = $row->choice('Type', SaveHoliday::TYPES);
        if ($schedule === '' || $type === '') {
            throw new RuntimeException($schedule === '' ? 'Schedule is required.' : 'Type is required.');
        }
        $draft = new HolidayDraft($row->text('Name'), $row->date('Date') ?? '', $schedule, $type, rtrim($row->text('Percent'), '% '));

        if ($holiday !== null
            && $holiday->name === trim($draft->formName)
            && $holiday->date->format('Y-m-d') === $draft->formDate
            && $holiday->schedule === $schedule
            && $holiday->type === $type
            && is_numeric($draft->formPercent) && (float) $holiday->percent === (float) $draft->formPercent) {
            return ImportOutcome::UNCHANGED;
        }

        try {
            app(SaveHoliday::class)->handle($actor, $holiday === null ? null : (string) $holiday->getKey(), $draft);
        } catch (UniqueConstraintViolationException) {
            // Two uniques can refuse the row: name + date, or the id SaveHoliday
            // mints without a lock (a clash with an add made at the same moment).
            $taken = Holiday::query()->where('name', trim($draft->formName))->where('date', $draft->formDate)->exists();
            throw new RuntimeException($taken
                ? "\"{$draft->formName}\" is already on the calendar for {$draft->formDate}."
                : 'Another holiday was added at the same moment. Import the file again.');
        }

        return $holiday === null ? ImportOutcome::CREATED : ImportOutcome::UPDATED;
    }
}
