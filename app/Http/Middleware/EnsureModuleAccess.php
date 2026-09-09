<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Concerns\ScopesToActor;
use App\Support\Access;
use App\Support\ModuleRegistry;
use Closure;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware: the caller must hold ANY of the named modules.
 *
 * The port of `requireModule(...modules)` (src/lib/api/guard.ts:80-90), applied
 * to page navigation the way `requirePageModule()` does
 * (src/app/(protected)/module-access.ts:28-36).
 *
 *     Route::get('/employees', Employees\Index::class)->middleware('module:EMPLOYEES');
 *     // A task is reachable from either module — the list is deliberate:
 *     // "a task moves under PROJECTS for a reviewer and under TASKS for its
 *     // assignee" (guard.ts:80-83).
 *     Route::get('/projects/{project}', Projects\Show::class)->middleware('module:PROJECTS,TASKS');
 *
 * Register the alias in `bootstrap/app.php`:
 *
 *     ->withMiddleware(function (Middleware $middleware): void {
 *         $middleware->alias([
 *             'module'     => \App\Http\Middleware\EnsureModuleAccess::class,
 *             'module.all' => \App\Http\Middleware\EnsureAllData::class,
 *         ]);
 *     })
 *
 * The `Access` it checks is rebuilt from the database on every request
 * ({@see Access} INVARIANT 1), so a revoked grant 403s on the very next call —
 * including the next `/livewire/update` call, which re-runs the component's
 * persisted route middleware. 03-OPEN-ISSUES.md:12 requires that last claim to
 * be PROVEN by a test against a Livewire *action* request rather than a page
 * load: a page-load test certifies nothing, because the action is reachable
 * without one.
 *
 * Not `final`: {@see EnsureAllData} is this middleware with one predicate
 * swapped, and the two must stay in lockstep.
 *
 * THIS IS ONE OF THREE LAYERS, never the only one. A public method on a mounted
 * Livewire component is reachable independently of the route that rendered it,
 * so every write action re-checks for itself, and every scoped read goes
 * through {@see ScopesToActor}.
 */
class EnsureModuleAccess
{
    public function __construct(
        protected readonly Access $access,
        protected readonly ModuleRegistry $registry,
    ) {}

    /**
     * @param  string  ...$codes  ModuleCode values; the caller needs any ONE of them
     */
    public function handle(Request $request, Closure $next, string ...$codes): Response
    {
        if ($codes === []) {
            // A module gate naming no module would permit nothing, silently,
            // and read as "the page is broken".
            throw new InvalidArgumentException(
                'The module middleware needs at least one module code, e.g. middleware(\'module:PAYROLL\').'
            );
        }

        // PHP preserves STRING keys when named arguments are spread into a
        // variadic, so a variadic is `array<int|string, string>`, not a list.
        // `passes()` spreads it back into `hasModule(string $first, string ...$rest)`,
        // where a string key would be read as a named argument and fatal.
        $codes = array_values($codes);

        if ($this->passes($codes)) {
            return $next($request);
        }

        return $this->deny($request, $codes);
    }

    /**
     * @param  list<string>  $codes
     */
    protected function passes(array $codes): bool
    {
        return $this->access->hasModule(...$codes);
    }

    /**
     * A browser is REDIRECTED to whatever it may see; an API caller gets a 403.
     *
     * Livewire is deliberately treated as the browser it is: its JS follows a
     * redirected response with a full page navigation
     * (`if (response.redirected) handlers.redirect(response.url)`), whereas a
     * 403 surfaces as an error overlay. The check must come first — a Livewire
     * update posts `Content-Type: application/json` and would otherwise be
     * mistaken for an API call.
     *
     * @param  list<string>  $codes
     */
    protected function deny(Request $request, array $codes): Response
    {
        if (! $request->hasHeader('X-Livewire') && $request->expectsJson()) {
            abort(403, $this->deniedMessage($codes));
        }

        return redirect($this->registry->landingPath($this->access->modules));
    }

    /**
     * @param  list<string>  $codes
     */
    protected function deniedMessage(array $codes): string
    {
        return 'You do not have access to that.';
    }
}
