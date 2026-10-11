<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application;

use App\Domain\Payroll\PayrollPeriod;
use App\Domain\Payroll\Statutory;
use App\Domain\Payroll\StatutorySchedule;
use App\Models\PayrollStatutoryTable;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;

/**
 * The Contribution & Tax Tables as payroll reads them: which version of each
 * of the four tables prices a period, and whether the period can be priced
 * at all.
 *
 * A period is priced by the version in force on its FIRST day — the latest
 * version whose `effective_from` is on or before it — and refused when that
 * version does not exist, is not known good through the period's last day
 * (`covered_through`), or a later version takes effect inside the period: a
 * payslip carries one set of figures, so a change splits payrolls. The 13th
 * month run reads only the withholding table, in force on 31 December of its
 * year.
 *
 * Files reach the host before the cron runs their migration, so while the
 * table is absent every reader gets the engine's built-in figures and their
 * constant coverage, exactly what payroll computed before the table existed.
 */
final class StatutoryTables
{
    /** @var array<string, string> the kinds' names on screen */
    private const array LABELS = [
        StatutorySchedule::SSS => 'SSS',
        StatutorySchedule::PHILHEALTH => 'PhilHealth',
        StatutorySchedule::PAGIBIG => 'Pag-IBIG',
        StatutorySchedule::WITHHOLDING_TAX => 'Withholding Tax',
    ];

    public static function ready(): bool
    {
        return once(static fn (): bool => Schema::hasTable('payroll_statutory_tables'));
    }

    public static function label(string $kind): string
    {
        return self::LABELS[$kind] ?? $kind;
    }

    /** The cut-off check every regular payroll makes: the dates, then the tables. */
    public function cutoffError(string $periodStart, string $periodEnd): ?string
    {
        return PayrollPeriod::cutoffError($periodStart, $periodEnd, fn (string $start, string $end): ?string => $this->resolve($start, $end)->error);
    }

    /** The 13th month run's check: the year's withholding table, in force on 31 December. */
    public function yearlyCutoffError(int $year): ?string
    {
        return PayrollPeriod::cutoffError(sprintf('%04d-01-01', $year), sprintf('%04d-12-31', $year), fn (): ?string => $this->resolveYear($year)->error);
    }

    /** The versions in force on the period's first day, or why the period cannot be priced. */
    public function resolve(string $periodStart, string $periodEnd): StatutoryResolution
    {
        if (! self::ready()) {
            return $this->builtIn(Statutory::scheduleError($periodStart));
        }

        $versions = $this->versions();
        $tables = [];
        $error = null;
        foreach (StatutorySchedule::KINDS as $kind) {
            $rows = $versions[$kind] ?? [];
            $current = $this->inForce($rows, $periodStart);
            $label = self::label($kind);
            if ($current === null) {
                $error ??= $rows === []
                    ? "The {$label} table has no version. Add one in Contribution & Tax Tables."
                    : "No {$label} table covers ".self::day($periodStart).'. Add or extend a version in Contribution & Tax Tables.';
                $tables[$kind] = $this->builtInTable($kind);

                continue;
            }
            $through = $current->covered_through->format('Y-m-d');
            if ($through < $periodStart) {
                $error ??= "No {$label} table covers ".self::day($periodStart).'. Add or extend a version in Contribution & Tax Tables.';
            } elseif ($through < $periodEnd) {
                $error ??= "The {$label} table is known only through ".self::day($through).'. Extend it in Contribution & Tax Tables, or end this payroll by then.';
            }
            $next = $this->after($rows, $current);
            if ($next !== null && $next->effective_from->format('Y-m-d') <= $periodEnd) {
                $error ??= "The {$label} table changes on ".self::day($next->effective_from->format('Y-m-d')).'. End this payroll before that date, or start it on that date.';
            }
            $tables[$kind] = $this->versionEntry($current);
        }

        return new StatutoryResolution($this->schedule($tables), $tables, $error);
    }

    /** The withholding table in force on 31 December of the year; the other three as in force that day, for completeness. */
    public function resolveYear(int $year): StatutoryResolution
    {
        $day = sprintf('%04d-12-31', $year);
        if (! self::ready()) {
            return $this->builtIn(Statutory::scheduleError(sprintf('%04d-01-01', $year)));
        }

        $versions = $this->versions();
        $tables = [];
        $error = null;
        foreach (StatutorySchedule::KINDS as $kind) {
            $current = $this->inForce($versions[$kind] ?? [], $day);
            if ($current === null || $current->covered_through->format('Y-m-d') < $day) {
                if ($kind === StatutorySchedule::WITHHOLDING_TAX) {
                    $error = 'No Withholding Tax table covers '.self::day($day).'. Add or extend a version in Contribution & Tax Tables.';
                }
                $tables[$kind] = $this->builtInTable($kind);

                continue;
            }
            $tables[$kind] = $this->versionEntry($current);
        }

        return new StatutoryResolution($this->schedule($tables), $tables, $error);
    }

    /**
     * The last day every table is known good for — the latest version of
     * each, the earliest of their `covered_through` — or null while the
     * table is absent.
     */
    public function coveredThrough(): ?string
    {
        if (! self::ready()) {
            return Statutory::VERIFIED_THROUGH;
        }
        $through = null;
        foreach (StatutorySchedule::KINDS as $kind) {
            $latest = PayrollStatutoryTable::query()->where('kind', $kind)->orderByDesc('effective_from')->first();
            if ($latest === null) {
                return null;
            }
            $day = $latest->covered_through->format('Y-m-d');
            $through = $through === null || $day < $through ? $day : $through;
        }

        return $through;
    }

    /**
     * The tables whose coverage ends on the day `coveredThrough()` names —
     * what holds payroll back after it.
     *
     * @return list<string> kinds
     */
    public function coverageEndsWith(): array
    {
        $through = $this->coveredThrough();
        if (! self::ready() || $through === null) {
            return [];
        }
        $kinds = [];
        foreach (StatutorySchedule::KINDS as $kind) {
            $latest = PayrollStatutoryTable::query()->where('kind', $kind)->orderByDesc('effective_from')->first();
            if ($latest !== null && $latest->covered_through->format('Y-m-d') === $through) {
                $kinds[] = $kind;
            }
        }

        return $kinds;
    }

    /** 'd M Y', the spelling the date fields show. */
    public static function day(string $date): string
    {
        return CarbonImmutable::parse($date, 'UTC')->format('d M Y');
    }

    /** @return array<string, list<PayrollStatutoryTable>> kind => versions, oldest first */
    private function versions(): array
    {
        $versions = [];
        foreach (PayrollStatutoryTable::query()->orderBy('effective_from')->orderBy('id')->get() as $row) {
            $versions[$row->kind][] = $row;
        }

        return $versions;
    }

    /** @param list<PayrollStatutoryTable> $rows */
    private function inForce(array $rows, string $date): ?PayrollStatutoryTable
    {
        $current = null;
        foreach ($rows as $row) {
            if ($row->effective_from->format('Y-m-d') <= $date) {
                $current = $row;
            }
        }

        return $current;
    }

    /** @param list<PayrollStatutoryTable> $rows */
    private function after(array $rows, PayrollStatutoryTable $current): ?PayrollStatutoryTable
    {
        foreach ($rows as $row) {
            if ($row->effective_from->format('Y-m-d') > $current->effective_from->format('Y-m-d')) {
                return $row;
            }
        }

        return null;
    }

    /** @return array{version_id: string, effective_from: string, covered_through: string, figures: array<string, mixed>} */
    private function versionEntry(PayrollStatutoryTable $row): array
    {
        return [
            'version_id' => (string) $row->id,
            'effective_from' => $row->effective_from->format('Y-m-d'),
            'covered_through' => $row->covered_through->format('Y-m-d'),
            'figures' => $row->params,
        ];
    }

    /** @return array{version_id: null, effective_from: null, covered_through: null, figures: array<string, mixed>} */
    private function builtInTable(string $kind): array
    {
        return ['version_id' => null, 'effective_from' => null, 'covered_through' => null, 'figures' => StatutorySchedule::BUILT_IN[$kind]];
    }

    private function builtIn(?string $error): StatutoryResolution
    {
        $tables = [];
        foreach (StatutorySchedule::KINDS as $kind) {
            $tables[$kind] = $this->builtInTable($kind);
        }

        return new StatutoryResolution(Statutory::builtIn(), $tables, $error);
    }

    /**
     * @param  array<string, array{version_id: ?string, effective_from: ?string, covered_through: ?string, figures: array<string, mixed>}>  $tables
     */
    private function schedule(array $tables): StatutorySchedule
    {
        try {
            return StatutorySchedule::fromArrays(array_map(static fn (array $table): array => $table['figures'], $tables));
        } catch (InvalidArgumentException $e) {
            // Saved versions are validated on the way in; this is a corrupted row.
            throw new RuntimeException('A Contribution & Tax Table version holds figures payroll cannot read: '.$e->getMessage());
        }
    }
}
