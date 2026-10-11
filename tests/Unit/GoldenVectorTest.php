<?php

declare(strict_types=1);

use App\Domain\Payroll\Statutory;
use App\Support\Money;

/**
 * Differential test against vectors emitted from the real TypeScript engine.
 *
 * Regenerate with:  bun tools/emit-golden-vectors.ts
 *
 * Comparison is PER FIELD with assertSame, never a whole-object JSON string
 * compare: PHP renders large-magnitude doubles as 1.0e+21 where JS renders
 * 1e+21, so a string-level comparison would fail on formatting rather than on
 * arithmetic and would have to be papered over with a magnitude bound.
 */
const FIELDS = [
    'sss.employee', 'sss.employer',
    'philhealth.employee', 'philhealth.employer',
    'pagibig.employee', 'pagibig.employer',
    'semiMonthlyWithholdingTax',
    'annualIncomeTax',
    'annualizedSemiMonthlyWithholdingTax',
    // The SEMI_MONTHLY halving step from payroll.ts contribution() - where the
    // documented PHP-vs-JS rounding divergence actually reaches a payslip.
    'semi.sss.employee', 'semi.sss.employer',
    'semi.philhealth.employee', 'semi.philhealth.employer',
    'semi.pagibig.employee', 'semi.pagibig.employer',
];

/**
 * One PHP-engine row in FIELDS order — fifteen floats, index-aligned with the
 * expected values that follow the input on each fixture line.
 *
 * @return list<float>
 */
function actualRow(float $input): array
{
    $sss = Statutory::sss($input);
    $ph = Statutory::philhealth($input);
    $pi = Statutory::pagibig($input);

    return [
        $sss['employee'], $sss['employer'],
        $ph['employee'], $ph['employer'],
        $pi['employee'], $pi['employer'],
        Statutory::semiMonthlyWithholdingTax($input),
        Statutory::annualIncomeTax($input),
        Statutory::annualizedSemiMonthlyWithholdingTax($input),
        Statutory::semiMonthlyShare($sss['employee']),
        Statutory::semiMonthlyShare($sss['employer']),
        Statutory::semiMonthlyShare($ph['employee']),
        Statutory::semiMonthlyShare($ph['employer']),
        Statutory::semiMonthlyShare($pi['employee']),
        Statutory::semiMonthlyShare($pi['employer']),
    ];
}

it('matches the TypeScript engine on every golden vector', function () {
    $path = __DIR__.'/../fixtures/golden-statutory.ndjson';
    expect(file_exists($path))->toBeTrue('Fixture missing — run: bun tools/emit-golden-vectors.ts');

    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException("Could not open fixture: {$path}");
    }
    $mismatches = [];
    $count = 0;

    while (($line = fgets($handle)) !== false) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }

        $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        $input = (float) array_shift($row);
        $count++;

        $actual = actualRow($input);

        foreach ($row as $i => $expected) {
            if ($actual[$i] !== (float) $expected) {
                $mismatches[] = sprintf(
                    'input %s  %s  expected %s  got %s',
                    var_export($input, true),
                    FIELDS[$i],
                    var_export((float) $expected, true),
                    var_export($actual[$i], true),
                );
            }
        }
    }
    fclose($handle);

    expect($count)->toBeGreaterThan(50_000, 'Vector corpus too small to be meaningful.');

    if ($mismatches !== []) {
        $shown = array_slice($mismatches, 0, 10);
        $more = count($mismatches) - count($shown);

        $this->fail(sprintf(
            "%d of %d vectors diverge from the TypeScript engine.\n%s%s",
            count($mismatches),
            $count,
            implode("\n", $shown),
            $more > 0 ? "\n... and {$more} more" : '',
        ));
    }

    expect($mismatches)->toBe([]);
});

/**
 * The specific divergence that motivated the whole harness. Named explicitly so
 * that a regression is legible in the test output rather than being one line
 * inside a 102k-vector sweep.
 */
it('yields 128.01 for the semi-monthly PhilHealth share at a 10,241 monthly rate', function () {
    // The canonical divergence. PHP's round(128.015, 2) gives 128.02; the engine
    // gives 128.01, because 128.015 * 100 is 12801.499999999998 in IEEE-754.
    $monthly = Statutory::philhealth(10_241);

    expect($monthly['employee'])->toBe(256.03);
    expect(Statutory::semiMonthlyShare($monthly['employee']))->toBe(128.01);
});

it('reproduces JS Math.round semantics where PHP round() disagrees', function () {
    // round(1.005, 2) is 1.01 in PHP and 1.00 in JS — PHP pre-corrects.
    expect(Money::round(1.005))->toBe(1.0);

    // Math.round(0.49999999999999994) === 0; floor(x + 0.5) would give 1.
    expect(Money::round(0.0049999999999999994))->toBe(0.0);

    // Half-up toward +Infinity on negatives, matching JS. net_pay is unclamped.
    expect(Money::round(-1.005))->toBe(-1.0);
    expect(Money::round(-1.015))->toBe(-1.01);

    // Non-numeric collapses to 0.0 the way Number() coercion does, rather
    // than throwing a TypeError inside the finalize transaction.
    expect(Money::round('nonsense'))->toBe(0.0);
    expect(Money::round(null))->toBe(0.0);
    expect(Money::round(NAN))->toBe(0.0);
    expect(Money::round(INF))->toBe(0.0);
});
