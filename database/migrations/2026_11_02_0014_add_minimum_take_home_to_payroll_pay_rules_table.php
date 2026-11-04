<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DATA — one new Pay Basis & Rates figure, BLANK, on every stored
 * version's `params` (`payroll_pay_rules`): `take_home.minimum`, the net
 * pay a payslip should not fall below (PayRules). Blank means no floor,
 * so NO MONEY MOVES and nothing is warned about; the key is written so
 * every saved version carries the shape the engine writes. A "follows"
 * row (NULL `params`) is left NULL.
 *
 * RETRY-SAFE: a version that already carries the key keeps its value;
 * only a missing key is added.
 *
 * ROLLBACK LOSES NOTHING that prices a payslip: the key is removed, and a
 * version read without it is read as blank. A minimum someone set IS lost
 * on rollback.
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
            if (! is_array($params) || array_key_exists('take_home', $params)) {
                continue;
            }
            $params['take_home'] = ['minimum' => null];
            DB::table('payroll_pay_rules')->where('id', $row->id)->update(['params' => json_encode($params)]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('payroll_pay_rules')) {
            return;
        }
        foreach (DB::table('payroll_pay_rules')->whereNotNull('params')->get(['id', 'params']) as $row) {
            $params = json_decode((string) $row->params, true);
            if (! is_array($params) || ! array_key_exists('take_home', $params)) {
                continue;
            }
            unset($params['take_home']);
            DB::table('payroll_pay_rules')->where('id', $row->id)->update(['params' => json_encode($params)]);
        }
    }
};
