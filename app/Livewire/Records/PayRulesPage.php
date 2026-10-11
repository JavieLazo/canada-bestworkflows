<?php

declare(strict_types=1);

namespace App\Livewire\Records;

use App\Domain\Enums\ModuleCode;
use App\Domain\Payroll\PayrollPeriod;
use App\Domain\Payroll\PayRules;
use App\Livewire\Records\Support\Hris;
use App\Modules\Payroll\Application\Commands\SavePayRulesVersion;
use App\Modules\Payroll\Application\PayrollPeriodText;
use App\Modules\Payroll\Application\PayRulesStore;
use App\Modules\Payroll\Application\Queries\PayRuleRecords;
use App\Modules\Payroll\Contracts\Views\PayRuleView;
use App\Platform\Records\ReferenceCatalogs;
use App\Support\Access;
use App\Support\ManilaClock;
use Carbon\CarbonImmutable;
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
 * time (the company rule, or an Employment status's override): the pay
 * basis, the daily-rate method, the night window, the daily caps and the
 * rates-by-day-type table, filled from the version IN FORCE for that scope
 * (an upcoming version is listed but prices nothing yet) or a preset.
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
        'SPECIAL_REST' => 'Special day on a rest day',
        'REGULAR' => 'Regular holiday',
        'REGULAR_REST' => 'Regular holiday on a rest day',
        'DOUBLE' => 'Two regular holidays',
        'DOUBLE_REST' => 'Two regular holidays on a rest day',
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
     * Who the figures on screen apply to: COMPANY, or an Employment list
     * entry's id. Client-chosen, like a filter: it only picks which
     * versions are shown and which scope a save names, and save()
     * re-resolves it against the Employment list before writing. Not Locked
     * because the select binds it.
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

    /** The version whose figures fill the form, or null for the scope's version in force. Id-bearing → #[Locked]. */
    #[Locked]
    public ?string $loadedId = null;

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

    /**
     * The rates-by-day-type cells as typed, type => cell => text. A blank
     * is "as derived". Client-editable, so read defensively.
     *
     * @var array<string, array<string, string>>
     */
    public array $formMatrix = [];

    public string $formEffectiveFrom = '';

    public string $formReason = '';

    /** The save's inline error — a refusal of the last save, cleared by the next edit. */
    public ?string $formError = null;

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
        $this->loadedId = null;
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
        $this->loadedId = null;
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
        $this->loadedId = null;
        $this->formError = null;
        Flux::toast(variant: 'success', text: ucfirst(strtolower($name)).' preset filled in — nothing saved yet.');
    }

    /** Fill the form from a saved version of the scope in view, to read it or to start a new version from it. */
    public function loadVersion(string $id): void
    {
        $access = app(Access::class);
        $row = app(PayRuleRecords::class)->versions($access, $this->scope)->first(static fn (PayRuleView $row): bool => $row->id === $id);
        if ($row === null) {
            Flux::toast(variant: 'danger', text: 'That version no longer exists.');

            return;
        }
        $this->loadedId = $row->id;
        $this->formError = null;
        $this->fillFrom($row->params);
        Flux::toast(variant: 'success', text: 'The version from '.$row->effective_from->format('d M Y').' is in the form.');
    }

    public function save(): void
    {
        $this->formError = null;
        $access = app(Access::class);
        if (! $access->mayReadAllData(ModuleCode::RECORDS)) {
            Flux::toast(variant: 'danger', text: 'Your role cannot manage organization-wide data.');

            return;
        }
        $scope = $this->resolvedScope($access);
        if ($scope === null) {
            $this->formError = 'Choose who the rules apply to.';

            return;
        }
        $figures = $this->engineFigures($this->latestFigures($access) ?? PayRules::fromFormulas()->toArray());
        if (is_string($figures)) {
            $this->formError = $figures;

            return;
        }
        try {
            $message = app(SavePayRulesVersion::class)->handle($access, $scope, $this->formEffectiveFrom, $this->formReason, $figures);
        } catch (QueryException $e) {
            report($e);
            Flux::toast(variant: 'danger', text: 'The version could not be saved. Nothing was changed. Please try again.');

            return;
        } catch (\RuntimeException $e) {
            $this->formError = self::humanize($e->getMessage());

            return;
        }
        Flux::toast(variant: 'success', text: $message);
        $this->loadedId = null;
        $this->loadForm($access);
    }

    public function render(): View
    {
        $access = app(Access::class);
        $records = app(PayRuleRecords::class);
        $versions = $records->versions($access, $this->scope);
        $latest = $versions->first();
        $today = ManilaClock::todayKey();
        $inForce = self::inForce($versions, $today);
        $loaded = $this->loadedId === null ? $inForce : $versions->first(fn (PayRuleView $row): bool => $row->id === $this->loadedId) ?? $inForce;
        $companyVersions = $this->scope === PayRulesStore::COMPANY ? $versions : $records->versions($access, PayRulesStore::COMPANY);
        $companyInForce = self::inForce($companyVersions, $today);
        $stored = $loaded->params ?? $companyInForce->params ?? PayRules::fromFormulas()->toArray();

        $figures = $this->engineFigures($stored);
        $figuresError = is_string($figures) ? $figures : self::humanize(PayRules::error($figures));
        $rules = null;
        if (! is_string($figures) && $figuresError === null) {
            $rules = PayRules::fromArray($figures);
        }
        // The placeholders and the warnings stay on screen while a cell is
        // being corrected: they read the base rates, which stand on their own.
        $placeholderRules = $rules ?? $this->baseRules($figures);
        $upcoming = $latest !== null && ($inForce === null || $latest->id !== $inForce->id) ? $latest : null;

        return view('livewire.records.pay-rules-page', [
            'scopes' => $this->scopeOptions($access),
            'versions' => $versions,
            'latest' => $latest,
            'inForce' => $inForce,
            'upcoming' => $upcoming,
            'loaded' => $loaded,
            'companyInForce' => $companyInForce,
            'canManage' => $access->mayReadAllData(ModuleCode::RECORDS),
            'ready' => PayRulesStore::ready(),
            'dayTypes' => self::DAY_TYPES,
            'derived' => $this->derivedText($placeholderRules),
            'sample' => $this->sample(),
            'shortfalls' => $this->shortfallText($placeholderRules),
            'figuresError' => $figuresError,
            'invalidCell' => self::invalidCell($figuresError),
            'dirty' => is_string($figures) || json_encode($figures) !== json_encode($stored),
            'today' => $today,
            'effectiveText' => $this->formEffectiveFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->formEffectiveFrom) === 1 ? PayrollPeriodText::day($this->formEffectiveFrom) : '',
            'cutoffNote' => self::cutoffNote($this->formEffectiveFrom),
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
        return $versions->first(static fn (PayRuleView $row): bool => $row->effective_from->format('Y-m-d') <= $today);
    }

    /** Fill the form from the scope's version in force, else the company's, else the reference's rules. */
    private function loadForm(Access $access): void
    {
        $records = app(PayRuleRecords::class);
        $today = ManilaClock::todayKey();
        $versions = $records->versions($access, $this->scope);
        $latest = $versions->first();
        $inForce = self::inForce($versions, $today);
        $base = $inForce ?? ($this->scope === PayRulesStore::COMPANY ? null : self::inForce($records->versions($access, PayRulesStore::COMPANY), $today));
        $this->fillFrom($base->params ?? PayRules::fromFormulas()->toArray());
        $this->formScope = $this->scope;
        // The next version starts on a cut-off's first day (the 1st or the
        // 16th) on or after today and after every version of the scope: a
        // version starting inside a payroll period blocks that payroll.
        $after = $latest === null ? $today : max($today, $latest->effective_from->addDay()->format('Y-m-d'));
        $this->formEffectiveFrom = self::nextCutoffStart($after);
        $this->formReason = '';
    }

    /** The 1st or the 16th on or after a day. */
    private static function nextCutoffStart(string $day): string
    {
        $date = CarbonImmutable::parse($day, 'UTC');
        if ($date->day === 1 || $date->day === 16) {
            return $date->format('Y-m-d');
        }

        return ($date->day < 16 ? $date->day(16) : $date->addMonthNoOverflow()->day(1))->format('Y-m-d');
    }

    /** A live note while the chosen date is not a cut-off's first day — the command refuses it on save. */
    private static function cutoffNote(string $day): ?string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1) {
            return null;
        }

        return PayrollPeriod::isCutoffStart($day) ? null : 'This date is not the first day of a payroll period. Choose the 1st or the 16th.';
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
            'overtime' => ['rate' => self::rate($otPercent, $stored['overtime']['rate'] ?? null), 'max_hours' => self::amount($caps['formOvertimeMax'], $stored['overtime']['max_hours'] ?? null)],
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
        $today = ManilaClock::todayKey();
        $versions = $records->versions($access, $this->formScope);
        $loaded = $this->loadedId === null ? self::inForce($versions, $today) : $versions->first(fn (PayRuleView $row): bool => $row->id === $this->loadedId);
        $stored = $loaded->params ?? self::inForce($records->versions($access, PayRulesStore::COMPANY), $today)->params ?? PayRules::fromFormulas()->toArray();
        $figures = $this->engineFigures($stored);

        return is_string($figures) || json_encode($figures) !== json_encode($stored);
    }

    /**
     * The scope's latest figures, else the company's, else null.
     *
     * @return array<string, mixed>|null
     */
    private function latestFigures(Access $access): ?array
    {
        $records = app(PayRuleRecords::class);
        $latest = $records->latest($access, $this->scope) ?? ($this->scope === PayRulesStore::COMPANY ? null : $records->latest($access, PayRulesStore::COMPANY));

        return $latest?->params;
    }

    /** COMPANY, or the Employment list id the select named, re-resolved against the list. */
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
     * status, marked when it carries an override.
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

        return $options;
    }

    /**
     * What a blank cell is priced as, for its placeholder: a number where
     * the form's own figures decide, a phrase where the day's holiday does.
     * A double holiday is priced only with a figure, so its cells say so.
     *
     * @return array<string, array<string, string>>
     */
    private function derivedText(PayRules $rules): array
    {
        $text = [];
        foreach (PayRules::DAY_TYPES as $type) {
            foreach (['worked', 'overtime', 'night'] as $cell) {
                if (in_array("{$type}.{$cell}", self::BASE_CELLS, true)) {
                    $text[$type][$cell] = '';

                    continue;
                }
                if (($type === 'DOUBLE' || $type === 'DOUBLE_REST') && $cell === 'worked') {
                    $text[$type][$cell] = 'enter to price';

                    continue;
                }
                $value = $rules->effectivePercent($type, $cell);
                if ($value !== null) {
                    $text[$type][$cell] = Hris::moneyText($value);

                    continue;
                }
                $rest = str_ends_with($type, '_REST');
                $text[$type][$cell] = match ($cell) {
                    'worked' => $rest ? 'holiday % × rest day' : 'holiday %',
                    'overtime' => $rest ? 'holiday % × '.$this->formHolidayOvertime.'% × rest day' : 'holiday % × '.$this->formHolidayOvertime.'%',
                    default => 'worked + night add-on',
                };
            }
        }

        return $text;
    }

    /**
     * The daily-rate card's sample: what the method makes of the sample
     * salary, a day and an hour on an 8-hour shift.
     *
     * @return array{daily: string, hourly: string, note: string}|null
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

        return [
            'daily' => Hris::peso($daily),
            'hourly' => Hris::peso($daily / 8),
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
