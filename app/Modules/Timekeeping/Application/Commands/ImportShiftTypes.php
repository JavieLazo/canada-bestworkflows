<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application\Commands;

use App\Domain\Enums\ModuleCode;
use App\Models\ShiftType;
use App\Modules\Timekeeping\Application\DatedChange;
use App\Modules\Timekeeping\Application\ShiftTypeDraft;
use App\Platform\Transfer\ImportLayout;
use App\Platform\Transfer\ImportOutcome;
use App\Platform\Transfer\ImportRow;
use App\Platform\Transfer\TableImport;
use App\Support\Access;
use Illuminate\Database\UniqueConstraintViolationException;
use RuntimeException;

/**
 * Shift patterns read back from CSV, one row at a time through
 * {@see SaveShiftType} — its shape rules, its RECORDS gate, and its re-copy
 * of an edited pattern onto every roster row linked to it (which also needs
 * all_data + ROSTER, and fails that row alone without it). ID is the id the
 * table shows: kept, the row updates that pattern; blank, it adds one.
 *
 * A row is UNCHANGED when every cell reads the same as the pattern's own
 * export row, so an untouched file re-copies nothing and audits nothing.
 */
final class ImportShiftTypes implements TableImport
{
    /** The columns an import reads — the shift types export's own. */
    public const array COLUMNS = ['ID', 'Name', 'Mode', 'Work days', 'Start', 'End', 'Required hours', 'Break start', 'Break end', 'Break minutes', 'Clock out for break', 'Grace (min)', 'Effective from'];

    /** 0 = Sunday, as `work_days` stores it (JS getDay()). */
    public const array DAYS = [0 => 'Sun', 1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat'];

    private const array MODES = ['FIXED' => 'Fixed', 'FLEXIBLE' => 'Flexible'];

    /** Every spelling of a day the Work days cell accepts. */
    private const array DAY_WORDS = [
        'sun' => 0, 'sunday' => 0,
        'mon' => 1, 'monday' => 1,
        'tue' => 2, 'tues' => 2, 'tuesday' => 2,
        'wed' => 3, 'wednesday' => 3,
        'thu' => 4, 'thur' => 4, 'thurs' => 4, 'thursday' => 4,
        'fri' => 5, 'friday' => 5,
        'sat' => 6, 'saturday' => 6,
    ];

    public function layout(Access $actor, string $context): ImportLayout
    {
        return new ImportLayout('shift types', 'ID', self::COLUMNS, [
            'Mode is Fixed (Start, End and Grace apply) or Flexible (only Required hours per day applies).',
            'Work days lists days like "Mon, Tue, Wed" or a run like "Mon–Fri". Times are 24-hour, like 08:00.',
            'Changing a pattern employees are rostered on re-copies it onto their roster, which also needs Shift Roster access.',
            'Effective from is the date the employees on a changed pattern follow it: today or later, never the past. Blank means today; a row whose change can no longer start today for someone is refused, so name the date instead.',
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
        $pattern = null;
        if ($code !== '') {
            $pattern = ShiftType::query()->where('code', $code)->first();
            if ($pattern === null) {
                throw new RuntimeException("No shift type has ID {$code}. Leave ID blank to add a new shift type.");
            }
        }

        $mode = $row->choice('Mode', self::MODES);
        if ($mode === '') {
            throw new RuntimeException('Mode is required.');
        }
        $incoming = [
            'name' => trim($row->text('Name')),
            'mode' => $mode,
            'days' => self::days($row->text('Work days')),
            'start' => $row->time('Start') ?? '',
            'end' => $row->time('End') ?? '',
            'hours' => self::number($row->text('Required hours'), 'Required hours'),
            'break_start' => $row->time('Break start') ?? '',
            'break_end' => $row->time('Break end') ?? '',
            'break_minutes' => (string) ($row->integer('Break minutes') ?? ''),
            'punch' => $row->flag('Clock out for break') === true ? '1' : '0',
            'grace' => (string) ($row->integer('Grace (min)') ?? ''),
        ];

        if ($pattern !== null && $incoming === self::current($pattern)) {
            return ImportOutcome::UNCHANGED;
        }

        $fixed = $mode === 'FIXED';
        if ($fixed && ($incoming['start'] === '' || $incoming['end'] === '')) {
            throw new RuntimeException('A fixed pattern needs a Start and an End.');
        }
        $window = $incoming['break_start'] !== '' || $incoming['break_end'] !== '';
        $draft = new ShiftTypeDraft(
            $incoming['name'],
            $mode,
            $incoming['days'],
            $incoming['start'],
            $incoming['end'],
            $incoming['grace'],
            $incoming['hours'],
            $window || (int) $incoming['break_minutes'] > 0,
            $incoming['break_start'],
            $incoming['break_end'],
            $incoming['break_minutes'],
            $incoming['punch'] === '1',
        );

        try {
            // A row cannot answer a review: an edit that could not reach
            // its rostered employees on its date is refused with the
            // reason, the rest is accepted with the import's preview as the
            // confirmation (DatedChange::forImport).
            $from = $row->date('Effective from');
            $id = $pattern === null ? null : (string) $pattern->getKey();
            DatedChange::forImport(static fn (?string $confirmed): string => app(SaveShiftType::class)->handle($actor, $id, $draft, $from, $confirmed));
        } catch (UniqueConstraintViolationException) {
            throw new RuntimeException("\"{$incoming['name']}\" is already a shift type.");
        }

        return $pattern === null ? ImportOutcome::CREATED : ImportOutcome::UPDATED;
    }

    /**
     * A pattern's cells as the export writes them and this import reads them
     * back — the one comparison behind UNCHANGED.
     *
     * @return array{name: string, mode: string, days: list<int>, start: string, end: string, hours: string,
     *     break_start: string, break_end: string, break_minutes: string, punch: string, grace: string}
     */
    private static function current(ShiftType $pattern): array
    {
        $days = array_map(intval(...), $pattern->work_days ?? []);
        sort($days);

        return [
            'name' => $pattern->name,
            'mode' => $pattern->mode,
            'days' => $days,
            'start' => self::hm($pattern->start_time),
            'end' => self::hm($pattern->end_time),
            'hours' => $pattern->required_hours === null ? '' : (string) (float) $pattern->required_hours,
            'break_start' => self::hm($pattern->break_start),
            'break_end' => self::hm($pattern->break_end),
            'break_minutes' => (string) $pattern->break_minutes,
            'punch' => $pattern->requires_break_punch ? '1' : '0',
            'grace' => (string) $pattern->grace_minutes,
        ];
    }

    /** `08:00` from a stored `08:00:00`; "" for none. */
    private static function hm(?string $time): string
    {
        return $time === null || $time === '' ? '' : substr($time, 0, 5);
    }

    /**
     * "Mon, Wed, Fri", "Monday Tuesday", "Mon–Fri" or "Mon-Fri" → the stored
     * day numbers, sorted; a word that is not a day is refused.
     *
     * @return list<int>
     */
    private static function days(string $cell): array
    {
        $index = static function (string $word) use ($cell): int {
            $day = self::DAY_WORDS[strtolower(rtrim($word, '.'))] ?? null;
            if ($day === null) {
                throw new RuntimeException("Work days: {$cell} is not a list of days, like \"Mon, Tue, Wed\" or \"Mon–Fri\".");
            }

            return $day;
        };

        $days = [];
        $cell = (string) preg_replace('/\s*[–—-]\s*/u', '–', trim($cell));
        foreach (preg_split('/[\s,;\/]+/u', $cell) ?: [] as $token) {
            if ($token === '') {
                continue;
            }
            $range = preg_split('/[–—-]/u', $token);
            if ($range !== false && count($range) === 2) {
                [$from, $to] = [$index($range[0]), $index($range[1])];
                for ($day = $from; ; $day = ($day + 1) % 7) {
                    $days[] = $day;
                    if ($day === $to) {
                        break;
                    }
                }

                continue;
            }
            $days[] = $index($token);
        }
        $days = array_values(array_unique($days));
        sort($days);

        return $days;
    }

    /** A plain number, as the dialog's hour field sends it; "" for a blank cell. */
    private static function number(string $cell, string $column): string
    {
        if ($cell === '') {
            return '';
        }
        if (! is_numeric($cell)) {
            throw new RuntimeException("{$column}: {$cell} is not a number.");
        }

        return (string) (float) $cell;
    }
}
