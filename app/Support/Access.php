<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\Enums\ModuleCode;
use App\Http\Middleware\EnsureModuleAccess;
use App\Models\Concerns\ScopesToActor;
use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use InvalidArgumentException;
use LogicException;

/**
 * Who is asking, and what they are allowed to touch — the port of the `Session`
 * type and the `require*` / `mayReadAllData` helpers in `src/lib/api/guard.ts`.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * INVARIANT 1 — GRANTS ARE READ FROM THE DATABASE ON EVERY REQUEST.
 * ─────────────────────────────────────────────────────────────────────────────
 * The source states it twice, in `session.ts:3-9` and `guard.ts:9-13`:
 *
 *   "The token carries **only the account id**. Role, `all_data` and the module
 *    grants are read from the database on every request instead, which is the
 *    difference between a revoked grant taking effect now and taking effect
 *    whenever the token happens to expire."
 *
 * So this object is built ONCE PER REQUEST and never cached beyond it. Bind it
 * `scoped()` — not `singleton()`, which would survive into the next request
 * under Octane, and never through the cache:
 *
 *     // bootstrap/app.php
 *     $this->app->scoped(Access::class, fn () => Access::forActor(Auth::user()));
 *
 * This is precisely why `spatie/laravel-permission` is banned from this port:
 * it caches the permission map for 24 hours, so a revoked grant would keep
 * working for a day. Revocation here takes effect on the next request.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * INVARIANT 2 — SEC-01: `all_data` IS NEVER SUFFICIENT ALONE.
 * ─────────────────────────────────────────────────────────────────────────────
 * `all_data` widens a read from "my rows" to "every row"; it does not say WHICH
 * data. A Supervisor holds it so they can see the roster — that must not also
 * hand them everyone's salary. So the widening always takes the flag AND a
 * module that is *relevant to the thing being read* (guard.ts:97-99, and the
 * comment on `api/payroll/payslips/route.ts:19-21`: "all_data alone … is not a
 * license to read salaries").
 *
 * {@see self::mayReadAllData()} therefore has NO zero-argument overload. A
 * caller physically cannot ask "does this user have all_data" without naming
 * the module the widening is relevant to. Do not add one.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * INVARIANT 3 — LIVEWIRE PUBLIC PROPERTIES ARE CLIENT-WRITABLE. USE #[Locked].
 * ─────────────────────────────────────────────────────────────────────────────
 * READ THIS BEFORE WRITING ANY LIVEWIRE COMPONENT. It is a vulnerability class
 * the JSON-body reference did not have, and 03-OPEN-ISSUES.md:11 calls it "the
 * single most likely way the RBAC scoping gets bypassed".
 *
 * Livewire serialises every public property into the snapshot embedded in the
 * DOM and posts it back on every interaction. The browser can edit any of them.
 * `public string $employeeId`, `public string $runId`, `public array $filters`
 * and `#[Url] public int $perPage` are ATTACKER-CONTROLLED BY DEFAULT.
 *
 *     use Livewire\Attributes\Locked;
 *
 *     #[Locked] public string $runId;        // ← an id: ALWAYS Locked
 *     #[Locked] public ?string $employeeId;  // ← authorization-relevant: ALWAYS Locked
 *     public string $search = '';            // ← free text the user may edit: fine
 *
 * The rule, with no exceptions:
 *
 *   a. EVERY id-bearing public property is `#[Locked]` — anything ending in
 *      `Id`, any model bound by key, any array of ids.
 *   b. EVERY authorization-relevant public property is `#[Locked]` — anything a
 *      guard, a policy, an `authorize()` call or a query scope reads.
 *   c. NEVER take the actor from a public property. The actor is THIS object,
 *      resolved from the container, which is built from the session cookie and
 *      the database. A component that reads `$this->employeeId` to decide what
 *      it may show has already lost.
 *   d. Route middleware is not enough. `/livewire/update` re-runs the
 *      component's persisted middleware, but a public method on a mounted
 *      component is reachable independently of the route that rendered it, so
 *      every write action calls `authorize()` / {@see self::hasModule()} again
 *      for itself. Three layers, always: route middleware, an in-action check,
 *      and {@see ScopesToActor} for row-level narrowing.
 *
 * @see ScopesToActor  the row-level half of this
 * @see EnsureModuleAccess  the route-level half
 */
final readonly class Access
{
    /** Every row in the table: the actor holds `all_data` AND a relevant module. */
    public const string ROWS_ALL = 'ALL';

    /** Only rows belonging to the actor's own linked employee record. */
    public const string ROWS_OWN = 'OWN';

    /**
     * NO ROWS AT ALL. The port of the source's `?? "__none__"` sentinel: an
     * account with no linked employee record matches nothing, rather than
     * matching everything or matching `IS NULL`.
     */
    public const string ROWS_NONE = 'NONE';

    /**
     * @param  list<string>  $modules  ModuleCode values, straight from `role_module_grants`
     */
    public function __construct(
        public ?string $userId,
        public ?string $employeeId,
        public string $fullName,
        public string $roleName,
        public bool $allData,
        public array $modules,
        public ?string $roleId = null,
        public string $email = '',
    ) {}

    /**
     * Nobody. Signed out, or an account the session could not resolve.
     *
     * `'System'` / `'Unassigned'` match the fallbacks the source uses for an
     * account with no role (guard.ts:66-69).
     */
    public static function forGuest(): self
    {
        return new self(null, null, 'System', 'Unassigned', false, []);
    }

    /**
     * The container factory. Anything that is not a signed-in `User` — null, or
     * some other guard's Authenticatable — is a guest, which fails closed.
     */
    public static function forActor(?Authenticatable $user): self
    {
        return $user instanceof User ? self::forUser($user) : self::forGuest();
    }

    /**
     * One query per request: `role` with its `grants`, eager-loaded together.
     *
     * NOTE the deactivation rule this does NOT implement, because it belongs to
     * the middleware that runs before it: "Returns null for a deactivated
     * account too — a user disabled mid-session is signed out at their next
     * request rather than keeping access until the token lapses"
     * (guard.ts:47-51). Laravel's session guard does not re-check `is_active`,
     * so `EnsureAccountActive` does it. This class trusts that it ran.
     */
    public static function forUser(User $user): self
    {
        /** @var Role|null $role */
        $role = $user->relationLoaded('role')
            ? $user->getRelationValue('role')
            : $user->loadMissing('role.grants')->getRelationValue('role');

        $modules = [];

        if ($role !== null) {
            foreach ($role->grants as $grant) {
                $modules[] = (string) $grant->module;
            }
        }

        return new self(
            userId: (string) $user->getKey(),
            // Nullable on purpose, and nullable all the way down: an account
            // exists before it is linked to an employee record. Read
            // `rowVisibility()` before you read this.
            employeeId: self::stringOrNull($user->getAttribute('employee_id')),
            fullName: $user->name,
            roleName: $role->name ?? 'Unassigned',
            allData: (bool) ($role->all_data ?? false),
            modules: $modules,
            roleId: self::stringOrNull($user->getAttribute('role_id')),
            email: (string) ($user->getAttribute('email') ?? ''),
        );
    }

    /** Whether anyone at all is behind this request. */
    public function isSignedIn(): bool
    {
        return $this->userId !== null;
    }

    /**
     * Whether the account is linked to an employee record. An account without
     * one owns no rows anywhere — see {@see self::rowVisibility()}.
     */
    public function hasEmployeeLink(): bool
    {
        return $this->employeeId !== null;
    }

    /**
     * Holds ANY of the given modules. A list, not a single code, because
     * "several operations are reachable from two screens — a task moves under
     * PROJECTS for a reviewer and under TASKS for its assignee" (guard.ts:80-83).
     *
     * At least one module is required by the signature: an empty call would be
     * a permission check that permits nothing, silently.
     */
    public function hasModule(string $first, string ...$rest): bool
    {
        // `array_values()`: a variadic keeps STRING keys when it is filled by
        // spreading named arguments, and unpacking those into an array literal
        // would carry them through as string keys rather than appending.
        foreach (self::assertKnown([$first, ...array_values($rest)]) as $code) {
            if (in_array($code, $this->modules, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * SEC-01. Organisation-wide reads need BOTH the role scope and a relevant
     * module (guard.ts:102-103).
     *
     * There is deliberately no zero-argument overload — see INVARIANT 2 in the
     * class docblock. Adding one re-opens the "a supervisor can read every
     * salary" hole this method exists to close.
     */
    public function mayReadAllData(string $first, string ...$rest): bool
    {
        return $this->allData && $this->hasModule($first, ...$rest);
    }

    /**
     * How much of a table this actor may see: {@see self::ROWS_ALL},
     * {@see self::ROWS_OWN} or {@see self::ROWS_NONE}.
     *
     * The three states are exhaustive and mutually exclusive, which is what
     * makes the leak in `where($column, null)` unconstructible: a caller
     * `match`es on this and never inspects `$employeeId` to decide. There is no
     * fourth answer, and no "it depends" answer, so there is no path on which a
     * null id reaches a WHERE clause.
     *
     * Order matters. `all_data` + a relevant module widens FIRST, so an admin
     * with no employee link still reads everything; only then does a missing
     * link collapse to nothing. A freshly bootstrapped admin who holds neither
     * gets ROWS_NONE — an empty list, never the whole table and never every
     * unassigned row.
     *
     * The narrowed return type is what lets a caller's `match` cover every case
     * without a default arm: a fourth state added here becomes a static error at
     * every call site, rather than an `\UnhandledMatchError` in production.
     *
     * @return self::ROWS_ALL|self::ROWS_OWN|self::ROWS_NONE
     */
    public function rowVisibility(string $first, string ...$rest): string
    {
        if ($this->mayReadAllData($first, ...$rest)) {
            return self::ROWS_ALL;
        }

        if ($this->employeeId === null) {
            return self::ROWS_NONE;
        }

        return self::ROWS_OWN;
    }

    /**
     * The actor's employee id, guaranteed non-null by the return type.
     *
     * This is the ONLY way a row-scoping query is allowed to obtain the id, and
     * the reason is the return type rather than the exception: a scope written
     * against this method cannot compile a `WHERE employee_id IS NULL`, because
     * there is no value it could return that would produce one. Callers reach
     * it only through the ROWS_OWN arm of a `match` on
     * {@see self::rowVisibility()}, which has already excluded null.
     *
     * The throw is therefore unreachable by construction. It is here so that a
     * future caller who skips the `match` fails loudly and immediately instead
     * of silently widening a query.
     *
     * @throws LogicException always, for an account with no employee link
     */
    public function ownEmployeeId(): string
    {
        if ($this->employeeId === null) {
            throw new LogicException(
                'Access::ownEmployeeId() was called for an account with no linked employee record. '
                .'Row scoping must go through Access::rowVisibility(), whose ROWS_NONE arm covers this case.'
            );
        }

        return $this->employeeId;
    }

    /**
     * Rejects a module code that is not one of the twelve.
     *
     * A typo in `->middleware('module:PAYROL')` or in an `authorize()` call
     * would otherwise be a permission check that quietly denies forever, which
     * reads as "the page is broken" and gets fixed by removing the check.
     *
     * @param  list<string>  $codes
     * @return list<string>
     *
     * @throws InvalidArgumentException
     */
    public static function assertKnown(array $codes): array
    {
        foreach ($codes as $code) {
            if (! in_array($code, ModuleCode::all(), true)) {
                throw new InvalidArgumentException(
                    sprintf('Unknown module code "%s". Expected one of: %s.', $code, implode(', ', ModuleCode::all()))
                );
            }
        }

        return $codes;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }
}
