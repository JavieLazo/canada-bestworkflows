<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application\Queries;

use App\Models\LeaveType;
use App\Modules\Timekeeping\Application\LeaveSettingsAccess;
use App\Modules\Timekeeping\Contracts\Views\LeaveTypeView;
use App\Modules\Timekeeping\Domain\Absence\LeaveApplication;
use App\Modules\Timekeeping\Domain\Credits\LeaveCashOutLimits;
use App\Modules\Timekeeping\Domain\Credits\LeaveEligibility;
use App\Modules\Timekeeping\Domain\Credits\LeaveTypeCredits;
use App\Platform\ReadModels\Snapshot;
use App\Support\Access;
use App\Support\HrisFormat;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Each leave type with its credit rules (LeaveTypeCredits), in Leave
 * Settings (name) order: what the settings page lists, what the earning
 * policy offers to earn, and what the charge rule may charge, in order.
 */
final class LeaveTypeCreditRecords
{
    public static function ready(): bool
    {
        return once(static fn (): bool => Schema::hasColumn('leave_types', 'earns_credits'));
    }

    /** Whether the cash-out flag exists yet (the window before the cron migrates 2026_10_31_0008). */
    public static function cashOutReady(): bool
    {
        return once(static fn (): bool => Schema::hasColumn('leave_types', 'can_cash_out'));
    }

    /** Whether the year-end rule column exists yet (the window before the cron migrates 2026_11_02_0017). */
    public static function yearEndReady(): bool
    {
        return once(static fn (): bool => Schema::hasColumn('leave_types', 'year_end'));
    }

    /** Whether how-it-is-filed exists yet (the window before the cron migrates 2026_11_07_0001). */
    public static function filingReady(): bool
    {
        return once(static fn (): bool => Schema::hasColumn('leave_types', 'filing'));
    }

    /** Whether the details-of-leave setting exists yet (the window before the cron migrates 2026_11_08_0002). */
    public static function detailsReady(): bool
    {
        return once(static fn (): bool => Schema::hasColumn('leave_types', 'leave_details'));
    }

    /** Whether the cash conversion limits and the separation payout exist yet (the window before the cron migrates 2026_11_09_0001). */
    public static function cashOutLimitsReady(): bool
    {
        return once(static fn (): bool => Schema::hasColumn('leave_types', 'paid_on_separation'));
    }

    /** Whether who may use a type exists yet (the window before the cron migrates 2026_11_09_0005). */
    public static function eligibilityReady(): bool
    {
        return once(static fn (): bool => Schema::hasColumn('leave_types', 'eligible_sex'));
    }

    /**
     * The tokens of the types not everyone may use, with who may (by
     * employment status and sex), in name order.
     *
     * @return array<string, LeaveEligibility>
     */
    public static function restricted(): array
    {
        $out = [];
        foreach (self::rules() as $token => $entry) {
            if (! $entry['rules']->eligibility->isEveryone()) {
                $out[$token] = $entry['rules']->eligibility;
            }
        }

        return $out;
    }

    /**
     * The tokens whose remaining balance is paid out when an employee
     * leaves (terminal leave), in name order.
     *
     * @return list<string>
     */
    public static function paidOnSeparation(): array
    {
        $out = [];
        foreach (self::rules() as $token => $entry) {
            if ($entry['rules']->paidOnSeparation) {
                $out[] = $token;
            }
        }

        return $out;
    }

    /**
     * The settings of one leave type by its token — the request type a
     * request carries — or, for a token no leave type carries any more,
     * today's defaults read from the token as a name (a stale "without pay"
     * token is still without pay).
     */
    public static function of(string $token): LeaveTypeCredits
    {
        $rules = self::rules()[$token]['rules'] ?? null;
        if ($rules !== null) {
            return $rules;
        }
        $legacy = LeaveType::filingByName(str_replace('_', ' ', $token));

        return new LeaveTypeCredits(false, LeaveTypeCredits::UNIT_DAYS, false, false, 0, 0.0, false, LeaveTypeCredits::YEAR_END_CARRY, $legacy);
    }

    /**
     * The tokens whose credits may be cashed out, in name order.
     *
     * @return list<string>
     */
    public static function cashable(): array
    {
        $out = [];
        foreach (self::rules() as $token => $entry) {
            if ($entry['rules']->canCashOut) {
                $out[] = $token;
            }
        }

        return $out;
    }

    /** @return Collection<int, LeaveTypeView> */
    public function list(Access $access): Collection
    {
        if (! LeaveSettingsAccess::mayView($access)) {
            return new Collection;
        }

        return LeaveType::query()->orderBy('name')->get()
            ->map(static fn (LeaveType $row): LeaveTypeView => Snapshot::of($row, LeaveTypeView::class, ['token' => HrisFormat::normalizeRequestType((string) $row->getAttribute('name'))]));
    }

    /**
     * The settings of every type, keyed by the token requests and credits
     * carry — what the policy, the charge rule and filing read. Before the
     * columns exist, every type has today's rules: nothing earns, nothing
     * is charged, and a type is filed without pay when its name says so,
     * else against credits. A type not filed against credits never earns,
     * is never charged, is never cashed out and is never paid out on
     * separation, whatever its columns hold; a type that may not be cashed
     * out has no cash conversion limits.
     *
     * @return array<string, array{name: string, rules: LeaveTypeCredits}>
     */
    public static function rules(): array
    {
        return self::rulesOf(LeaveType::query()->orderBy('name')->get());
    }

    /**
     * rules() over rows the caller already holds — SaveLeaveTypeCredits
     * reads them locked, to check a charge order against the others.
     *
     * @param  iterable<LeaveType>  $rows  in name order
     * @return array<string, array{name: string, rules: LeaveTypeCredits}>
     */
    public static function rulesOf(iterable $rows): array
    {
        $ready = self::ready();
        $cashOut = $ready && self::cashOutReady();
        $filingReady = self::filingReady();
        $detailsReady = self::detailsReady();
        $limitsReady = $cashOut && self::cashOutLimitsReady();
        $eligibilityReady = self::eligibilityReady();
        $out = [];
        foreach ($rows as $row) {
            $name = (string) $row->getAttribute('name');
            $token = HrisFormat::normalizeRequestType($name);
            if ($token === '' || isset($out[$token])) {
                continue;
            }
            $filing = $filingReady && in_array($row->getAttribute('filing'), LeaveTypeCredits::FILINGS, true)
                ? (string) $row->getAttribute('filing') : LeaveType::filingByName($name);
            $credits = $filing === LeaveTypeCredits::FILING_CREDITS;
            $allowance = $filingReady && in_array($filing, [LeaveTypeCredits::FILING_YEARLY, LeaveTypeCredits::FILING_OCCASION], true) && $row->getAttribute('allowance_days') !== null
                ? (float) $row->getAttribute('allowance_days') : null;
            $dayCount = $filingReady && in_array($row->getAttribute('day_count'), LeaveTypeCredits::DAY_COUNTS, true)
                ? (string) $row->getAttribute('day_count') : LeaveTypeCredits::DAYS_WORKING;
            $details = $detailsReady ? LeaveApplication::section($row->getAttribute('leave_details')) : LeaveApplication::NONE;
            $canCashOut = $credits && $cashOut && (bool) $row->getAttribute('can_cash_out');
            $limits = $limitsReady && $canCashOut ? LeaveCashOutLimits::fromColumns(
                $row->getAttribute('cash_out_min_balance'), $row->getAttribute('cash_out_min_days'), $row->getAttribute('cash_out_keep_days'),
                $row->getAttribute('cash_out_max_year'), $row->getAttribute('cash_out_per_year'), $row->getAttribute('cash_out_reason_share'),
            ) : new LeaveCashOutLimits;
            $eligibility = $eligibilityReady
                ? LeaveEligibility::fromColumns($row->getAttribute('eligible_statuses'), $row->getAttribute('eligible_sex'))
                : new LeaveEligibility;
            $out[$token] = [
                'name' => $name,
                'rules' => $ready ? new LeaveTypeCredits(
                    $credits && (bool) $row->getAttribute('earns_credits'),
                    (string) ($row->getAttribute('unit') ?? LeaveTypeCredits::UNIT_DAYS),
                    $credits && (bool) $row->getAttribute('charge_late'),
                    $credits && (bool) $row->getAttribute('charge_absence'),
                    (int) ($row->getAttribute('charge_order') ?? 0),
                    (float) ($row->getAttribute('below_zero_limit') ?? 0.0),
                    $canCashOut,
                    self::yearEndReady() && in_array($row->getAttribute('year_end'), LeaveTypeCredits::YEAR_ENDS, true) ? (string) $row->getAttribute('year_end') : LeaveTypeCredits::YEAR_END_CARRY,
                    $filing,
                    $allowance,
                    $dayCount,
                    $details,
                    $limits,
                    $credits && $limitsReady && (bool) $row->getAttribute('paid_on_separation'),
                    $eligibility,
                ) : new LeaveTypeCredits(false, LeaveTypeCredits::UNIT_DAYS, false, false, 0, 0.0, false, LeaveTypeCredits::YEAR_END_CARRY, $filing, leaveDetails: $details, eligibility: $eligibility),
            ];
        }

        return $out;
    }

    /**
     * The tokens that may be charged for a kind of charge ('late' or
     * 'absence'), in charge order then name order.
     *
     * @return list<string>
     */
    public static function chargeable(string $kind): array
    {
        $rows = [];
        foreach (self::rules() as $token => $entry) {
            $rules = $entry['rules'];
            if (($kind === 'late' && $rules->chargeLate) || ($kind === 'absence' && $rules->chargeAbsence)) {
                $rows[] = ['token' => $token, 'order' => $rules->chargeOrder, 'name' => $entry['name']];
            }
        }
        usort($rows, static fn (array $a, array $b): int => [$a['order'], $a['name']] <=> [$b['order'], $b['name']]);

        return array_column($rows, 'token');
    }
}
