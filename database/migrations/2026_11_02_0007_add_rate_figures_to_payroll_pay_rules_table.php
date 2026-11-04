<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DATA — three new Pay Basis & Rates figures, BLANK, on every stored
 * version's `params` (`payroll_pay_rules`): `hourly_rate.hours`,
 * `rounding.decimals` and `overtime.seaport_rate` (PayRules, divergence
 * 16). Blank is the reference's behaviour, so NO MONEY MOVES; the keys are
 * written so every saved version carries the same shape the engine
 * writes, and the Pay Basis & Rates page reads a version as it was saved.
 * A "follows" row (NULL `params`) is left NULL.
 *
 * RETRY-SAFE: a version that already carries a key keeps its value; only a
 * missing key is added, so a run stopped halfway finishes the rest.
 *
 * ROLLBACK LOSES NOTHING that prices a payslip: the keys are removed, and
 * a version read without them is read as blank. A seaport rate, hours or
 * decimals someone set ARE lost on rollback.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payroll_pay_rules')) {
            return;
        }

        foreach (DB::table('payroll_pay_rules')->whereNotNull('params')->get(['id', 'params']) as $row) {
            $params = json_decode((string) $row->params, true);
            if (! is_array($params)) {
                continue;
            }
            $changed = false;
            if (! is_array($params['overtime'] ?? null)) {
                continue;
            }
            if (! array_key_exists('seaport_rate', $params['overtime'])) {
                $params['overtime']['seaport_rate'] = null;
                $changed = true;
            }
            if (! array_key_exists('hourly_rate', $params)) {
                $params['hourly_rate'] = ['hours' => null];
                $changed = true;
            }
            if (! array_key_exists('rounding', $params)) {
                $params['rounding'] = ['decimals' => null];
                $changed = true;
            }
            if ($changed) {
                DB::table('payroll_pay_rules')->where('id', $row->id)->update(['params' => json_encode($params)]);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('payroll_pay_rules')) {
            return;
        }

        foreach (DB::table('payroll_pay_rules')->whereNotNull('params')->get(['id', 'params']) as $row) {
            $params = json_decode((string) $row->params, true);
            if (! is_array($params)) {
                continue;
            }
            unset($params['hourly_rate'], $params['rounding']);
            if (is_array($params['overtime'] ?? null)) {
                unset($params['overtime']['seaport_rate']);
            }
            DB::table('payroll_pay_rules')->where('id', $row->id)->update(['params' => json_encode($params)]);
        }
    }
};
