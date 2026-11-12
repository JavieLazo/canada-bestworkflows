<?php

declare(strict_types=1);

namespace App\Livewire\Records\Support;

use App\Modules\Timekeeping\Domain\Credits\LeaveQuantity;
use App\Modules\Timekeeping\Domain\Credits\LeaveTypeRule;

/**
 * The leave rule form's draft, to and from the params a leave type's rule
 * stores: text inputs and switches on the screen, the strict LeaveTypeRule
 * shape in the command. Presentation only — the command validates
 * everything again.
 */
final class LeaveRuleForm
{
    private function __construct() {}

    /**
     * A leave type's rule as the dialog edits it.
     *
     * @param  array<string, mixed>  $params  the rule in force, or [] for a type with none
     * @return array<string, mixed>
     */
    public static function type(array $params): array
    {
        $earning = $params['earning'] ?? null;
        $months = $params['waiting_months'] ?? null;
        $table = [];
        foreach ((is_array($earning) ? $earning['table'] ?? [] : []) as $row) {
            $table[] = ['up_to' => LeaveQuantity::format((float) $row['up_to']), 'credits' => LeaveQuantity::credits((float) $row['credits'])];
        }

        return [
            'waiting' => $months !== null,
            'waiting_months' => $months === null ? '' : (string) $months,
            'waiting_extends' => (bool) ($params['waiting_extends'] ?? false),
            'on_acquire' => (string) ($params['on_acquire'] ?? LeaveTypeRule::ZERO),
            'earns' => is_array($earning),
            'amount' => is_array($earning) ? LeaveQuantity::credits((float) $earning['amount']) : '',
            'method' => is_array($earning) ? (string) $earning['method'] : 'FLAT',
            'table' => $table,
            'partial' => is_array($earning) ? (string) $earning['partial'] : 'PRO_RATA',
            'cap' => is_array($earning) && $earning['cap'] !== null ? LeaveQuantity::credits((float) $earning['cap']) : '',
            'fallback' => (string) ($params['fallback'] ?? ''),
        ];
    }

    /**
     * The params SaveLeaveRule takes for a leave type, from the draft. A
     * type not filed against earned credits (`$credits` false) has only a
     * waiting period: the fields it does not show — the earning, the
     * fallback, a grant on acquiring — are left out rather than sent from
     * whatever the hidden draft still holds.
     *
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     */
    public static function typeParams(array $form, bool $credits = true): array
    {
        $waits = (bool) ($form['waiting'] ?? false);
        $cap = trim((string) ($form['cap'] ?? ''));
        $fallback = $credits ? trim((string) ($form['fallback'] ?? '')) : '';

        return [
            'waiting_months' => $waits ? trim((string) ($form['waiting_months'] ?? '')) : null,
            'waiting_extends' => $waits && (bool) ($form['waiting_extends'] ?? false),
            'on_acquire' => $waits && $credits ? (string) ($form['on_acquire'] ?? LeaveTypeRule::ZERO) : LeaveTypeRule::ZERO,
            'earning' => $credits && ($form['earns'] ?? false) ? [
                'amount' => trim((string) ($form['amount'] ?? '')),
                'method' => (string) ($form['method'] ?? ''),
                'table' => array_values(array_map(static fn (mixed $row): array => [
                    'up_to' => trim((string) (is_array($row) ? $row['up_to'] ?? '' : '')),
                    'credits' => trim((string) (is_array($row) ? $row['credits'] ?? '' : '')),
                ], is_array($form['table'] ?? null) ? $form['table'] : [])),
                'partial' => (string) ($form['partial'] ?? ''),
                'cap' => $cap === '' ? null : $cap,
            ] : null,
            'fallback' => $fallback === '' ? null : $fallback,
        ];
    }

    /**
     * Whether the draft says anything: a waiting period, an earning or a
     * fallback (the last two only for a type filed against earned credits).
     * One left as it starts is a type with no rule.
     *
     * @param  array<string, mixed>  $form
     */
    public static function touched(array $form, bool $credits = true): bool
    {
        return (bool) ($form['waiting'] ?? false)
            || ($credits && ((bool) ($form['earns'] ?? false) || trim((string) ($form['fallback'] ?? '')) !== ''));
    }
}
