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
 * version whose `effective_from` is on or before it — which stays in force
 * until a later version starts, with no end of its own. The period is
 * refused when no version is in force on that day or a later version takes
 * effect inside the period: a payslip carries one set of figures, so a
 * change splits payrolls. A version's `covered_through` is the day its
 * figures were last CONFIRMED good through; a period reaching past it is
 * still priced, with a reminder to check the figures (`warning`), never a
 * refusal. The 13th month run reads only the withholding table, in force on
 * 31 December of its year.
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

    /** The reminder a regular payroll shows when a table is not confirmed as far as the period reaches; null when every table is, or the period is refused anyway. */
    public function cutoffWarning(string $periodStart, string $periodEnd): ?string
    {
        if ($this->cutoffError($periodStart, $periodEnd) !== null) {
            return null;
        }

        return $this->resolve($periodStart, $periodEnd)->warning;
    }

    /** The 13th month run's check: the year's withholding table, in force on 31 December. */
    public function yearlyCutoffError(int $year): ?string
    {
        return PayrollPeriod::cutoffError(sprintf('%04d-01-01', $year), sprintf('%04d-12-31', $year), fn (): ?string => $this->resolveYear($year)->error);
    }

    /** The 13th month run's reminder: the withholding table not confirmed through 31 December; null when it is, or the year is refused anyway. */
    public function yearlyCutoffWarning(int $year): ?string
    {
        if ($this->yearlyCutoffError($year) !== null) {
            return null;
        }

        return $this->resolveYear($year)->warning;
    }

    /**
     * The versions in force on the period's first day, or why the period
     * cannot be priced. A payroll made from a pay schedule picks the
     * withholding table by its pay date and the contribution tables by the
     * first day of its contribution month (`YYYY-MM`) instead, when given;
     * the "changes inside the period" refusal stays on the period.
     */
    public function resolve(string $periodStart, string $periodEnd, ?string $payDate = null, ?string $contributionMonth = null): StatutoryResolution
    {
        if (! self::ready()) {
            return $this->builtIn(Statutory::scheduleError($periodStart));
        }

        $versions = $this->versions();
        $tables = [];
        $error = null;
        $warning = null;
        foreach (StatutorySchedule::KINDS as $kind) {
            $rows = $versions[$kind] ?? [];
            $pick = $kind === StatutorySchedule::WITHHOLDING_TAX
                ? ($payDate ?? $periodStart)
                : ($contributionMonth === null ? $periodStart : $contributionMonth.'-01');
            $current = $this->inForce($rows, $pick);
            $label = self::label($kind);
            if ($current === null) {
                $error ??= $rows === []
                    ? "The {$label} table has no version. Add one in Contribution & Tax Tables."
                    : "No {$label} table is in force on ".self::day($periodStart).'. Add a version that starts on or before it in Contribution & Tax Tables.';
                $tables[$kind] = $this->builtInTable($kind);

                continue;
            }
            $next = $this->after($rows, $current);
            if ($next !== null && $next->effective_from->format('Y-m-d') <= $periodEnd) {
                $error ??= "The {$label} table changes on ".self::day($next->effective_from->format('Y-m-d')).'. End this payroll before that date, or start it on that date.';
            }
            $warning ??= self::unconfirmedWarning($current, $periodEnd);
            $tables[$kind] = $this->versionEntry($current);
        }

        return new StatutoryResolution($this->schedule($tables), $tables, $error, $warning);
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
        $warning = null;
        foreach (StatutorySchedule::KINDS as $kind) {
            $current = $this->inForce($versions[$kind] ?? [], $day);
            if ($current === null) {
                if ($kind === StatutorySchedule::WITHHOLDING_TAX) {
                    $error = 'No Withholding Tax table is in force on '.self::day($day).'. Add a version that starts on or before it in Contribution & Tax Tables.';
                }
                $tables[$kind] = $this->builtInTable($kind);

                continue;
            }
            if ($kind === StatutorySchedule::WITHHOLDING_TAX) {
                $warning = self::unconfirmedWarning($current, $day);
            }
            $tables[$kind] = $this->versionEntry($current);
        }

        return new StatutoryResolution($this->schedule($tables), $tables, $error, $warning);
    }

    /**
     * The last day every table's figures are confirmed good through — the
     * latest version of each, the earliest of their `covered_through` — or
     * null while a table has no version at all.
     */
    public function confirmedThrough(): ?string
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
     * The tables whose confirmation ends on the day `confirmedThrough()`
     * names — the ones a payroll past it reminds the officer to check.
     *
     * @return list<string> kinds
     */
    public function confirmationEndsWith(): array
    {
        $through = $this->confirmedThrough();
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

    /** The reminder for a version whose figures are not confirmed through the day a period reaches, or null. */
    private static function unconfirmedWarning(PayrollStatutoryTable $current, string $through): ?string
    {
        $confirmed = $current->covered_through->format('Y-m-d');
        if ($confirmed >= $through) {
            return null;
        }

        return 'The '.self::label($current->kind).' table has not been confirmed past '.self::day($confirmed).'. Check the current figures, then confirm the table in Contribution & Tax Tables.';
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
