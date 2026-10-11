<?php

declare(strict_types=1);

/**
 * Enforcement for the one money rule: plain PHP floats end-to-end, with a single
 * Money::round() applied at the existing call sites and nowhere else.
 *
 * Four mutually incompatible money strategies are possible (BigDecimal at
 * scale 2, plain floats, an integer-centavo value object, and Eloquent
 * 'decimal:2' casts). Three of them round half-away-from-zero and would
 * silently change payslips; the fourth returns a string and reproduces the
 * NaN bug. This test is what keeps the chosen one from being quietly replaced.
 */
$payrollPaths = [
    __DIR__.'/../../app/Domain/Payroll',
    __DIR__.'/../../app/Support',
    // The Payroll module's own pure rules (the PHP 90,000 exempt-benefit
    // ceiling among them) move money too, so the same bans apply there.
    __DIR__.'/../../app/Modules/Payroll/Domain',
];

/**
 * Every PHP file under the given directories, keyed by absolute path, with its
 * raw source as the value. An unreadable file throws rather than yielding
 * `false`: a silently skipped file would let a banned call pass the scans.
 *
 * @param  list<string>  $roots
 * @return array<string, string>
 */
function payrollSources(array $roots): array
{
    $files = [];
    foreach ($roots as $root) {
        if (! is_dir($root)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $src = file_get_contents($file->getPathname());
                if ($src === false) {
                    throw new RuntimeException('Unreadable payroll source: '.$file->getPathname());
                }
                $files[$file->getPathname()] = $src;
            }
        }
    }

    return $files;
}

it('never calls PHP round() inside the payroll domain', function () use ($payrollPaths) {
    $offenders = [];

    foreach (payrollSources($payrollPaths) as $path => $src) {
        // Strip comments so the explanatory prose naming round() does not trip this.
        $stripped = implode('', array_map(
            fn ($t) => is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : (is_array($t) ? $t[1] : $t),
            token_get_all($src)
        ));

        // Money::round's own declaration is the one legitimate `round(` in the
        // codebase, so exempt `function round(` — but nothing else, so that a
        // round($x, 2) smuggled into Money.php's own body still trips this.
        $stripped = preg_replace('/function\s+round\s*\(/i', 'function __declared_round(', $stripped);

        // A bare round( call — not Money::round(, ->round( or $round(.
        if (preg_match('/(?<![\w:>$])round\s*\(/i', (string) $stripped)) {
            $offenders[] = basename($path);
        }
    }

    expect($offenders)->toBe([], 'PHP round() is banned here — it rounds half-away-from-zero and mispays 3.6% of whole-peso salaries. Use Money::round().');
});

it('never uses bcmath, BigDecimal or integer-centavo arithmetic in the payroll domain', function () use ($payrollPaths) {
    $banned = ['bcadd', 'bcsub', 'bcmul', 'bcdiv', 'bccomp', 'BigDecimal', 'BigRational', 'Brick\\Math'];
    $offenders = [];

    foreach (payrollSources($payrollPaths) as $path => $src) {
        foreach ($banned as $needle) {
            if (str_contains($src, $needle)) {
                $offenders[] = basename($path).' → '.$needle;
            }
        }
    }

    expect($offenders)->toBe([], 'These all round half-away-from-zero at scale 2; the engine rounds half-up.');
});

it('never uses the Eloquent decimal cast anywhere', function () {
    $offenders = [];
    $root = __DIR__.'/../../app';

    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
    foreach ($it as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            if (preg_match("/'decimal:\d/", (string) file_get_contents($file->getPathname()))) {
                $offenders[] = basename($file->getPathname());
            }
        }
    }

    expect($offenders)->toBe([], "The 'decimal:N' cast returns a STRING and reproduces the NaN bug respond.ts exists to fix. Use 'float'.");
});
