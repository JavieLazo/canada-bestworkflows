<?php

declare(strict_types=1);

namespace App\Livewire\Payroll\Support;

/**
 * The Payroll Settings pages as their shared switch lists them: the switch's
 * key, the label and the route of each, in the order they are offered. ONE
 * list, so the switch's links and its dropdown can never disagree.
 *
 * PAY SCHEDULES AND ADJUSTMENT CATEGORIES ARE NOT LISTED: both pages stay
 * reachable by their address alone, so the switch never offers them.
 */
final class SettingsPages
{
    private function __construct() {}

    /**
     * @return array<string, array{label: string, route: string}>
     */
    public static function all(): array
    {
        return [
            'employees' => ['label' => 'Employees', 'route' => 'records.employee-pay-settings'],
            'tables' => ['label' => 'Contribution & Tax Tables', 'route' => 'records.contribution-tables'],
            'rates' => ['label' => 'Pay Basis & Rates', 'route' => 'records.pay-rates'],
            'deductions' => ['label' => 'Payroll Deductions', 'route' => 'payroll.settings.deductions'],
            'incentives' => ['label' => 'Incentives & Bonuses', 'route' => 'records.incentive-types'],
            'signatories' => ['label' => 'Signatories', 'route' => 'payroll.settings.signatories'],
        ];
    }
}
