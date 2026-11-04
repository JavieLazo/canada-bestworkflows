<?php

declare(strict_types=1);

namespace App\Livewire\Records\Support;

use App\Modules\Timekeeping\Contracts\Views\LeaveTypeView;
use App\Modules\Timekeeping\Domain\Credits\LeavePolicy;
use App\Modules\Timekeeping\Domain\Credits\LeaveQuantity;
use Illuminate\Support\Collection;

/**
 * The leave policy dialog's draft (Leave Credits presets and Leave
 * Management policies share it): a flat, wire:model-friendly array built
 * from LeavePolicy params, and the params built back from it. Every
 * leave type that earns credits gets an earning section; the charge
 * orders are ticks over the types that may be charged, in the company
 * order. Presentation only: the command validates the params.
 */
final class LeavePolicyForm
{
    private function __construct() {}

    /**
     * @param  array<string, mixed>  $params
     * @param  Collection<int, LeaveTypeView>  $types
     * @return array<string, mixed>
     */
    public static function draft(array $params, string $name, Collection $types): array
    {
        $policy = $params === [] ? LeavePolicy::none() : LeavePolicy::fromParams($params);
        $earning = [];
        foreach ($types as $type) {
            if (! (bool) $type->earns_credits || $type->token === null) {
                continue;
            }
            $rule = $policy->earning()[$type->token] ?? null;
            $rows = [];
            foreach ($rule['table'] ?? [] as $row) {
                $rows[] = ['up_to' => LeaveQuantity::format($row['up_to']), 'credits' => LeaveQuantity::format($row['credits'])];
            }
            $earning[$type->token] = [
                'enabled' => $rule !== null,
                'label' => $type->name,
                'amount' => $rule === null ? '' : LeaveQuantity::format($rule['amount']),
                'method' => $rule['method'] ?? 'FLAT',
                'rows' => $rows === [] ? [['up_to' => '0', 'credits' => '']] : $rows,
                'partial' => $rule['partial'] ?? 'PRO_RATA',
                'cap' => ($rule['cap'] ?? null) === null ? '' : LeaveQuantity::format($rule['cap']),
            ];
        }
        $lateTicks = [];
        foreach ($types as $type) {
            if ((bool) $type->charge_late && $type->token !== null) {
                $lateTicks[$type->token] = in_array($type->token, $policy->chargeOrder('late'), true);
            }
        }
        $absenceTicks = [];
        foreach ($types as $type) {
            if ((bool) $type->charge_absence && $type->token !== null) {
                $absenceTicks[$type->token] = in_array($type->token, $policy->chargeOrder('absence'), true);
            }
        }

        return [
            'name' => $name,
            'posting_day' => (string) $policy->postingDay(),
            'unpaid_leave' => in_array('UNPAID_LEAVE', $policy->unpaidCounts(), true),
            'unpaid_absence' => in_array('ABSENCE', $policy->unpaidCounts(), true),
            'earning' => $earning,
            'charges_enabled' => $policy->chargesEnabled(),
            'charges_combine' => $policy->combine(),
            'charge_late' => $lateTicks,
            'charge_absence' => $absenceTicks,
            'cash_out_max' => $policy->cashOutMaxPerYear() === null ? '' : LeaveQuantity::format($policy->cashOutMaxPerYear()),
        ];
    }

    /**
     * The params the draft stands for, for the command to validate.
     *
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     */
    public static function params(array $form): array
    {
        $earning = [];
        foreach ($form['earning'] ?? [] as $token => $rule) {
            if (! (bool) ($rule['enabled'] ?? false)) {
                continue;
            }
            $table = [];
            foreach ($rule['rows'] ?? [] as $row) {
                $table[] = ['up_to' => (string) ($row['up_to'] ?? ''), 'credits' => (string) ($row['credits'] ?? '')];
            }
            $earning[(string) $token] = [
                'amount' => (string) ($rule['amount'] ?? ''),
                'method' => (string) ($rule['method'] ?? ''),
                'table' => $table,
                'partial' => (string) ($rule['partial'] ?? ''),
                'cap' => trim((string) ($rule['cap'] ?? '')) === '' ? null : (string) $rule['cap'],
            ];
        }
        $counts = [];
        if ((bool) ($form['unpaid_leave'] ?? false)) {
            $counts[] = 'UNPAID_LEAVE';
        }
        if ((bool) ($form['unpaid_absence'] ?? false)) {
            $counts[] = 'ABSENCE';
        }
        $posting = (string) ($form['posting_day'] ?? LeavePolicy::MONTH_END);

        return [
            'posting_day' => $posting === LeavePolicy::MONTH_END ? LeavePolicy::MONTH_END : $posting,
            'unpaid_counts' => $counts,
            'earning' => $earning,
            'charges' => [
                'enabled' => (bool) ($form['charges_enabled'] ?? false),
                'combine' => (string) ($form['charges_combine'] ?? 'DAY'),
                'late' => array_keys(array_filter($form['charge_late'] ?? [], static fn (mixed $on): bool => (bool) $on)),
                'absence' => array_keys(array_filter($form['charge_absence'] ?? [], static fn (mixed $on): bool => (bool) $on)),
            ],
            'cash_out_max_per_year' => trim((string) ($form['cash_out_max'] ?? '')) === '' ? null : (string) $form['cash_out_max'],
        ];
    }

    /**
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     */
    public static function addRow(array $form, string $token): array
    {
        if (isset($form['earning'][$token]) && count($form['earning'][$token]['rows'] ?? []) < LeavePolicy::MAX_TABLE_ROWS) {
            $form['earning'][$token]['rows'][] = ['up_to' => '', 'credits' => ''];
        }

        return $form;
    }

    /**
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     */
    public static function removeRow(array $form, string $token, int $index): array
    {
        if (isset($form['earning'][$token]['rows'][$index]) && count($form['earning'][$token]['rows']) > 1) {
            unset($form['earning'][$token]['rows'][$index]);
            $form['earning'][$token]['rows'] = array_values($form['earning'][$token]['rows']);
        }

        return $form;
    }
}
