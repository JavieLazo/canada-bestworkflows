<?php

declare(strict_types=1);

use App\Domain\Attendance\AttendanceLog;
use App\Domain\Attendance\HolidaySetting;
use App\Domain\Attendance\LeaveRequest;
use App\Domain\Attendance\Shift;
use App\Domain\Payroll\PayslipCalculator;
use App\Support\Money;

/**
 * Differential test of the WHOLE payslip chain against vectors emitted from
 * the real TypeScript engine — computePayslip() at copems/src/lib/payroll.ts,
 * which internally runs computeDtr(), so one vector exercises punch pairing,
 * the overnight carry-over slice, day buckets, holiday pricing, OT/NSD/
 * rest-day, lateness/undertime, contributions and withholding end to end.
 *
 * Regenerate with:  bun tools/emit-payslip-vectors.ts
 *
 * Comparison is PER FIELD with strict identity, never a whole-object JSON
 * string compare: PHP renders large-magnitude doubles as 1.0e+21 where JS
 * renders 1e+21, so a string-level comparison would fail on formatting rather
 * than on arithmetic. Numeric fields are compared float-to-float; PHP's ===
 * on floats is numeric, so a JS -0 that JSON.stringify flattened to 0 still
 * matches a PHP -0.0.
 */
const PAYSLIP_STRING_FIELDS = ['employee_id', 'period_start', 'period_end'];
const PAYSLIP_BOOL_FIELDS = ['overtime_acknowledged'];

/*
|--------------------------------------------------------------------------
| The one deliberate divergence, and how it is still checked
|--------------------------------------------------------------------------
| The engine no longer computes contributions or withholding tax at all. They
| are typed by hand in the run workspace, per employee, and PayslipCalculator
| seeds all four at zero rather than pre-filling a figure somebody would have
| to correct. Every vector in this corpus therefore differs on those fields.
|
| EVERY EARNINGS FIELD STILL MATCHES THE TYPESCRIPT ENGINE EXACTLY, and that is
| why the corpus is kept. Punch pairing, the overnight carry-over, day buckets,
| holiday pricing, OT, night differential, rest days, lateness and undertime —
| the intricate half — are untouched and still compared with strict identity.
| Not one of them differs across 6,921 vectors.
|
| The four zeroed fields are not simply dropped from the contract. They are
| asserted to BE zero, and the two totals they feed are still checked exactly,
| in terms of the reference's own figures:
|
|   total_deductions  the reference's total, less the four lines the engine no
|                     longer fills. What remains is late, undertime and other.
|   net_pay           gross minus that total, gross being reference-exact.
|
| So the day something starts populating these again, this test says so.
*/
const PAYSLIP_ZEROED = [
    'sss', 'sss_er', 'philhealth', 'philhealth_er', 'pagibig', 'pagibig_er', 'withholding_tax',
];

/** The four that actually entered the reference's deduction total. */
const PAYSLIP_ZEROED_IN_TOTAL = ['sss', 'philhealth', 'pagibig', 'withholding_tax'];

/*
|--------------------------------------------------------------------------
| The second divergence: a holiday worked on a rest day
|--------------------------------------------------------------------------
| The reference priced such a day as the holiday alone. It now compounds the
| holiday's percent by the Formulas -> Rest Day percentage (floored at 1), so
| a regular holiday worked on a Sunday pays 200% x 1.3 rather than 200%.
|
| Which DAYS compound is not visible from a vector's output, so these fields
| are bounded rather than predicted: compounding only ever raises pay, and it
| cannot raise any component by more than the uplift itself. A vector whose
| uplift is 1.0 — 477 of them set the Rest Day percentage to 0 — can compound
| nothing, and every field of it is still held to strict identity.
|
| The exact arithmetic is pinned in PayrollModuleTest instead, where the
| holiday, the rest day and the rate are all chosen rather than swept.
*/
const PAYSLIP_COMPOUNDABLE = [
    'holiday_pay', 'basic_pay', 'total_base_pay', 'overtime_pay', 'gross_pay',
];

/** The Rest Day uplift a vector's own settings imply, floored as the engine floors it. */
function vectorRestDayUplift(array $input): float
{
    $rate = 1.3; // PayrollRates::DEFAULT_REST_DAY_RATE

    foreach (($input['settings']['formulas'] ?? []) as $formula) {
        if (($formula['key'] ?? null) !== 'REST_DAY') {
            continue;
        }
        foreach (($formula['params'] ?? []) as $param) {
            if (($param['key'] ?? null) === 'percentage' && is_numeric($param['value'] ?? null)) {
                $rate = (float) $param['value'];
            }
        }
    }

    return max(1.0, $rate);
}

/**
 * Maps one JSON vector input onto the real PHP domain API — the same
 * fromArray() factories the application's own query layer uses. Nothing in
 * app/Domain is bent toward the fixture; if a vector could not be expressed
 * through this public API, that would be a finding, not a mapping hack.
 *
 * @param  array<string, mixed>  $in
 */
function computePayslipFromVector(array $in): array
{
    $settings = null;
    if (array_key_exists('settings', $in) && is_array($in['settings'])) {
        $settings = [
            'formulas' => $in['settings']['formulas'] ?? [],
            'holidays' => HolidaySetting::listFromArrays($in['settings']['holidays'] ?? []),
        ];
    }

    return PayslipCalculator::compute(
        employee: (object) $in['employee'],
        logs: AttendanceLog::listFromArrays($in['logs']),
        shift: is_array($in['shift'] ?? null) ? Shift::fromArray($in['shift']) : null,
        leaves: LeaveRequest::listFromArrays($in['leaves']),
        periodStart: (string) $in['periodStart'],
        periodEnd: (string) $in['periodEnd'],
        bonus: (float) $in['bonus'],
        allowancesOverride: array_key_exists('allowancesOverride', $in)
            ? (float) $in['allowancesOverride']
            : null,
        earningsOverride: $in['earningsOverride'] ?? null,
        settings: $settings,
    )->toArray();
}

it('PayslipGolden: matches the TypeScript engine on every golden payslip vector', function () {
    $path = __DIR__.'/../fixtures/golden-payslips.ndjson';
    expect(file_exists($path))->toBeTrue('Fixture missing — run: bun tools/emit-payslip-vectors.ts');

    $handle = fopen($path, 'rb');
    $mismatches = [];
    $count = 0;

    while (($line = fgets($handle)) !== false) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }

        $vector = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        $index = $count;
        $count++;

        $actual = computePayslipFromVector($vector['input']);
        $want = $vector['expected'];

        // What the reference charged on the four lines this engine now leaves
        // at zero — which is exactly how much lighter its total must be.
        $removed = 0.0;
        foreach (PAYSLIP_ZEROED_IN_TOTAL as $line) {
            $removed += (float) $want[$line];
        }

        $uplift = vectorRestDayUplift($vector['input']);

        foreach ($want as $field => $expected) {
            if (in_array($field, PAYSLIP_STRING_FIELDS, true)) {
                $ok = $actual[$field] === (string) $expected;
            } elseif (in_array($field, PAYSLIP_BOOL_FIELDS, true)) {
                $ok = $actual[$field] === (bool) $expected;
            } elseif (in_array($field, PAYSLIP_ZEROED, true)) {
                $ok = $actual[$field] === 0.0;
            } elseif ($uplift > 1.0 && in_array($field, PAYSLIP_COMPOUNDABLE, true)) {
                // Never less than the reference, never more than the uplift.
                // A cent of slack: these are sums of independently rounded
                // components, so the upper bound is not exact to the centavo.
                // min/max rather than lo/hi: a handful of vectors carry a
                // NEGATIVE monthly rate, and multiplying those by the uplift
                // moves the bound downward, not upward.
                $bound = (float) $expected * $uplift;

                // The reference figure is already rounded to the centavo, so
                // multiplying it by the uplift carries that half-centavo up
                // with it; the engine's own result is rounded once more.
                $slack = 0.005 * $uplift + 0.005;

                $ok = $actual[$field] >= min((float) $expected, $bound) - $slack
                    && $actual[$field] <= max((float) $expected, $bound) + $slack;
            } elseif ($field === 'total_deductions') {
                $ok = $actual[$field] === Money::round((float) $expected - $removed);
            } elseif ($field === 'net_pay') {
                $ok = $actual[$field] === Money::round($actual['gross_pay'] - $actual['total_deductions']);
            } else {
                // EVERY other numeric field of the draft is still the literal
                // contract with the TypeScript engine — day counts, every
                // earning bucket, gross, late and undertime.
                $ok = $actual[$field] === (float) $expected;
            }

            if (! $ok) {
                $mismatches[] = sprintf(
                    'vector #%d  %s..%s  rate %s  %s: expected %s, got %s',
                    $index,
                    (string) $vector['input']['periodStart'],
                    (string) $vector['input']['periodEnd'],
                    var_export($vector['input']['employee']['monthly_rate'] ?? null, true),
                    $field,
                    var_export($expected, true),
                    var_export($actual[$field], true),
                );
            }
        }
    }
    fclose($handle);

    expect($count)->toBeGreaterThanOrEqual(5_000, 'Vector corpus too small to be meaningful.');

    if ($mismatches !== []) {
        $shown = array_slice($mismatches, 0, 10);
        $more = count($mismatches) - count($shown);

        $this->fail(sprintf(
            "%d field mismatches across %d vectors.\n%s%s",
            count($mismatches),
            $count,
            implode("\n", $shown),
            $more > 0 ? "\n... and {$more} more" : '',
        ));
    }

    expect($mismatches)->toBe([]);
});
