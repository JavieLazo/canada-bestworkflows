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
     * Every module, in `config/modules.php` order — which is the spec.
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
     * `/` for a role granted nothing — the sign-in page.
     *
     * @param  list<string>  $granted
     */
    public function landingPath(array $granted): string
    {
        foreach ($this->all() as $module) {
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
            $this->all(),
            static fn (array $module): bool => in_array($module['code'], $granted, true),
        ));
    }

    /**
     * What the sidebar's "Modules" group draws: the granted modules MINUS
     * PROFILE, which "is reached from the identity block in the footer, so it
     * would only be a second entry to the same page here"
     * (AppSidebar.tsx:124-126).
     *
     * @param  list<string>  $granted
     * @return list<array<string, mixed>>
     */
    public function navFor(array $granted): array
    {
        return array_values(array_filter(
            $this->grantedFor($granted),
            static fn (array $module): bool => $module['code'] !== ModuleCode::PROFILE,
        ));
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
