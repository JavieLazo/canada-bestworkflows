<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application\Queries;

use App\Models\LeaveType;
use App\Modules\Timekeeping\Application\LeaveSettingsAccess;
use App\Modules\Timekeeping\Contracts\Views\LeaveTypeView;
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
     * The credit rules of every type, keyed by the token requests and
     * credits carry — what the policy and the charge rule read. Before the
     * columns exist, every type has today's rules: nothing earns, nothing
     * is charged.
     *
     * @return array<string, array{name: string, rules: LeaveTypeCredits}>
     */
    public static function rules(): array
    {
        $ready = self::ready();
        $cashOut = $ready && self::cashOutReady();
        $out = [];
        foreach (LeaveType::query()->orderBy('name')->get() as $row) {
            $token = HrisFormat::normalizeRequestType((string) $row->getAttribute('name'));
            if ($token === '' || isset($out[$token])) {
                continue;
            }
            $out[$token] = [
                'name' => (string) $row->getAttribute('name'),
                'rules' => $ready ? new LeaveTypeCredits(
                    (bool) $row->getAttribute('earns_credits'),
                    (string) ($row->getAttribute('unit') ?? LeaveTypeCredits::UNIT_DAYS),
                    (bool) $row->getAttribute('charge_late'),
                    (bool) $row->getAttribute('charge_absence'),
                    (int) ($row->getAttribute('charge_order') ?? 0),
                    (float) ($row->getAttribute('below_zero_limit') ?? 0.0),
                    $cashOut && (bool) $row->getAttribute('can_cash_out'),
                    self::yearEndReady() && in_array($row->getAttribute('year_end'), LeaveTypeCredits::YEAR_ENDS, true) ? (string) $row->getAttribute('year_end') : LeaveTypeCredits::YEAR_END_CARRY,
                ) : LeaveTypeCredits::none(),
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
