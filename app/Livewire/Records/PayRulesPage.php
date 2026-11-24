<?php

declare(strict_types=1);

namespace App\Livewire\Records;

use App\Domain\Enums\ModuleCode;
use App\Domain\Payroll\PayRules;
use App\Livewire\Records\Support\Hris;
use App\Modules\Organization\Contracts\HolidayCalendar;
use App\Modules\Payroll\Application\Commands\ReplacePayRules;
use App\Modules\Payroll\Application\Commands\SaveEmployeePayRules;
use App\Modules\Payroll\Application\Commands\SavePayRulesVersion;
use App\Modules\Payroll\Application\PayrollSettingsAccess;
use App\Modules\Payroll\Application\PayRulesStore;
use App\Modules\Payroll\Application\Queries\PayRuleRecords;
use App\Modules\Payroll\Contracts\Views\PayRuleView;
use App\Modules\Workforce\Contracts\EmployeePayContexts;
use App\Modules\Workforce\Contracts\EmployeePayData;
use App\Platform\Records\ReferenceCatalogs;
use App\Support\Access;
use App\Support\EmployeeName;
use App\Support\LocalClock;
use App\Support\Money;
use Flux\Flux;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Pay Basis & Rates — how a day, an overtime hour and a night hour are
 * priced, under Records → Payroll Settings. One form for one scope at a
 * time (the company rule, an Employment status's override, or — opened
 * from Employee Pay Settings — one employee's own rates): the pay basis,
 * the daily-rate method, the night window, the daily caps and the
 * rates-by-day-type table, filled from the version IN FORCE for that scope
 * (an upcoming version is listed but prices nothing yet), else from what
 * the scope falls back to — an employee's status override, else the
 * company rule — or a preset.
 *
 * The page opens for PAYROLL. The company's and the statuses' versions are
 * saved by whoever manages the Payroll Settings (SavePayRulesVersion); one
 * employee's are pay data, read and saved by all-data PAYROLL only
 * (SaveEmployeePayRules), which can also date their return to the status
 * or company rules.
 * Every save is a NEW dated version through the audited command; a saved
 * version never changes. Percentages are typed as shown; the three base
 * rates (an ordinary overtime hour, an ordinary night hour, a worked rest
 * day) are stored as fractions, converted once here, and a percentage left
 * as shown keeps the stored fraction exactly. A blank table cell is priced
 * "as derived" — the reference's own derivation from those rates and the
 * holiday's percent — which is what keeps the golden path intact.
 */
#[Layout('layouts.app')]
#[Title('Pay Basis & Rates')]
class PayRulesPage extends Component
{
    /** The day types in table order, with their names on screen. */
    public const array DAY_TYPES = [
        'ORDINARY' => 'Ordinary workday',
        'REST' => 'Rest day',
        'SPECIAL' => 'Special non-working day',
        'SPECIAL_REST' => 'Special non-working day on a rest day',
        'REGULAR' => 'Regular holiday',
        'REGULAR_REST' => 'Regular holiday on a rest day',
        'DOUBLE' => 'Double holiday',
        'DOUBLE_REST' => 'Double holiday on a rest day',
    ];

    /** The cells, with their names in a message. */
    public const array CELLS = ['worked' => 'worked day', 'overtime' => 'overtime hour', 'night' => 'night hour', 'unworked' => 'not worked'];

    /** The three cells that ARE the base rates: never blank, never derived. */
    public const array BASE_CELLS = ['ORDINARY.overtime', 'ORDINARY.night', 'REST.worked'];

    /** The sample salary the daily-rate card prices. */
    private const float SAMPLE_MONTHLY = 30000.0;

    /** The working days the sample month has under the month's-working-days method. */
    private const int SAMPLE_MONTH_DAYS = 22;

    /**
     * Who the figures on screen apply to: COMPANY, an Employment list
     * entry's id, or an employee's id (for an all-data PAYROLL holder).
     * Client-chosen, like a filter: it only picks which versions are shown
     * and which scope a save names, and save() re-resolves it against the
     * Employment list and the employees before writing. Not Locked because
     * the select binds it.
     */
    #[Url(as: 'for', except: PayRulesStore::COMPANY)]
    public string $scope = PayRulesStore::COMPANY;

    /** The scope the form was last filled for — what an unsaved edit belongs to. Id-bearing → #[Locked]. */
    #[Locked]
    public string $formScope = PayRulesStore::COMPANY;

    /** A scope change asked for while the form holds unsaved edits, awaiting the confirm. Id-bearing → #[Locked]. */
    #[Locked]
    public ?string $pendingScope = null;

    /** The discard-edits confirm's visibility. Plain UI state. */
    public bool $scopeConfirmOpen = false;

    public string $formBasis = PayRules::BASIS_DAILY;

    public bool $formHolidayNeedsAttendance = false;

    public string $formMethod = PayRules::METHOD_MONTH;

    /** The yearly factor or the fixed divisor, as typed. */
    public string $formDivisor = '';

    /** The night window's bounds as `HH:mm` (what the time field speaks). */
    public string $formNightStart = '22:00';

    public string $formNightEnd = '06:00';

    public bool $formNightOnOvertime = false;

    public string $formOvertimeMax = '';

    public string $formNightMax = '';

    public string $formRestMax = '';

    /** The holiday overtime factor as a percentage of the holiday's rate. */
    public string $formHolidayOvertime = '';

    /** Hours for the hourly rate; blank = the shift's paid hours. */
    public string $formHourlyHours = '';

    /** Decimals each rate step is rounded to; blank = no rounding. */
    public string $formRounding = '';

    /** Seaport overtime hour as a percent of the hourly rate; blank = the day's overtime. */
    public string $formSeaportRate = '';

    /** The net pay a payslip should not fall below, as typed; blank = no floor. */
    public string $formMinimumTakeHome = '';

    /**
     * The rates-by-day-type cells as typed, type => cell => text. A blank
     * is "as derived". Client-editable, so read defensively.
     *
     * @var array<string, array<string, string>>
     */
    public array $formMatrix = [];

    /** The save's inline error — a refusal of the last save, cleared by the next edit. */
    public ?string $formError = null;

    /**
     * The employees a scope was looked up as, this request only (never sent
     * to the browser).
     *
     * @var array<string, EmployeePayData|null>
     */
    private array $employees = [];

    public function mount(): void
    {
        $access = app(Access::class);
        if ($this->resolvedScope($access) === null) {
            $this->scope = PayRulesStore::COMPANY;
        }
        $this->loadForm($access);
    }

    /** Any edit clears the last save's refusal; the live message takes over. */
    public function updated(string $name): void
    {
        if (str_starts_with($name, 'form')) {
            $this->formError = null;
        }
    }

    /** A new method starts with an empty divisor: a day count is not a yearly factor. */
    public function updatedFormMethod(): void
    {
        $this->formDivisor = '';
    }

    /**
     * Applies to changed. With unsaved edits the change waits for the
     * confirm (the select is put back meanwhile); otherwise the form is
     * reloaded for the new scope.
     */
    public function updatedScope(): void
    {
        $access = app(Access::class);
        if ($this->resolvedScope($access) === null) {
            $this->scope = $this->formScope;

            return;
        }
        if ($this->scope === $this->formScope) {
            return;
        }
        if ($this->isDirty($access)) {
            $this->pendingScope = $this->scope;
            $this->scope = $this->formScope;
            $this->scopeConfirmOpen = true;

            return;
        }
        $this->formError = null;
        $this->loadForm($access);
    }

    /** Discard the unsaved edits and switch to the scope that was asked for. */
    public function discardAndSwitch(): void
    {
        $this->scopeConfirmOpen = false;
        if ($this->pendingScope === null) {
            return;
        }
        $this->scope = $this->pendingScope;
        $this->pendingScope = null;
        $this->formError = null;
        $this->loadForm(app(Access::class));
    }

    /** Keep editing: the scope stays as it was. */
    public function keepEditing(): void
    {
        $this->scopeConfirmOpen = false;
        $this->pendingScope = null;
    }

    /** Fill the form from a preset; nothing is saved until Save. */
    public function applyPreset(string $name): void
    {
        $access = app(Access::class);
        $base = $this->latestFigures($access);
        try {
            $figures = PayRules::preset($name, $base === null ? null : PayRules::fromArray($base));
        } catch (InvalidArgumentException) {
            Flux::toast(variant: 'danger', text: 'That preset does not exist.');

            return;
        }
        $this->fillFrom($figures);
        $this->formError = null;
        Flux::toast(variant: 'success', text: ucfirst(strtolower($name)).' preset filled in — nothing saved yet.');
    }

    public function save(): void
    {
        $this->formError = null;
        $access = app(Access::class);
        $scope = $this->resolvedScope($access);
        $employee = $scope === null ? null : $this->scopeEmployee($access, $scope);
        if (! $this->canManage($access, $employee)) {
            Flux::toast(variant: 'danger', text: $employee !== null ? 'Your role cannot manage employee pay settings.' : 'Your role cannot manage organization-wide data.');

            return;
        }
        if ($scope === null) {
            $this->formError = 'Choose who the rules apply to.';

            return;
        }
        $figures = $this->engineFigures($this->latestFigures($access) ?? PayRules::fromFormulas()->toArray());
        if (is_string($figures)) {
            $this->formError = $figures;

            return;
        }
        $this->write($access, $scope, $employee, $figures);
    }

    /**
     * Remove an employee's own rates: they follow their status's rates,
     * else the company rule, again — at once.
     */
    public function returnToGeneral(): void
    {
        $this->formError = null;
        $access = app(Access::class);
        $scope = $this->resolvedScope($access);
        $employee = $scope === null ? null : $this->scopeEmployee($access, $scope);
        if ($scope === null || $employee === null || ! $this->canManage($access, $employee)) {
            return;
        }
        $this->write($access, $scope, $employee, null);
    }

    public function render(): View
    {
        $access = app(Access::class);
        $records = app(PayRuleRecords::class);
        $versions = $records->versions($access, $this->scope);
        $today = LocalClock::todayKey();
        // The rules in use are the only ones the page shows.
        $inForce = self::inForce($versions, $today);
        $loaded = $inForce;
        // What prices the scope when its own version does not (or, for an
        // employee, when their own in force is a "follows" one).
        $inherited = $this->inheritedInForce($access, $this->scope, $today);
        $stored = $loaded->params ?? $inherited->params ?? PayRules::fromFormulas()->toArray();
        $employee = $this->scopeEmployee($access, $this->scope);

        $figures = $this->engineFigures($stored);
        $figuresError = is_string($figures) ? $figures : self::humanize(PayRules::error($figures));
        $rules = null;
        if (! is_string($figures) && $figuresError === null) {
            $rules = PayRules::fromArray($figures);
        }
        // The placeholders and the warnings stay on screen while a cell is
        // being corrected: they read the base rates, which stand on their own.
        $placeholderRules = $rules ?? $this->baseRules($figures);

        return view('livewire.records.pay-rules-page', [
            'scopes' => $this->scopeOptions($access),
            'inForce' => $inForce,
            'inheritedName' => $this->inheritedName($access, $this->scope, $today),
            'employee' => $employee,
            'employeeName' => $employee === null ? '' : EmployeeName::display($employee),
            'canManage' => $this->canManage($access, $employee),
            'ready' => PayRulesStore::ready(),
            'dayTypes' => self::DAY_TYPES,
            'derived' => $this->derivedText($placeholderRules),
            'sample' => $this->sample(),
            'shortfalls' => $this->shortfallText($placeholderRules),
            'figuresError' => $figuresError,
            'invalidCell' => self::invalidCell($figuresError),
            'dirty' => is_string($figures) || json_encode($figures) !== json_encode(self::canonical($stored)),
            'pendingScopeName' => $this->pendingScope === null ? '' : (array_column($this->scopeOptions($access), 'name', 'id')[$this->pendingScope] ?? ''),
        ]);
    }

    /**
     * The version in force for a scope on a day: the newest that has
     * started. An upcoming version is listed above it but prices nothing.
     *
     * @param  Collection<int, PayRuleView>  $versions  newest first
     */
    private static function inForce(Collection $versions, string $today): ?PayRuleView
    {
        return $versions->first(static fn (PayRuleView $row): bool => $row->status !== PayRulesStore::DRAFT && $row->effective_from->format('Y-m-d') <= $today);
    }

    /**
     * Replace the scope's rules through the audited command — they apply at
     * once — and reload the form from what is now in use.
     *
     * @param  array<string, mixed>|null  $figures  null: an employee's return to the general rules
     */
    private function write(Access $access, string $scope, ?EmployeePayData $employee, ?array $figures): void
    {
        try {
            $message = app(ReplacePayRules::class)->handle($access, $scope, $employee?->id, $figures);
        } catch (QueryException $e) {
            report($e);
            Flux::toast(variant: 'danger', text: 'The rules could not be saved. Nothing was changed. Please try again.');

            return;
        } catch (\RuntimeException $e) {
            $this->formError = self::humanize($e->getMessage());

            return;
        }
        Flux::toast(variant: 'success', text: $message);
        $this->loadForm($access);
    }

    /** Fill the form from the scope's version in force, else what it falls back to, else the reference's rules. */
    private function loadForm(Access $access): void
    {
        $records = app(PayRuleRecords::class);
        $today = LocalClock::todayKey();
        $versions = $records->versions($access, $this->scope);
        $inForce = self::inForce($versions, $today);
        $this->fillFrom($inForce->params ?? $this->inheritedInForce($access, $this->scope, $today)->params ?? PayRules::fromFormulas()->toArray());
        $this->formScope = $this->scope;
    }

    /**
     * The form from a version's figures, in display terms.
     *
     * @param  array<string, mixed>  $figures
     */
    private function fillFrom(array $figures): void
    {
        $this->formBasis = (string) ($figures['basis'] ?? PayRules::BASIS_DAILY);
        $this->formHolidayNeedsAttendance = (bool) ($figures['holiday_needs_attendance'] ?? false);
        $this->formMethod = (string) ($figures['daily_rate']['method'] ?? PayRules::METHOD_MONTH);
        $divisor = $figures['daily_rate']['value'] ?? 0;
        $this->formDivisor = $this->formMethod === PayRules::METHOD_MONTH ? '' : Hris::moneyText($divisor);
        $this->formNightStart = self::clockText((int) ($figures['night']['start'] ?? 1320));
        $this->formNightEnd = self::clockText((int) ($figures['night']['end'] ?? 1800));
        $this->formNightOnOvertime = (bool) ($figures['night']['on_overtime'] ?? false);
        $this->formOvertimeMax = Hris::moneyText($figures['overtime']['max_hours'] ?? 8);
        $this->formNightMax = Hris::moneyText($figures['night']['max_hours'] ?? 8);
        $this->formRestMax = Hris::moneyText($figures['rest_day']['max_hours'] ?? 8);
        $this->formHolidayOvertime = Hris::percentText($figures['holiday_overtime_factor'] ?? 1.3);
        $hours = $figures['hourly_rate']['hours'] ?? null;
        $this->formHourlyHours = is_numeric($hours) ? Hris::moneyText($hours) : '';
        $decimals = $figures['rounding']['decimals'] ?? null;
        $this->formRounding = is_int($decimals) ? (string) $decimals : '';
        $seaport = $figures['overtime']['seaport_rate'] ?? null;
        $this->formSeaportRate = is_numeric($seaport) ? Hris::percentText($seaport) : '';
        $minimum = $figures['take_home']['minimum'] ?? null;
        $this->formMinimumTakeHome = is_numeric($minimum) ? Hris::moneyText($minimum) : '';
        $matrix = [];
        foreach (PayRules::DAY_TYPES as $type) {
            $row = is_array($figures['matrix'][$type] ?? null) ? $figures['matrix'][$type] : [];
            foreach (PayRules::CELLS as $cell) {
                $value = $row[$cell] ?? null;
                $matrix[$type][$cell] = is_numeric($value) ? Hris::moneyText($value) : '';
            }
        }
        // The three base rates live in their own cells and are never blank.
        $matrix['ORDINARY']['worked'] = '100';
        $matrix['ORDINARY']['overtime'] = Hris::percentText($figures['overtime']['rate'] ?? 1.25);
        $matrix['ORDINARY']['night'] = Hris::moneyText(100 + (float) Hris::percentText($figures['night']['rate'] ?? 0.1));
        $matrix['REST']['worked'] = Hris::percentText($figures['rest_day']['rate'] ?? 1.3);
        $this->formMatrix = $matrix;
    }

    /**
     * The typed form in the engine's shape, or the first thing wrong with
     * it. A percentage shown unchanged keeps the stored fraction, so the
     * display conversion never edits a figure by itself.
     *
     * @param  array<string, mixed>  $stored  the figures the form was filled from
     * @return array<string, mixed>|string
     */
    private function engineFigures(array $stored): array|string
    {
        if (! in_array($this->formBasis, [PayRules::BASIS_DAILY, PayRules::BASIS_MONTHLY], true)) {
            return 'Choose the pay basis.';
        }
        if (! in_array($this->formMethod, [PayRules::METHOD_MONTH, PayRules::METHOD_FACTOR, PayRules::METHOD_DIVISOR], true)) {
            return 'Choose how the daily rate is derived.';
        }
        $divisor = 0.0;
        if ($this->formMethod !== PayRules::METHOD_MONTH) {
            $divisor = self::number($this->formDivisor);
            if ($divisor === null) {
                return $this->formMethod === PayRules::METHOD_FACTOR ? 'Daily rate: enter the days a year.' : 'Daily rate: enter the divisor.';
            }
        }
        $start = self::minutesOf($this->formNightStart);
        $end = self::minutesOf($this->formNightEnd);
        if ($start === null || $end === null) {
            return 'Night work: enter when it starts and ends.';
        }
        if ($end === $start) {
            return 'Night work: the end must differ from the start.';
        }
        if ($end < $start) {
            $end += 1440;
        }
        $caps = [];
        foreach (['formOvertimeMax' => 'Overtime hours a day at most', 'formNightMax' => 'Night hours a day at most', 'formRestMax' => 'Rest-day hours a day at most'] as $field => $label) {
            $caps[$field] = self::number($this->{$field});
            if ($caps[$field] === null) {
                return "{$label}: enter a number.";
            }
        }
        $holidayOvertime = self::number($this->formHolidayOvertime);
        if ($holidayOvertime === null) {
            return 'Holiday overtime: enter a percent of the holiday rate.';
        }
        $hourlyHours = null;
        if (trim($this->formHourlyHours) !== '') {
            $hourlyHours = self::number($this->formHourlyHours);
            if ($hourlyHours === null) {
                return 'Hours for the hourly rate: enter a number, or leave it blank.';
            }
        }
        $rounding = null;
        if (trim($this->formRounding) !== '') {
            if (preg_match('/^[0-4]$/', trim($this->formRounding)) !== 1) {
                return 'Rounding: choose 0 to 4 decimals, or leave it blank.';
            }
            $rounding = (int) trim($this->formRounding);
        }
        $seaportRate = null;
        if (trim($this->formSeaportRate) !== '') {
            $seaportRate = self::number($this->formSeaportRate);
            if ($seaportRate === null) {
                return 'Seaport overtime: enter a percentage, or leave it blank.';
            }
        }
        $minimumTakeHome = null;
        if (trim($this->formMinimumTakeHome) !== '') {
            $minimumTakeHome = self::number($this->formMinimumTakeHome);
            if ($minimumTakeHome === null) {
                return 'Minimum take-home pay: enter an amount, or leave it blank.';
            }
        }
        $otPercent = self::number($this->formMatrix['ORDINARY']['overtime'] ?? null);
        $nightPercent = self::number($this->formMatrix['ORDINARY']['night'] ?? null);
        $restPercent = self::number($this->formMatrix['REST']['worked'] ?? null);
        if ($otPercent === null) {
            return 'Ordinary workday, overtime hour: enter a percentage — it is the base overtime rate.';
        }
        if ($nightPercent === null) {
            return 'Ordinary workday, night hour: enter a percentage — it is the base night rate.';
        }
        if ($restPercent === null) {
            return 'Rest day, worked day: enter a percentage — it is the base rest-day rate.';
        }
        $matrix = [];
        foreach (PayRules::DAY_TYPES as $type) {
            foreach (['worked', 'overtime', 'night'] as $cell) {
                $text = trim((string) ($this->formMatrix[$type][$cell] ?? ''));
                if ($text === '') {
                    $matrix[$type][$cell] = null;

                    continue;
                }
                $value = self::number($text);
                if ($value === null) {
                    return self::DAY_TYPES[$type].', '.self::CELLS[$cell].': enter a percentage, or leave it blank.';
                }
                $matrix[$type][$cell] = $value;
            }
            $unworked = self::number($this->formMatrix[$type]['unworked'] ?? '');
            $matrix[$type]['unworked'] = $unworked ?? 0.0;
        }
        // The base rates are the ordinary overtime hour, the ordinary night
        // hour's add-on and the worked rest day; their cells stay derived.
        $matrix['ORDINARY']['worked'] = null;
        $matrix['ORDINARY']['overtime'] = null;
        $matrix['ORDINARY']['night'] = null;
        $matrix['REST']['worked'] = null;

        return [
            'basis' => $this->formBasis,
            'daily_rate' => ['method' => $this->formMethod, 'value' => $this->formMethod === PayRules::METHOD_MONTH ? 0.0 : self::amount($divisor, $stored['daily_rate']['value'] ?? null)],
            'holiday_needs_attendance' => $this->formHolidayNeedsAttendance,
            'overtime' => [
                'rate' => self::rate($otPercent, $stored['overtime']['rate'] ?? null),
                'max_hours' => self::amount($caps['formOvertimeMax'], $stored['overtime']['max_hours'] ?? null),
                'seaport_rate' => $seaportRate === null ? null : self::rate($seaportRate, $stored['overtime']['seaport_rate'] ?? null),
            ],
            'hourly_rate' => ['hours' => $hourlyHours === null ? null : self::amount($hourlyHours, $stored['hourly_rate']['hours'] ?? null)],
            'rounding' => ['decimals' => $rounding],
            'take_home' => ['minimum' => $minimumTakeHome === null ? null : self::amount($minimumTakeHome, $stored['take_home']['minimum'] ?? null)],
            'night' => [
                'rate' => self::rate($nightPercent - 100, $stored['night']['rate'] ?? null),
                'max_hours' => self::amount($caps['formNightMax'], $stored['night']['max_hours'] ?? null),
                'start' => $start,
                'end' => $end,
                'on_overtime' => $this->formNightOnOvertime,
            ],
            'rest_day' => ['rate' => self::rate($restPercent, $stored['rest_day']['rate'] ?? null), 'max_hours' => self::amount($caps['formRestMax'], $stored['rest_day']['max_hours'] ?? null)],
            'holiday_overtime_factor' => self::rate($holidayOvertime, $stored['holiday_overtime_factor'] ?? null),
            'matrix' => $matrix,
        ];
    }

    /**
     * The rules the base figures alone make, for placeholders and warnings
     * while a cell is being corrected: the typed table is dropped, the
     * reference's rules stand in when even the base is unreadable.
     *
     * @param  array<string, mixed>|string  $figures
     */
    private function baseRules(array|string $figures): PayRules
    {
        if (is_array($figures)) {
            try {
                return PayRules::fromArray(['matrix' => PayRules::emptyMatrix()] + $figures);
            } catch (InvalidArgumentException) {
                // The reference's rules stand in below.
            }
        }

        return PayRules::fromFormulas();
    }

    /** Whether the form differs from the figures it was filled from. */
    private function isDirty(Access $access): bool
    {
        $records = app(PayRuleRecords::class);
        $today = LocalClock::todayKey();
        $versions = $records->versions($access, $this->formScope);
        $loaded = self::inForce($versions, $today);
        $stored = $loaded->params ?? $this->inheritedInForce($access, $this->formScope, $today)->params ?? PayRules::fromFormulas()->toArray();
        $figures = $this->engineFigures($stored);

        return is_string($figures) || json_encode($figures) !== json_encode(self::canonical($stored));
    }

    /**
     * Stored figures in the engine's own key order, for the dirty compare:
     * a version whose later-added keys sit at the end, or are absent, reads
     * the same as the form filled from it.
     *
     * @param  array<string, mixed>  $stored
     * @return array<string, mixed>
     */
    private static function canonical(array $stored): array
    {
        return PayRules::error($stored) === null ? PayRules::fromArray($stored)->toArray() : $stored;
    }

    /**
     * The scope's latest figures, else those of what it falls back to,
     * else null.
     *
     * @return array<string, mixed>|null
     */
    private function latestFigures(Access $access): ?array
    {
        $records = app(PayRuleRecords::class);
        foreach ([$this->scope, ...$this->fallbacks($access, $this->scope)] as $scope) {
            $figures = $records->latest($access, $scope)?->params;
            if ($figures !== null) {
                return $figures;
            }
        }

        return null;
    }

    /** COMPANY, the Employment list id the select named, or the employee the scope names — re-resolved against the list and the employees. */
    private function resolvedScope(Access $access): ?string
    {
        if ($this->scope === PayRulesStore::COMPANY) {
            return PayRulesStore::COMPANY;
        }
        foreach ($this->scopeOptions($access) as $option) {
            if ($option['id'] === $this->scope) {
                return $option['id'];
            }
        }

        return null;
    }

    /**
     * The Applies-to options: the company rule, then every Employment
     * status, marked when it carries an override, then the employee on
     * screen when the scope is one (the employees are never all listed:
     * one is opened from Employee Pay Settings).
     *
     * @return list<array{id: string, name: string, override: bool}>
     */
    private function scopeOptions(Access $access): array
    {
        $overridden = app(PayRuleRecords::class)->overriddenScopes($access);
        $options = [['id' => PayRulesStore::COMPANY, 'name' => 'All employees (company rule)', 'override' => false]];
        foreach (app(ReferenceCatalogs::class)->for('employment')->options($access) as $status) {
            $options[] = ['id' => $status['id'], 'name' => $status['name'], 'override' => in_array($status['id'], $overridden, true)];
        }
        foreach (array_unique(array_filter([$this->scope, $this->formScope, $this->pendingScope])) as $scope) {
            if (in_array($scope, array_column($options, 'id'), true)) {
                continue;
            }
            $employee = $this->scopeEmployee($access, $scope);
            if ($employee !== null) {
                $options[] = ['id' => $employee->id, 'name' => EmployeeName::display($employee).' (this employee)', 'override' => false];
            }
        }

        return $options;
    }

    /**
     * The employee a scope names, for an all-data PAYROLL holder: null for
     * the company, a status, anyone else, or before employee rates exist.
     * Read once per request per scope.
     */
    private function scopeEmployee(Access $access, string $scope): ?EmployeePayData
    {
        if ($scope === PayRulesStore::COMPANY || ! PayRulesStore::employeeScopesReady() || ! $access->mayReadAllData(ModuleCode::PAYROLL)) {
            return null;
        }
        if (! array_key_exists($scope, $this->employees)) {
            $this->employees[$scope] = app(EmployeePayContexts::class)->get($access, [$scope])[0] ?? null;
        }

        return $this->employees[$scope];
    }

    /**
     * Who may save a version of the scope: an all-data PAYROLL holder for
     * an employee's rates, whoever manages the Payroll Settings
     * (PayrollSettingsAccess) for the company's and the statuses'.
     */
    private function canManage(Access $access, ?EmployeePayData $employee): bool
    {
        return $employee !== null ? $access->mayReadAllData(ModuleCode::PAYROLL) : PayrollSettingsAccess::mayManage($access);
    }

    /**
     * What prices a scope when its own versions do not, nearest first: an
     * employee's status, then the company; a status's company.
     *
     * @return list<string>
     */
    private function fallbacks(Access $access, string $scope): array
    {
        if ($scope === PayRulesStore::COMPANY) {
            return [];
        }
        $statusId = $this->scopeEmployee($access, $scope)?->employment_status_id;

        return $statusId === null ? [PayRulesStore::COMPANY] : [$statusId, PayRulesStore::COMPANY];
    }

    /** The version in force today that prices the scope when its own does not. */
    private function inheritedInForce(Access $access, string $scope, string $today): ?PayRuleView
    {
        foreach ($this->fallbacks($access, $scope) as $next) {
            $inForce = self::inForce(app(PayRuleRecords::class)->versions($access, $next), $today);
            if ($inForce !== null) {
                return $inForce;
            }
        }

        return null;
    }

    /** What the scope falls back to, in a phrase: "the company rule", "the Regular override". */
    private function inheritedName(Access $access, string $scope, string $today): string
    {
        $employee = $this->scopeEmployee($access, $scope);
        $inherited = $this->inheritedInForce($access, $scope, $today);
        if ($employee !== null && $inherited !== null && $inherited->scope !== PayRulesStore::COMPANY) {
            return 'the '.($employee->employment_status ?? 'status').' override';
        }

        return 'the company rule';
    }

    /**
     * What a blank cell pays, for its placeholder: THE PERCENT ITSELF
     * wherever one can be worked out — from the form's own figures, and for
     * a holiday row from the percent the Holidays list gives that kind of
     * holiday, through the engine's own steps (a rest day multiplies it, an
     * overtime hour multiplies it by the holiday overtime rate, a night hour
     * adds the ordinary night premium). Only where no single figure exists
     * does it say so in words: holidays of one kind carrying different
     * percents, a kind the list has none of, and a double holiday, which is
     * paid only with a figure typed here.
     *
     * @return array<string, array<string, string>>
     */
    private function derivedText(PayRules $rules): array
    {
        $holidays = $this->holidayPercents();
        $restFactor = max(100.0, (float) $rules->effectivePercent('REST', 'worked')) / 100;
        $nightPremium = (float) $rules->effectivePercent('ORDINARY', 'night') - 100.0;

        $text = [];
        foreach (PayRules::DAY_TYPES as $type) {
            $double = $type === 'DOUBLE' || $type === 'DOUBLE_REST';
            $rest = str_ends_with($type, '_REST');
            // The worked percent the holiday rows stand on: a typed cell, else
            // the list's percent for that kind (times the rest day), else none.
            $typed = $rules->effectivePercent($type, 'worked');
            $listed = $holidays[$double ? 'NONE' : (str_starts_with($type, 'SPECIAL') ? 'SPECIAL' : 'REGULAR')] ?? null;
            $worked = $typed ?? ($listed === null ? null : $listed * ($rest ? $restFactor : 1.0));

            foreach (['worked', 'overtime', 'night'] as $cell) {
                if (in_array("{$type}.{$cell}", self::BASE_CELLS, true)) {
                    $text[$type][$cell] = '';

                    continue;
                }
                $value = $rules->effectivePercent($type, $cell);
                if ($value === null && $worked !== null) {
                    $value = match ($cell) {
                        'worked' => $worked,
                        'overtime' => $worked * $rules->holidayOvertimeFactor,
                        default => $worked + $nightPremium,
                    };
                }
                $text[$type][$cell] = match (true) {
                    $value !== null => Hris::moneyText(Money::round($value)),
                    $double => $cell === 'worked' ? 'Type a rate' : 'Needs a worked rate',
                    default => 'Per holiday',
                };
            }
        }

        return $text;
    }

    /**
     * The percent the Holidays list gives each kind of holiday, when every
     * holiday of that kind carries the same one: `REGULAR` and `SPECIAL`.
     * Null for a kind with no holiday, or whose holidays differ.
     *
     * @return array<string, float|null>
     */
    private function holidayPercents(): array
    {
        $seen = [];
        foreach (app(HolidayCalendar::class)->all() as $holiday) {
            $kind = str_starts_with($holiday->type, 'SPECIAL') ? 'SPECIAL' : 'REGULAR';
            $seen[$kind][(string) $holiday->percent] = $holiday->percent;
        }

        $percents = [];
        foreach (['REGULAR', 'SPECIAL'] as $kind) {
            $percents[$kind] = count($seen[$kind] ?? []) === 1 ? (float) reset($seen[$kind]) : null;
        }

        return $percents;
    }

    /**
     * The daily-rate card's sample: what the method makes of the sample
     * salary, a day and an hour on an 8-hour shift.
     *
     * @return array{daily: string, hourly: string, hours: string, note: string}|null
     */
    private function sample(): ?array
    {
        $daily = match ($this->formMethod) {
            PayRules::METHOD_MONTH => self::SAMPLE_MONTHLY / self::SAMPLE_MONTH_DAYS,
            PayRules::METHOD_FACTOR => ($n = self::number($this->formDivisor)) !== null && $n > 0 ? self::SAMPLE_MONTHLY * 12 / $n : null,
            PayRules::METHOD_DIVISOR => ($n = self::number($this->formDivisor)) !== null && $n > 0 ? self::SAMPLE_MONTHLY / $n : null,
            default => null,
        };
        if ($daily === null) {
            return null;
        }
        // The sample follows the rounding and the fixed hours as typed, so
        // the officer sees the figures a payslip would start from.
        $decimals = preg_match('/^[0-4]$/', trim($this->formRounding)) === 1 ? (int) trim($this->formRounding) : null;
        $round = static fn (float $rate): float => $decimals === null ? $rate : Money::roundTo($rate, $decimals);
        $hours = self::number($this->formHourlyHours);
        $hours = $hours !== null && $hours >= 1 && $hours <= 24 ? $hours : 8.0;
        $daily = $round($daily);

        return [
            'daily' => Hris::money($daily),
            'hourly' => Hris::money($round($daily / $hours)),
            'hours' => Hris::moneyText($hours),
            'note' => $this->formMethod === PayRules::METHOD_MONTH ? 'in a month with '.self::SAMPLE_MONTH_DAYS.' working days' : '',
        ];
    }

    /**
     * The warning lines for the cells below their usual minimum.
     *
     * @return list<array{type: string, cell: string, text: string}>
     */
    private function shortfallText(PayRules $rules): array
    {
        $lines = [];
        foreach ($rules->shortfalls() as $shortfall) {
            $lines[] = [
                'type' => $shortfall['type'],
                'cell' => $shortfall['cell'],
                'text' => self::DAY_TYPES[$shortfall['type']].', '.self::CELLS[$shortfall['cell']].': '.Hris::moneyText($shortfall['value']).'% is below the usual minimum of '.Hris::moneyText($shortfall['minimum']).'%.',
            ];
        }

        return $lines;
    }

    /**
     * The engine's messages in the page's words: a day type by its name, a
     * cell by its column.
     */
    private static function humanize(?string $message): ?string
    {
        if ($message === null) {
            return null;
        }
        // Longest codes first, so REST never eats REGULAR_REST.
        $types = self::DAY_TYPES;
        uksort($types, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        foreach ($types as $code => $label) {
            $message = preg_replace('/\b'.preg_quote($code, '/').'\b/', $label, $message) ?? $message;
        }

        return str_replace(['worked rate', 'overtime rate', 'night rate', 'an unworked'], ['worked day', 'overtime hour', 'night hour', 'a not-worked'], $message);
    }

    /** The "type.cell" the live message names, so the table can flag it. */
    private static function invalidCell(?string $message): ?string
    {
        if ($message === null) {
            return null;
        }
        foreach (self::DAY_TYPES as $type => $label) {
            foreach (self::CELLS as $cell => $name) {
                if (str_contains($message, $label.', '.$name) || str_contains($message, $label.' '.$name)) {
                    return "{$type}.{$cell}";
                }
            }
        }

        return null;
    }

    /** Minutes from midnight as the time field's `HH:mm`, a bound past midnight folded back onto the clock. */
    private static function clockText(int $minutes): string
    {
        $minutes = (($minutes % 1440) + 1440) % 1440;

        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /** The time field's `HH:mm` as minutes from midnight, or null for anything else. */
    private static function minutesOf(string $clock): ?int
    {
        if (preg_match('/^(\d{1,2}):(\d{2})$/', trim($clock), $m) !== 1 || (int) $m[1] > 23 || (int) $m[2] > 59) {
            return null;
        }

        return (int) $m[1] * 60 + (int) $m[2];
    }

    /** A typed figure, keeping a stored figure whose display matches so the display rounding never edits it. */
    private static function amount(float $typed, mixed $stored): float
    {
        if (is_numeric($stored) && (string) $typed === Hris::moneyText($stored)) {
            return (float) $stored;
        }

        return $typed;
    }

    /** A typed percentage as the fraction the engine reads, keeping a stored fraction whose display matches. */
    private static function rate(float $percent, mixed $stored): float
    {
        if (is_numeric($stored) && (string) $percent === Hris::percentText($stored)) {
            return (float) $stored;
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
}
