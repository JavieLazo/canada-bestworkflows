<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\Enums\ModuleCode;
use App\Http\Middleware\EnsureModuleAccess;

/**
 * Reads `config/modules.php`. The port of the four helpers around `MODULES`:
 * `modulePath()`, `landingPath()` and `moduleLabel()` (src/lib/hris.ts:124-139),
 * plus `moduleForPath()` (src/app/(protected)/module-access.ts:7-22) and the
 * nav filtering the sidebar does inline (src/components/AppSidebar.tsx:123-126).
 *
 * Stateless and side-effect free, so `app(ModuleRegistry::class)` resolves it
 * without a binding. Registering it as a `singleton()` saves nothing but an
 * object allocation — the config is already loaded once per request. It is a
 * class rather than free functions only so it can be injected and faked.
 *
 * NOTHING HERE IS A PERMISSION CHECK. `navFor()` decides what is *drawn*;
 * {@see EnsureModuleAccess} decides what is *reachable*.
 * The source is explicit that hiding a module from the sidebar was the whole of
 * the enforcement in the demo store, and that this was the bug
 * (src/lib/api/guard.ts:4-7).
 */
final class ModuleRegistry
{
    /**
     * The modules no installation can turn off: without User Management
     * nobody could give a role access again, and My Profile is where an
     * account changes its own password and sign-in settings.
     *
     * @var list<string>
     */
    public const array ALWAYS_ON = [ModuleCode::USERS, ModuleCode::PROFILE];

    /**
     * Every module, in `config/modules.php` order — which is the spec. A
     * module turned off for the installation is still listed here: `off()`
     * says which those are, and `enabled()` leaves them out.
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        /** @var list<array<string, mixed>> $modules */
        $modules = config('modules', []);

        return $modules;
    }

    /**
     * The module codes turned off for the whole installation
     * (`copems.modules_off`, a comma-separated list), in `ModuleCode` order.
     * THE ONE PLACE THE SETTING IS READ: `Access` drops these from every
     * actor's grants, so the route gate, every action's own check, the
     * sidebar, the landing page and the reminders all treat an off module as
     * one the role does not hold; User Management's roles table leaves it
     * out. The stored grants are never touched, so turning a module back on
     * restores them. A code that is not a module is ignored, and the
     * `ALWAYS_ON` modules cannot be turned off.
     *
     * @return list<string>
     */
    public function off(): array
    {
        $setting = config('copems.modules_off', '');
        $named = is_array($setting) ? $setting : explode(',', is_string($setting) ? $setting : '');
        $wanted = [];
        foreach ($named as $code) {
            if (is_string($code)) {
                $wanted[strtoupper(trim($code))] = true;
            }
        }

        return array_values(array_filter(
            ModuleCode::all(),
            static fn (string $code): bool => isset($wanted[$code]) && ! in_array($code, self::ALWAYS_ON, true),
        ));
    }

    /** Whether a module is turned off for the whole installation. */
    public function isOff(string $code): bool
    {
        return in_array($code, $this->off(), true);
    }

    /**
     * Every module the installation runs, in registry order: `all()` without
     * the ones turned off.
     *
     * @return list<array<string, mixed>>
     */
    public function enabled(): array
    {
        $off = $this->off();

        return $off === [] ? $this->all() : array_values(array_filter(
            $this->all(),
            static fn (array $module): bool => ! in_array($module['code'], $off, true),
        ));
    }

    /**
     * A role's grants without the modules turned off — what the role holds
     * as far as every permission check is concerned.
     *
     * @param  list<string>  $granted
     * @return list<string>
     */
    public function withoutOff(array $granted): array
    {
        $off = $this->off();

        return $off === [] ? $granted : array_values(array_filter(
            $granted,
            static fn (string $code): bool => ! in_array($code, $off, true),
        ));
    }

    /**
     * One module by code, or null.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $code): ?array
    {
        foreach ($this->all() as $module) {
            if ($module['code'] === $code) {
                return $module;
            }
        }

        return null;
    }

    /**
     * Where a module's sidebar entry points.
     *
     * Falls back to `/portal` for an unknown code, exactly as
     * `modulePath()` does (hris.ts:124-126) — note this differs from
     * `landingPath()`, which falls back to `/`.
     */
    public function modulePath(string $code): string
    {
        $module = $this->find($code);

        return isset($module['path']) ? (string) $module['path'] : '/portal';
    }

    /** A module code as something to read; unknown codes echo back (hris.ts:136-138). */
    public function moduleLabel(string $code): string
    {
        $module = $this->find($code);

        return isset($module['label']) ? (string) $module['label'] : $code;
    }

    /**
     * Where a role lands after sign-in: "the first module it holds *in MODULES
     * order*, not in the order the grants happen to come back from the store"
     * (hris.ts:127-134).
     *
     * `/` for a role granted nothing — the sign-in page. A module turned off
     * is never a landing page, whatever the grants passed in say.
     *
     * @param  list<string>  $granted
     */
    public function landingPath(array $granted): string
    {
        foreach ($this->enabled() as $module) {
            if (in_array($module['code'], $granted, true)) {
                return (string) $module['path'];
            }
        }

        return '/';
    }

    /**
     * The granted modules in registry order — the port of
     * `MODULES.filter(m => me.modules.includes(m.code))`
     * (protected-shell.tsx:25). Includes PROFILE, because the sidebar footer
     * needs to know whether the role still holds it (AppSidebar.tsx:123).
     *
     * @param  list<string>  $granted
     * @return list<array<string, mixed>>
     */
    public function grantedFor(array $granted): array
    {
        return array_values(array_filter(
            $this->enabled(),
            static fn (array $module): bool => in_array($module['code'], $granted, true),
        ));
    }

    /**
     * What the sidebar's "Modules" group draws: the granted modules MINUS
     * PROFILE, which "is reached from the identity block in the footer, so it
     * would only be a second entry to the same page here"
     * (AppSidebar.tsx:124-126).
     *
     * A group naming a `grant` is drawn for a holder of THAT grant, whether or
     * not they hold the tree's own module, and for nobody else. So a tree can
     * appear for a role that does not hold its module, carrying only the
     * entries the role does hold.
     *
     * A module turned off is never drawn, and a group whose `grant` names
     * one is not either: the grants are read without the off modules.
     *
     * @param  list<string>  $granted
     * @return list<array<string, mixed>>
     */
    public function navFor(array $granted): array
    {
        $nav = [];
        $granted = $this->withoutOff($granted);

        foreach ($this->enabled() as $module) {
            if ($module['code'] === ModuleCode::PROFILE) {
                continue;
            }

            $held = in_array($module['code'], $granted, true);

            if (isset($module['groups'])) {
                /** @var list<array<string, mixed>> $groups */
                $groups = $module['groups'];
                $module['groups'] = array_values(array_filter(
                    $groups,
                    static fn (array $group): bool => isset($group['grant'])
                        ? in_array($group['grant'], $granted, true)
                        : $held,
                ));
                $held = $held || $module['groups'] !== [];
            }

            if ($held) {
                $nav[] = $module;
            }
        }

        return $nav;
    }

    /**
     * Which module a URI belongs to, or null for a path outside every module.
     *
     * The port of `moduleForPath()` (module-access.ts:16-22): the FIRST module
     * in registry order one of whose paths the URI equals or sits underneath.
     * A module's paths are its own, every path in its `groups` — both the leaf
     * `path` shape and the folder `items` shape — and, for RECORDS only, the
     * bare `/records` (module-access.ts:13), which is a real prefix with no
     * page of its own.
     */
    public function forPath(string $uri): ?string
    {
        $uri = self::normalise($uri);

        foreach ($this->all() as $module) {
            foreach ($this->pathsOf($module) as $path) {
                if ($uri === $path || str_starts_with($uri, $path.'/')) {
                    return (string) $module['code'];
                }
            }
        }

        return null;
    }

    /**
     * Every path that belongs to a module.
     *
     * @param  array<string, mixed>  $module
     * @return list<string>
     */
    public function pathsOf(array $module): array
    {
        $paths = [(string) $module['path']];

        /** @var list<array<string, mixed>> $groups */
        $groups = $module['groups'] ?? [];

        foreach ($groups as $group) {
            // A group is EITHER a page of its own (Holiday Settings) OR a
            // folder of pages (General Records). Both shapes are live in
            // config/modules.php; assuming `items` throws on the first one.
            if (isset($group['path'])) {
                $paths[] = (string) $group['path'];
            }

            /** @var list<array<string, mixed>> $items */
            $items = $group['items'] ?? [];

            foreach ($items as $item) {
                $paths[] = (string) $item['path'];
            }
        }

        if ($module['code'] === ModuleCode::RECORDS) {
            $paths[] = '/records';
        }

        return $paths;
    }

    /**
     * `employees/12` and `/employees/12/` both become `/employees/12`.
     *
     * `Request::path()` returns no leading slash and `/` for the root, so both
     * ends need fixing before a prefix comparison means anything.
     */
    private static function normalise(string $uri): string
    {
        return '/'.trim(parse_url($uri, PHP_URL_PATH) ?: $uri, '/');
    }
}
