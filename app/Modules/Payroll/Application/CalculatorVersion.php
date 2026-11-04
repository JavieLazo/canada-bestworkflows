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
    /**
     * The installed version, hashed once per request: the files cannot
     * change while a request runs, and every preview asks for it at least
     * twice (the capture and the pricing gate).
     */
    public static function current(): string
    {
        return once(static function (): string {
            $hash = hash_init('sha256');
            foreach (self::files() as $file) {
                $digest = hash_file('sha256', $file);
                if ($digest === false) {
                    throw new RuntimeException('The payroll calculation version could not be verified.');
                }
                hash_update($hash, str_replace(str_replace(DIRECTORY_SEPARATOR, '/', base_path()).'/', '', $file).':'.$digest.';');
            }

            return 'payroll-v1:'.hash_final($hash);
        });
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
            // The leave-credit charge plan prices lateness and absences (DIVERGENCE 15).
            ...self::phpFilesUnder(base_path('app/Modules/Timekeeping/Domain/Credits')),
            base_path('app/Support/Money.php'),
            base_path('app/Support/ManilaClock.php'),
            base_path('composer.lock'),
            __DIR__.'/PayrollData.php',
            __DIR__.'/PayrollInputs.php',
            // The 13th month run's input mapping and compute path.
            __DIR__.'/ThirteenthMonthData.php',
            __DIR__.'/ThirteenthMonthInputs.php',
        ];
        // Forward slashes before sorting, so a Windows checkout fingerprints
        // the same files in the same order as Linux.
        $files = array_map(static fn (string $file): string => str_replace(DIRECTORY_SEPARATOR, '/', $file), $files);
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
