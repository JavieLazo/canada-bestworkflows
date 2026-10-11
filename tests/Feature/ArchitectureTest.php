<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use Livewire\Component;

/**
 * Structural guards that no behavioural test can express: every class a Blade
 * view imports still exists, module folders hold only screens, and the several
 * copies of the module registry agree with each other.
 *
 * Why these three, here:
 *
 *  - PHPStan analyses app/, config/, database/ and routes/ — never
 *    resources/views. A `use App\…;` inside a Blade `@php` block, an
 *    `@use('App\…')` directive or an inline `\App\…::` call therefore fails only
 *    at RENDER time, on the one screen that imports the moved class, and only a
 *    test that happens to render that screen would notice. That is the whole
 *    hazard of moving or deleting a helper class in this codebase, and it is
 *    invisible to `composer test` without this file.
 *
 *  - AGENTS.md says a module exists only when several places agree —
 *    `ModuleCode::all()`, `config/modules.php`, the `rmg_module_chk` CHECK
 *    migration, `routes/modules/<module>.php` and the sidebar's icon switch —
 *    and that ORDER is behaviour: sidebar order, the landing page after
 *    sign-in and path resolution all follow it. Nothing else tested that any
 *    two of those copies agree, so a `sort()` on the list would have shipped.
 *
 *  - Module helpers belong in `<Module>/Support/`, so that a module folder
 *    reads as a list of screens. The root-level helpers that predate that rule
 *    are grandfathered BY NAME below; a new one fails, and a grandfathered one
 *    that moves must be removed from the list, which the test also enforces.
 */

/* |---| Blade imports |---| */

/**
 * Every Blade file under resources/views, keyed by path.
 *
 * @return array<string, string>
 */
function archViewSources(): array
{
    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views')));
    foreach ($it as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
            $files[$file->getPathname()] = (string) file_get_contents($file->getPathname());
        }
    }
    ksort($files);

    return $files;
}

/**
 * The class names a Blade file refers to by name, in the three spellings the
 * views use: `@use('A\B')` (with or without an alias), a plain `use A\B;` or
 * `use A\B as C;` inside a `@php` block, and an inline `\App\…` FQCN. Only
 * qualified names (containing a backslash) count; a bare `use Closure;` is a
 * language feature, not a dependency on this codebase.
 *
 * @return list<string>
 */
function archImportedNames(string $src): array
{
    $names = [];

    preg_match_all("/@use\\(\\s*'([A-Za-z0-9_\\\\]+)'/", $src, $m);
    array_push($names, ...$m[1]);

    preg_match_all('/^\s*use\s+([A-Za-z0-9_\\\\]+)(?:\s+as\s+\w+)?\s*;/m', $src, $m);
    array_push($names, ...$m[1]);

    preg_match_all('/\\\\(App(?:\\\\[A-Za-z0-9_]+)+)/', $src, $m);
    array_push($names, ...$m[1]);

    $names = array_filter($names, static fn (string $n): bool => str_contains($n, '\\'));

    return array_values(array_unique($names));
}

it('imports only classes that exist from every Blade view', function () {
    $missing = [];
    $found = 0;

    foreach (archViewSources() as $path => $src) {
        foreach (archImportedNames($src) as $name) {
            $found++;
            if (class_exists($name) || interface_exists($name) || trait_exists($name) || enum_exists($name)) {
                continue;
            }
            $missing[] = str_replace(resource_path('views').'/', '', $path).' → '.$name;
        }
    }

    expect($missing)->toBe([], 'A Blade view imports a class that no longer exists. PHPStan never scans views, so this is the only gate before a render-time fatal.');

    // The count observed on a green run: 142 qualified names across the
    // views, over all three spellings, deduplicated per file. If this drops,
    // either a view lost an import — fine, lower it deliberately — or the
    // regexes above have stopped matching a spelling the views use, and the
    // guard is silently scanning less than it did. Never adjust it just to
    // make the test pass.
    expect($found)->toBeGreaterThanOrEqual(
        142,
        "Found {$found} Blade class imports, below the pinned floor of 142 — a view lost an import, or the scanner is missing a spelling.",
    );
});

/* |---| Module folders |---| */

it('keeps module folders to Livewire screens, with helpers under Support/', function () {
    // Folders under app/Livewire that are not modules: cross-module traits, the
    // kit widgets, the shell chrome, the ungated inbox (`routes/modules/
    // notifications.php` explains why it has no module code), and the shell's
    // invokable actions (Actions\Logout, behind the confirmed "Sign out").
    $notModules = ['Actions', 'Concerns', 'Kit', 'Shell', 'Notifications'];

    // Root-level helpers that predate the Support/ rule. Grandfathered by name
    // so that a NEW root-level helper fails; when one of these moves, delete
    // its entry here, or the test fails on the stale name.
    $grandfathered = [
        'Employees\\EmployeeFields',
        'Leaves\\LeaveLabels',
        'Projects\\ProjectsData',
        'Roster\\Hris',
        'Roster\\ShiftPolicy',
    ];

    $root = app_path('Livewire');
    $offenders = [];
    $seen = [];

    foreach (new DirectoryIterator($root) as $dir) {
        if ($dir->isDot() || ! $dir->isDir() || in_array($dir->getFilename(), $notModules, true)) {
            continue;
        }
        foreach (new DirectoryIterator($dir->getPathname()) as $file) {
            // Subfolders (Support/, a module's own Concerns/) are exactly where
            // non-screens belong, so only files directly in the module root count.
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $short = $dir->getFilename().'\\'.$file->getBasename('.php');
            $seen[] = $short;
            if (in_array($short, $grandfathered, true)) {
                continue;
            }
            if (! is_subclass_of('App\\Livewire\\'.$short, Component::class)) {
                $offenders[] = $short;
            }
        }
    }

    expect($offenders)->toBe([], 'A non-component class sits directly in a module folder. Helpers go in <Module>/Support/.');

    $stale = array_values(array_diff($grandfathered, $seen));
    expect($stale)->toBe([], 'A grandfathered helper no longer exists at the module root — remove it from the list so the rule tightens.');
});

/* |---| Module registry |---| */

it('keeps every copy of the module registry in agreement', function () {
    $modules = config('modules');
    $codes = array_column($modules, 'code');

    // Order is the spec: sidebar order, the post-login landing page and
    // ModuleRegistry::forPath() all walk this list in order.
    expect($codes)->toBe(ModuleCode::all(), 'config/modules.php must list exactly ModuleCode::all(), in the same order. Never sort it.');

    // The sidebar icon switch has no @default, so a configured icon without a
    // case renders an empty <svg> with no error anywhere.
    $switch = (string) file_get_contents(resource_path('views/layouts/app/module-icon.blade.php'));
    preg_match_all("/@case\\('([^']+)'\\)/", $switch, $m);
    $cases = $m[1];
    $noIcon = array_values(array_diff(array_unique(array_column($modules, 'icon')), $cases));
    expect($noIcon)->toBe([], 'A module icon in config/modules.php has no @case in layouts/app/module-icon.blade.php.');

    // One route file per module, loaded by glob from routes/web.php.
    $noRoutes = array_values(array_filter(
        $codes,
        static fn (string $code): bool => ! is_file(base_path('routes/modules/'.strtolower($code).'.php')),
    ));
    expect($noRoutes)->toBe([], 'A module code has no routes/modules/<module>.php.');

    // The newest migration that re-issues the CHECK must name every code. The
    // constraint is only created on mysql/mariadb, so sqlite tests never see it
    // and this scan is the only thing that keeps the literal list current.
    $checkMigrations = array_values(array_filter(
        glob(database_path('migrations/*.php')) ?: [],
        static fn (string $f): bool => str_contains((string) file_get_contents($f), 'rmg_module_chk'),
    ));
    sort($checkMigrations);
    expect($checkMigrations)->not->toBe([], 'No migration issues rmg_module_chk.');
    $latest = (string) file_get_contents((string) end($checkMigrations));
    $notInCheck = array_values(array_filter(
        $codes,
        static fn (string $code): bool => ! str_contains($latest, "'{$code}'"),
    ));
    expect($notInCheck)->toBe([], 'A module code is missing from the newest rmg_module_chk migration ('.basename((string) end($checkMigrations)).'). Add a NEW migration that re-issues the CHECK with the full list.');
});
