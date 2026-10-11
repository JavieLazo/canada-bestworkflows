<?php

declare(strict_types=1);

namespace App\Livewire\Records;

use App\Domain\Enums\ModuleCode;
use App\Domain\Payroll\Statutory;
use App\Domain\Payroll\StatutorySchedule;
use App\Livewire\Records\Support\Hris;
use App\Modules\Payroll\Application\Commands\AddStatutoryTableVersion;
use App\Modules\Payroll\Application\Commands\ExtendStatutoryCoverage;
use App\Modules\Payroll\Application\Queries\StatutoryTableRecords;
use App\Modules\Payroll\Application\StatutoryTables;
use App\Modules\Payroll\Contracts\Views\StatutoryTableView;
use App\Support\Access;
use App\Support\ManilaClock;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Database\QueryException;
use Illuminate\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Contribution & Tax Tables — the SSS, PhilHealth, Pag-IBIG and withholding
 * figures payroll computes from, each as dated versions, under Records →
 * Payroll Settings. One tab per table: its versions newest first, the
 * figures of the version in view, what those figures deduct at sample
 * salaries, and — for a RECORDS all-data holder — a dialog to add a version
 * from a date, with a live preview, and one to extend the latest version's
 * coverage. Figures are typed as percentages and pesos; the commands get
 * the engine's shape (rates as fractions), converted once at this boundary,
 * and a percentage left as shown keeps the stored fraction exactly.
 */
#[Layout('layouts.app')]
#[Title('Contribution & Tax Tables')]
class StatutoryTablesPage extends Component
{
    /** The tabs, in order, with their names on screen. */
    public const array TABS = [
        StatutorySchedule::SSS => 'SSS',
        StatutorySchedule::PHILHEALTH => 'PhilHealth',
        StatutorySchedule::PAGIBIG => 'Pag-IBIG',
        StatutorySchedule::WITHHOLDING_TAX => 'Withholding Tax',
    ];

    /**
     * Each contribution table's figures as the page names them: key =>
     * [label, kind of figure]. A `percent` is shown ×100; `money` as pesos.
     *
     * @var array<string, list<array{0: string, 1: string, 2: string}>>
     */
    private const array FIGURES = [
        StatutorySchedule::SSS => [
            ['ee_rate', 'Employee share (%)', 'percent'],
            ['er_rate', 'Employer share (%)', 'percent'],
            ['msc_min', 'Lowest salary credit', 'money'],
            ['msc_max', 'Highest salary credit', 'money'],
            ['step', 'Salary credit step', 'money'],
            ['offset', 'Rounding midpoint', 'money'],
            ['ec_threshold', 'Work-injury fee changes above this credit', 'money'],
            ['ec_low', 'Work-injury fee up to that credit', 'money'],
            ['ec_high', 'Work-injury fee above it', 'money'],
        ],
        StatutorySchedule::PHILHEALTH => [
            ['ee_rate', 'Employee share (%)', 'percent'],
            ['er_rate', 'Employer share (%)', 'percent'],
            ['floor', 'Lowest salary counted', 'money'],
            ['cap', 'Highest salary counted', 'money'],
        ],
        StatutorySchedule::PAGIBIG => [
            ['ee_rate', 'Employee share (%)', 'percent'],
            ['ee_low_rate', 'Employee share up to the low line (%)', 'percent'],
            ['low_line', 'Low line', 'money'],
            ['er_rate', 'Employer share (%)', 'percent'],
            ['cap', 'Highest salary counted', 'money'],
        ],
    ];

    /** Sample monthly salaries for the contribution tables, and per-cut-off / yearly taxable pay for withholding. */
    private const array SAMPLE_SALARIES = [8000, 14800, 20000, 30000, 50000];

    private const array SAMPLE_SEMI_MONTHLY = [10000, 15000, 25000, 40000, 90000];

    private const array SAMPLE_ANNUAL = [250000, 350000, 600000, 1200000];

    /** The table in view. Whitelisted in mount() and updatedTab(). */
    #[Url(as: 'table', except: StatutorySchedule::SSS)]
    public string $tab = StatutorySchedule::SSS;

    /** The version whose figures the card shows, or null for the latest. Id-bearing → #[Locked]. */
    #[Locked]
    public ?string $viewingId = null;

    /** Add-version dialog visibility. Plain UI state. */
    public bool $dialogOpen = false;

    public string $formEffectiveFrom = '';

    public string $formCoveredThrough = '';

    public string $formReference = '';

    /**
     * The contribution figures being typed, key => display text (a
     * percentage or a peso amount).
     *
     * @var array<string, string>
     */
    public array $formFigures = [];

    /**
     * The withholding brackets being typed: per list, rows of [over, fixed
     * tax, rate %], as text. Client-editable, so read defensively.
     *
     * @var array<string, list<array<int, string>>>
     */
    public array $formBrackets = [];

    /** The add dialog's inline error. */
    public ?string $formError = null;

    /** Extend-coverage dialog visibility. Plain UI state. */
    public bool $extendOpen = false;

    /** The version being extended. Id-bearing → #[Locked]. */
    #[Locked]
    public ?string $extendingId = null;

    public string $formExtendThrough = '';

    public function mount(): void
    {
        $this->tab = isset(self::TABS[$this->tab]) ? $this->tab : StatutorySchedule::SSS;
    }

    public function updatedTab(): void
    {
        $this->tab = isset(self::TABS[$this->tab]) ? $this->tab : StatutorySchedule::SSS;
        $this->viewingId = null;
        $this->dialogOpen = false;
        $this->extendOpen = false;
    }

    /** Back to the version in force in the Figures card. */
    public function showCurrent(): void
    {
        $this->viewingId = null;
    }

    public function viewFigures(string $id): void
    {
        $row = app(StatutoryTableRecords::class)->find(app(Access::class), $id);
        if ($row === null || $row->kind !== $this->tab) {
            Flux::toast(variant: 'danger', text: 'That table version no longer exists.');

            return;
        }
        $this->viewingId = $row->id;
    }

    /** The add dialog, pre-filled from the latest version's figures and dated the day after its coverage ends. */
    public function openAdd(): void
    {
        $latest = app(StatutoryTableRecords::class)->latest(app(Access::class), $this->tab);
        $figures = $latest->params ?? StatutorySchedule::BUILT_IN[$this->tab];
        $today = ManilaClock::todayKey();
        // A change made while the coverage still holds starts today (after
        // an upcoming version, the day after it starts); once the coverage
        // has lapsed, the day after it ended.
        if ($latest === null) {
            $from = $today;
        } elseif ($latest->covered_through->format('Y-m-d') < $today) {
            $from = $latest->covered_through->addDay()->format('Y-m-d');
        } else {
            $from = max($today, $latest->effective_from->addDay()->format('Y-m-d'));
        }
        $this->formEffectiveFrom = $from;
        $this->formCoveredThrough = CarbonImmutable::parse($from, 'UTC')->endOfYear()->format('Y-m-d');
        $this->formReference = '';
        $this->formError = null;
        if ($this->tab === StatutorySchedule::WITHHOLDING_TAX) {
            $this->formFigures = [];
            $this->formBrackets = [];
            foreach (StatutorySchedule::WITHHOLDING_KEYS as $list) {
                foreach ($figures[$list] as [$over, $base, $rate]) {
                    $this->formBrackets[$list][] = [self::moneyText($over), self::moneyText($base), self::percentText($rate)];
                }
            }
        } else {
            $this->formBrackets = [];
            $this->formFigures = [];
            foreach (self::FIGURES[$this->tab] as [$key, , $shape]) {
                $this->formFigures[$key] = $shape === 'percent' ? self::percentText($figures[$key]) : self::moneyText($figures[$key]);
            }
        }
        $this->dialogOpen = true;
    }

    /** One more withholding bracket at the end of a list, blank, for the officer to fill. */
    public function addBracket(string $list): void
    {
        if (! in_array($list, StatutorySchedule::WITHHOLDING_KEYS, true)) {
            return;
        }
        $this->formBrackets[$list][] = ['', '', ''];
    }

    /** Drops one withholding bracket; a list keeps at least one. */
    public function removeBracket(string $list, int $index): void
    {
        if (! in_array($list, StatutorySchedule::WITHHOLDING_KEYS, true) || count($this->formBrackets[$list] ?? []) <= 1) {
            return;
        }
        $kept = $this->formBrackets[$list];
        unset($kept[$index]);
        $this->formBrackets[$list] = array_values($kept);
    }

    public function save(): void
    {
        $this->formError = null;
        $access = app(Access::class);
        $latest = app(StatutoryTableRecords::class)->latest($access, $this->tab);
        $figures = $this->engineFigures($latest->params ?? StatutorySchedule::BUILT_IN[$this->tab]);
        if (is_string($figures)) {
            $this->formError = $figures;

            return;
        }
        try {
            $message = app(AddStatutoryTableVersion::class)->handle($access, $this->tab, $this->formEffectiveFrom, $this->formCoveredThrough, $this->formReference, $figures);
        } catch (QueryException $e) {
            report($e);
            Flux::toast(variant: 'danger', text: 'The table version could not be saved. Nothing was changed. Please try again.');

            return;
        } catch (\RuntimeException $e) {
            $this->formError = $e->getMessage();

            return;
        }
        Flux::toast(variant: 'success', text: $message);
        $this->dialogOpen = false;
        $this->viewingId = null;
    }

    public function openExtend(string $id): void
    {
        $row = app(StatutoryTableRecords::class)->find(app(Access::class), $id);
        if ($row === null || $row->kind !== $this->tab) {
            Flux::toast(variant: 'danger', text: 'That table version no longer exists.');

            return;
        }
        $this->extendingId = $row->id;
        $this->formExtendThrough = $row->covered_through->addYear()->endOfYear()->format('Y-m-d');
        $this->formError = null;
        $this->extendOpen = true;
    }

    public function saveExtend(): void
    {
        $id = $this->extendingId;
        if ($id === null) {
            return;
        }
        $this->formError = null;
        $row = app(StatutoryTableRecords::class)->find(app(Access::class), $id);
        if ($row !== null && $row->covered_through->format('Y-m-d') === $this->formExtendThrough) {
            $this->formError = 'This version is already covered through '.StatutoryTables::day($this->formExtendThrough).'.';

            return;
        }
        try {
            $message = app(ExtendStatutoryCoverage::class)->handle(app(Access::class), $id, $this->formExtendThrough);
        } catch (QueryException $e) {
            report($e);
            Flux::toast(variant: 'danger', text: 'The coverage could not be saved. Nothing was changed. Please try again.');

            return;
        } catch (\RuntimeException $e) {
            $this->formError = $e->getMessage();

            return;
        }
        Flux::toast(variant: 'success', text: $message);
        $this->extendOpen = false;
        $this->extendingId = null;
    }

    public function render(): View
    {
        $access = app(Access::class);
        $records = app(StatutoryTableRecords::class);
        $versions = $records->versions($access, $this->tab);
        $latest = $versions->first();
        $today = ManilaClock::todayKey();
        // The version in force: the newest that has started — an upcoming
        // version is listed above it but prices nothing yet.
        $inForce = $versions->first(static fn (StatutoryTableView $row): bool => $row->effective_from->format('Y-m-d') <= $today);
        $shown = $this->viewingId !== null
            ? $versions->first(fn (StatutoryTableView $row): bool => $row->id === $this->viewingId) ?? $inForce ?? $latest
            : $inForce ?? $latest;
        $figures = $shown->params ?? StatutorySchedule::BUILT_IN[$this->tab];

        $schedule = $this->schedule($figures);
        $preview = null;
        $previewFigures = null;
        if ($this->dialogOpen) {
            $previewFigures = $this->engineFigures($latest->params ?? StatutorySchedule::BUILT_IN[$this->tab]);
            $preview = is_string($previewFigures) ? ['error' => $previewFigures] : $this->preview($previewFigures);
        }
        $tables = app(StatutoryTables::class);
        $coveredThrough = $tables->coveredThrough();

        return view('livewire.records.statutory-tables-page', [
            'tabs' => self::TABS,
            'label' => self::TABS[$this->tab],
            'versions' => $versions,
            'latest' => $latest,
            'inForce' => $inForce,
            'shown' => $shown,
            'figureRows' => $this->figureRows($figures),
            'bracketRows' => $this->tab === StatutorySchedule::WITHHOLDING_TAX ? $figures : null,
            'samples' => $schedule === null ? [] : $this->samples($schedule),
            'coveredThrough' => $coveredThrough,
            'coverageLapsed' => $coveredThrough !== null && $coveredThrough < $today,
            'coverageEndsWith' => array_map(static fn (string $kind): string => self::TABS[$kind], $tables->coverageEndsWith()),
            'ready' => StatutoryTables::ready(),
            'canManage' => $access->mayReadAllData(ModuleCode::RECORDS),
            'figureSpecs' => self::FIGURES[$this->tab] ?? [],
            'preview' => $preview,
            'extending' => $this->extendingId !== null ? $versions->first(fn (StatutoryTableView $row): bool => $row->id === $this->extendingId) : null,
            'today' => $today,
        ]);
    }

    /**
     * The figures as the card lists them: label => formatted value.
     *
     * @param  array<string, mixed>  $figures
     * @return list<array{0: string, 1: string}>
     */
    private function figureRows(array $figures): array
    {
        $rows = [];
        foreach (self::FIGURES[$this->tab] ?? [] as [$key, $label, $shape]) {
            $value = $figures[$key] ?? null;
            $rows[] = [$label, ! is_numeric($value) ? '—' : ($shape === 'percent' ? self::percentText($value).'%' : Hris::peso((float) $value))];
        }

        return $rows;
    }

    /** @param array<string, mixed> $figures */
    private function schedule(array $figures): ?StatutorySchedule
    {
        try {
            return Statutory::builtIn()->with($this->tab, $figures);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * What the figures deduct at sample amounts — the engine's own
     * functions, so the page can never disagree with a payslip.
     *
     * @return list<array<string, string>>
     */
    private function samples(StatutorySchedule $schedule): array
    {
        $rows = [];
        if ($this->tab === StatutorySchedule::WITHHOLDING_TAX) {
            foreach (self::SAMPLE_SEMI_MONTHLY as $taxable) {
                $rows[] = ['list' => 'semi_monthly', 'amount' => Hris::peso($taxable), 'tax' => Hris::peso(Statutory::semiMonthlyWithholdingTax($taxable, $schedule))];
            }
            foreach (self::SAMPLE_ANNUAL as $taxable) {
                $rows[] = ['list' => 'annual', 'amount' => Hris::peso($taxable), 'tax' => Hris::peso(Statutory::annualIncomeTax($taxable, $schedule))];
            }

            return $rows;
        }
        foreach (self::SAMPLE_SALARIES as $salary) {
            $shares = match ($this->tab) {
                StatutorySchedule::SSS => Statutory::sss($salary, $schedule),
                StatutorySchedule::PHILHEALTH => Statutory::philhealth($salary, $schedule),
                default => Statutory::pagibig($salary, $schedule),
            };
            $rows[] = [
                'amount' => Hris::peso($salary),
                'employee' => Hris::peso($shares['employee']),
                'employer' => Hris::peso($shares['employer']),
                'half' => Hris::peso(Statutory::semiMonthlyShare($shares['employee'])),
            ];
        }

        return $rows;
    }

    /**
     * What is wrong with a set of figures, in the page's own words (the
     * engine names its keys), or null.
     *
     * @param  array<string, mixed>  $figures
     */
    private function figuresError(array $figures): ?string
    {
        try {
            Statutory::builtIn()->with($this->tab, $figures);
        } catch (InvalidArgumentException $e) {
            $message = $e->getMessage();
            foreach (self::FIGURES[$this->tab] ?? [] as [$key, $label]) {
                $message = str_replace($key, $label, $message);
            }

            return $message;
        }

        return null;
    }

    /**
     * The add dialog's live preview at one sample amount.
     *
     * @param  array<string, mixed>  $figures
     * @return array<string, string>
     */
    private function preview(array $figures): array
    {
        $error = $this->figuresError($figures);
        if ($error !== null) {
            return ['error' => $error];
        }
        $schedule = $this->schedule($figures);
        if ($schedule === null) {
            return ['error' => 'Check the figures.'];
        }
        if ($this->tab === StatutorySchedule::WITHHOLDING_TAX) {
            return ['amount' => Hris::peso(20000), 'tax' => Hris::peso(Statutory::semiMonthlyWithholdingTax(20000, $schedule))];
        }
        $shares = match ($this->tab) {
            StatutorySchedule::SSS => Statutory::sss(30000, $schedule),
            StatutorySchedule::PHILHEALTH => Statutory::philhealth(30000, $schedule),
            default => Statutory::pagibig(30000, $schedule),
        };

        return ['amount' => Hris::peso(30000), 'employee' => Hris::peso($shares['employee']), 'employer' => Hris::peso($shares['employer'])];
    }

    /**
     * The typed figures in the engine's shape, or the first thing wrong
     * with them. A percentage shown unchanged keeps the stored fraction, so
     * the display conversion never edits a figure by itself.
     *
     * @param  array<string, mixed>  $stored  the figures the dialog was pre-filled from
     * @return array<string, mixed>|string
     */
    private function engineFigures(array $stored): array|string
    {
        if ($this->tab === StatutorySchedule::WITHHOLDING_TAX) {
            $figures = [];
            foreach (StatutorySchedule::WITHHOLDING_KEYS as $list) {
                $rows = $this->formBrackets[$list] ?? [];
                if ($rows === []) {
                    return 'Enter at least one tax bracket.';
                }
                foreach ($rows as $i => $row) {
                    $over = self::number($row[0] ?? null);
                    $base = self::number($row[1] ?? null);
                    $rate = self::number($row[2] ?? null);
                    if ($over === null || $base === null || $rate === null) {
                        return 'Each tax bracket needs an amount, a fixed tax and a rate.';
                    }
                    $storedRate = $stored[$list][$i][2] ?? null;
                    $figures[$list][] = [$over, $base, self::rate($rate, is_numeric($storedRate) ? (float) $storedRate : null)];
                }
            }

            return $figures;
        }

        $figures = [];
        foreach (self::FIGURES[$this->tab] ?? [] as [$key, $label, $shape]) {
            $value = self::number($this->formFigures[$key] ?? null);
            if ($value === null) {
                return "Enter a number for {$label}.";
            }
            $storedValue = $stored[$key] ?? null;
            $storedFloat = is_numeric($storedValue) ? (float) $storedValue : null;
            $figures[$key] = $shape === 'percent' ? self::rate($value, $storedFloat) : self::amount($value, $storedFloat);
        }

        return $figures;
    }

    /** A typed peso figure, keeping a stored figure whose display matches so the display rounding never edits it. */
    private static function amount(float $typed, ?float $stored): float
    {
        if ($stored !== null && (string) $typed === self::moneyText($stored)) {
            return $stored;
        }

        return $typed;
    }

    /** A typed percentage as the fraction the engine reads, keeping a stored fraction whose display matches. */
    private static function rate(float $percent, ?float $stored): float
    {
        if ($stored !== null && (string) $percent === self::percentText($stored)) {
            return $stored;
        }

        return $percent / 100;
    }

    private static function number(mixed $text): ?float
    {
        $text = is_string($text) ? str_replace(',', '', trim($text)) : $text;
        if (! is_numeric($text) || ! is_finite((float) $text)) {
            return null;
        }

        return (float) $text;
    }

    /** A fraction as the percentage the page shows: 0.025 → "2.5", 0.1 → "10". */
    public static function percentText(mixed $rate): string
    {
        return Hris::percentText($rate);
    }

    /** A peso figure as the input shows it: "5000" or "937.5". */
    public static function moneyText(mixed $amount): string
    {
        return Hris::moneyText($amount);
    }
}
