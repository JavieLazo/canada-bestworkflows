<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Support\Access;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;

/**
 * Row-level narrowing: "my rows", "every row", or NO rows.
 *
 * The port of the `session.employeeId ?? "__none__"` idiom that appears ten
 * times in the source (api/attendance, api/shifts, api/leave-requests,
 * api/tasks, api/tasks/comments, api/tasks/files, api/projects,
 * api/projects/[id], api/employees, api/employees/documents), and the third of
 * the three enforcement layers named in `src/lib/api/guard.ts:13-17`:
 *
 *   "**Scoping is separate from access.** Holding PORTAL lets you read
 *    payslips; it does not let you read *everyone's*."
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE LEAK THIS TRAIT EXISTS TO MAKE UNCONSTRUCTIBLE
 * ─────────────────────────────────────────────────────────────────────────────
 * The naive transcription of `{ employee_id: session.employeeId ?? "__none__" }`
 * is `->where('employee_id', $access->employeeId)`. In Laravel that is not a
 * translation, it is a DATA LEAK: `where($column, null)` silently compiles to
 * `WHERE column IS NULL`. `project_tasks.assignee_id` is nullable, so the naive
 * port hands EVERY UNASSIGNED TASK to any account with no employee record —
 * including a freshly bootstrapped admin, and including whoever is holding the
 * one account nobody has linked yet. The TypeScript sentinel `"__none__"`
 * exists precisely so that an unlinked account matches zero rows.
 *
 * Three things make that leak unconstructible here rather than merely handled:
 *
 *  1. **The scope never reads `$actor->employeeId`.** It `match`es on
 *     {@see Access::rowVisibility()}, which answers with exactly one of three
 *     values. There is no fourth answer to fall through, and `match` without a
 *     default throws `\UnhandledMatchError` rather than degrading to an
 *     unfiltered query, so a future fourth state fails closed and loudly.
 *  2. **The id can only arrive through {@see Access::ownEmployeeId()}**, whose
 *     return type is a non-nullable `string`. No value it can return produces
 *     an `IS NULL`; returning one would be a `TypeError` at the boundary.
 *  3. **The ROWS_NONE arm is `whereRaw('1 = 0')`**, not a sentinel string
 *     compared against a real column. It cannot be defeated by a row that
 *     happens to hold the sentinel, and it does not depend on the column being
 *     NOT NULL.
 *
 * Non-negotiable regression test (01-STARTER-PACK.md §5.3): an account with
 * `employee_id = null` gets an EMPTY collection from every scoped index —
 * attendance, shifts, leaves, tasks, payslips, documents. 03-OPEN-ISSUES.md:107
 * ranks skipping that test third among the things most likely to be dropped
 * under pressure, with the failure mode "invisible at launch; discovered later
 * and externally".
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE PER-ENDPOINT MODULE LISTS — TRANSCRIBED, NOT INVENTED
 * ─────────────────────────────────────────────────────────────────────────────
 * These are not interchangeable. Each is the list the corresponding route in
 * the source passes to `mayReadAllData`, and widening one is a privilege
 * escalation:
 *
 *   attendance      EDTR, PAYROLL                  api/attendance/route.ts:25
 *   shifts          ROSTER, EDTR, PAYROLL          api/shifts/route.ts:14
 *   leave requests  LEAVES                         api/leave-requests/route.ts:64
 *   documents       EMPLOYEES, RECORDS             api/employees/documents/route.ts:20
 *   tasks           PROJECTS                       api/tasks/route.ts:22
 *   projects        PROJECTS  (via tasks.assignee) api/projects/route.ts:21-27
 *   payslips        PAYROLL ALONE                  api/payroll/payslips/route.ts:22
 *
 * TASKS AND PROJECTS ARE `PROJECTS` ALONE. The source computes
 * `session.allData && session.modules.includes("PROJECTS")` — the *access* gate
 * is `requireModule("PROJECTS","TASKS")`, but the *widening* gate is PROJECTS
 * only, so an assignee who holds TASKS and happens to carry `all_data` still
 * sees only their own queue. 01-STARTER-PACK.md §5.3 writes
 * `// tasks → PROJECTS, TASKS`, which conflates the two gates and would widen
 * for a TASKS holder; the TypeScript is the thing being ported, so the
 * TypeScript wins. Do not "fix" this back.
 *
 * PAYSLIPS ARE `PAYROLL` ALONE, for the reason spelled out at
 * api/payroll/payslips/route.ts:19-21: "`all_data` alone (a supervisor who sees
 * the roster, say) is not a license to read salaries."
 *
 * Two source endpoints do NOT reduce to this scope and must be ported by hand:
 *
 *   - `GET /api/payroll/payslips` reads ONE employee's payslips and returns a
 *     400 — "Your account isn't linked to an employee record yet." — for an
 *     unlinked caller, where this scope would return an empty list. It also
 *     ignores an `employeeId` query parameter it is not allowed to honour
 *     rather than rejecting it.
 *   - `GET /api/employees` has three tiers, not two: the full directory
 *     (EMPLOYEES/PAYROLL), a reduced-column operational directory
 *     (EDTR/ROSTER/LEAVES/PROJECTS/TASKS) with the caller's OWN row swapped
 *     back in at full width, and own-record-only below that.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * USAGE
 * ─────────────────────────────────────────────────────────────────────────────
 *     use App\Models\Concerns\ScopesToActor;
 *
 *     class AttendanceLog extends BaseModel { use ScopesToActor; }
 *
 *     // The actor is ALWAYS the container's request-scoped Access, never a
 *     // Livewire public property and never a request parameter.
 *     AttendanceLog::query()
 *         ->scopeToEmployee($access, 'employee_id', ModuleCode::EDTR, ModuleCode::PAYROLL)
 *         ->orderByDesc('logged_at');
 *
 *     Project::query()
 *         ->scopeToEmployeeVia($access, 'tasks', 'assignee_id', ModuleCode::PROJECTS);
 *
 * @see Access  the request-scoped actor, and the `#[Locked]` rule for Livewire
 */
trait ScopesToActor
{
    /**
     * Narrow to the actor's own rows, unless their role may read the whole
     * organisation's.
     *
     * At least one module is required by the signature, which is SEC-01
     * enforced by the type system: there is no way to ask for the widening
     * without naming the module it is relevant to.
     *
     * @param  Builder<static>  $query
     * @param  string  $column  the employee foreign key on THIS table
     * @param  string  $module  first module the widening is relevant to
     * @param  string  ...$more  further relevant modules; ANY of them widens
     * @return Builder<static>
     */
    #[Scope]
    protected function scopeToEmployee(
        Builder $query,
        Access $actor,
        string $column,
        string $module,
        string ...$more,
    ): Builder {
        return match ($actor->rowVisibility($module, ...$more)) {
            // Organisation-wide read: no narrowing at all.
            Access::ROWS_ALL => $query,

            // The actor's own rows. `qualifyColumn` keeps this unambiguous
            // under a join, and the explicit '=' means there is no argument
            // shape in which a null could become `IS NULL`.
            Access::ROWS_OWN => $query->where(
                $query->qualifyColumn($column),
                '=',
                $actor->ownEmployeeId(),
            ),

            // No linked employee record: the port of `?? "__none__"`. NOT
            // `where($column, null)`, which is `IS NULL` and hands over every
            // unassigned row.
            Access::ROWS_NONE => $query->whereRaw('1 = 0'),
        };
    }

    /**
     * The same narrowing, one relation away: the port of
     * `{ tasks: { some: { assignee_id: session.employeeId ?? "__none__" } } }`
     * (api/projects/route.ts:26, api/tasks/comments/route.ts:17,
     * api/tasks/files/route.ts:19).
     *
     * It exists so that call sites never hand-roll the comparison inside a
     * `whereHas` closure, which is where the `IS NULL` leak would reappear out
     * of sight of this file.
     *
     * Prisma's `some` is an EXISTS subquery and so is `whereHas`, so a parent
     * with no matching children is excluded either way.
     *
     * @param  Builder<static>  $query
     * @param  string  $relation  relation holding the employee key ('tasks', 'task')
     * @param  string  $column  the employee foreign key on the RELATED table
     * @return Builder<static>
     */
    #[Scope]
    protected function scopeToEmployeeVia(
        Builder $query,
        Access $actor,
        string $relation,
        string $column,
        string $module,
        string ...$more,
    ): Builder {
        return match ($actor->rowVisibility($module, ...$more)) {
            Access::ROWS_ALL => $query,

            Access::ROWS_OWN => $query->whereHas(
                $relation,
                static fn (Builder $related): Builder => $related->where(
                    $related->qualifyColumn($column),
                    '=',
                    $actor->ownEmployeeId(),
                ),
            ),

            Access::ROWS_NONE => $query->whereRaw('1 = 0'),
        };
    }
}
