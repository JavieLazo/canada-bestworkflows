<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/** Fingerprints the installed calculation and input-mapping code, including default rules. */
final class CalculatorVersion
{
    public static function current(): string
    {
        $hash = hash_init('sha256');
        foreach (self::files() as $file) {
            $digest = hash_file('sha256', $file);
            if ($digest === false) {
                throw new RuntimeException('The payroll calculation version could not be verified.');
            }
            hash_update($hash, str_replace(base_path().'/', '', $file).':'.$digest.';');
        }

        return 'payroll-v1:'.hash_final($hash);
    }

    /**
     * Every file whose bytes the version covers, sorted by path.
     *
     * The shared engines (app/Domain/Payroll, app/Domain/Attendance), Money
     * and ManilaClock, composer.lock, the input mapping (PayrollData,
     * PayrollInputs, and the 13th month run's ThirteenthMonthData and
     * ThirteenthMonthInputs) and every rule under app/Modules/Payroll/Domain,
     * at any depth (the PHP 90,000 exempt-benefit ceiling lives there). A
     * change to any of them stales every outstanding preview and makes
     * calculateInputs() refuse evidence captured before it.
     *
     * @return list<string>
     */
    public static function files(): array
    {
        $files = [
            ...(glob(base_path('app/Domain/Payroll/*.php')) ?: []),
            ...(glob(base_path('app/Domain/Attendance/*.php')) ?: []),
            ...self::phpFilesUnder(base_path('app/Modules/Payroll/Domain')),
            base_path('app/Support/Money.php'),
            base_path('app/Support/ManilaClock.php'),
            base_path('composer.lock'),
            __DIR__.'/PayrollData.php',
            __DIR__.'/PayrollInputs.php',
            // The 13th month run's input mapping and compute path.
            __DIR__.'/ThirteenthMonthData.php',
            __DIR__.'/ThirteenthMonthInputs.php',
        ];
        sort($files, SORT_STRING);

        return $files;
    }

    /** @return list<string> */
    private static function phpFilesUnder(string $root): array
    {
        if (! is_dir($root)) {
            return [];
        }

        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
