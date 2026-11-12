<?php

declare(strict_types=1);

namespace App\Livewire\Records\Support;

use App\Domain\Payroll\PayRules;
use App\Modules\Payroll\Application\PayRulesStore;
use App\Modules\Payroll\Contracts\Views\PayRuleView;

/**
 * Which Pay Basis & Rates price one employee today, in the order payroll
 * resolves a period (PayRulesStore): a version of their own, else their
 * Employment status's override, else the company rule — and the version of
 * their own that comes next, if one is dated. A "follows" version of their
 * own (no figures) hands them back to the status or company rule.
 */
final class EmployeeRates
{
    public const string OWN = 'OWN';

    public const string STATUS = 'STATUS';

    public const string COMPANY = 'COMPANY';

    private function __construct() {}

    /**
     * @param  array<int, PayRuleView>  $own  the employee's versions, newest first, drafts included (they are counted, never in force)
     * @param  array<int, PayRuleView>  $status  their status's versions, newest first
     * @param  array<int, PayRuleView>  $company  the company's versions, newest first
     * @return array{source: string, version: ?PayRuleView, next: ?PayRuleView, drafts: int}
     */
    public static function today(array $own, array $status, array $company, string $today): array
    {
        $drafts = count(array_filter($own, static fn (PayRuleView $row): bool => $row->status === PayRulesStore::DRAFT));
        $own = array_values(array_filter($own, static fn (PayRuleView $row): bool => $row->status !== PayRulesStore::DRAFT));
        $next = null;
        foreach ($own as $row) {
            if ($row->effective_from->format('Y-m-d') > $today) {
                $next = $row;
            }
        }
        $ownInForce = self::inForce($own, $today);
        if ($ownInForce !== null && $ownInForce->params !== null) {
            return ['source' => self::OWN, 'version' => $ownInForce, 'next' => $next, 'drafts' => $drafts];
        }
        $statusInForce = self::inForce($status, $today);
        if ($statusInForce !== null) {
            return ['source' => self::STATUS, 'version' => $statusInForce, 'next' => $next, 'drafts' => $drafts];
        }

        return ['source' => self::COMPANY, 'version' => self::inForce($company, $today), 'next' => $next, 'drafts' => $drafts];
    }

    /**
     * Whether the employee is priced apart from the general rules, now or
     * from a dated version of their own.
     *
     * @param  array{source: string, version: ?PayRuleView, next: ?PayRuleView, drafts: int}  $state
     */
    public static function customized(array $state): bool
    {
        return $state['source'] === self::OWN || $state['next'] !== null;
    }

    /**
     * The list's phrase: "Own rates", "Regular override" or "Company rule",
     * with what changes next.
     *
     * @param  array{source: string, version: ?PayRuleView, next: ?PayRuleView, drafts: int}  $state
     */
    public static function phrase(array $state, ?string $statusName): string
    {
        $text = match ($state['source']) {
            self::OWN => 'Own rates',
            self::STATUS => ($statusName ?? 'Status').' override',
            default => 'Company rule',
        };
        $next = $state['next'];
        if ($next === null) {
            return $text;
        }
        $from = $next->effective_from->format('d M Y');

        return $text.match (true) {
            $next->params === null => ", general from {$from}",
            $state['source'] === self::OWN => ", changes {$from}",
            default => ", own from {$from}",
        };
    }

    /**
     * A version's basis and daily-rate method in a phrase.
     *
     * @param  array<string, mixed>|null  $figures
     */
    public static function summary(?array $figures): string
    {
        $figures ??= PayRules::fromFormulas()->toArray();
        $basis = ($figures['basis'] ?? null) === PayRules::BASIS_MONTHLY ? 'Monthly-paid' : 'Daily-paid';
        $value = Hris::moneyText($figures['daily_rate']['value'] ?? 0);
        $method = match ($figures['daily_rate']['method'] ?? null) {
            PayRules::METHOD_FACTOR => "a day is the monthly rate × 12 ÷ {$value}",
            PayRules::METHOD_DIVISOR => "a day is the monthly rate ÷ {$value}",
            default => "a day is the monthly rate ÷ the month's working days",
        };

        return "{$basis} · {$method}";
    }

    /**
     * The newest version that has started.
     *
     * @param  array<int, PayRuleView>  $versions  newest first
     */
    private static function inForce(array $versions, string $today): ?PayRuleView
    {
        foreach ($versions as $row) {
            if ($row->effective_from->format('Y-m-d') <= $today) {
                return $row;
            }
        }

        return null;
    }
}
