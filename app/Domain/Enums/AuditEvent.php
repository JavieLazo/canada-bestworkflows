<?php

declare(strict_types=1);

namespace App\Domain\Enums;

use App\Support\AuditService;

/**
 * The audit-trail vocabulary — every value that may appear in `audit_logs.action`.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY AN ENUM AND NOT A PACKAGE
 * ─────────────────────────────────────────────────────────────────────────────
 * `owen-it/laravel-auditing` is banned from this port. It records *attribute
 * diffs*; this trail records *curated sentences* — "Sick Leave approved for
 * Liza Domingo", "5 payslips posted for the previous cut-off". And six of the
 * events below (BOOTSTRAP_ADMIN, LOGIN_SUCCESS, LOGIN_FAILED, LOGIN_BLOCKED,
 * LOGOUT, PASSWORD_CHANGED) have no model behind them at all, so a
 * model-observer package cannot see them happen.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * PROVENANCE — this is the FULL UNION of three drifted vocabularies
 * ─────────────────────────────────────────────────────────────────────────────
 * The reference wrote its trail from three places that were maintained by hand
 * and drifted apart:
 *
 *   1. the demo/browser path, `src/lib/audit.ts` → `appendAuditLog()`
 *      (~40 call sites under `src/app/(protected)` and `src/components`);
 *   2. the server path, `src/lib/api/audit.ts` → `audit()` / `auditAnonymous()`
 *      (~60 call sites under `src/app/api`);
 *   3. `src/lib/seed.ts`, whose four historical `audit_logs` rows use two names
 *      that NO writer in either path ever emits.
 *
 * Every case below was extracted from those three sources, with the computed
 * forms (`PUNCH_${log_type}`, `TASK_${status}`, `REQUEST_${next}`, the account
 * PATCH ternary, `projectAuditAction`) expanded to their reachable values. That
 * is 70 cases, against the 43-literals-plus-7-factories estimate in
 * 01-STARTER-PACK.md §5.4 — the estimate counted the factories instead of the
 * strings they produce, and did not count the demo-only or seed-only names.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE DIVERGENCES, AND HOW THEY ARE RESOLVED
 * ─────────────────────────────────────────────────────────────────────────────
 * Laravel gets ONE vocabulary. Where the two paths named the same event
 * differently, the SERVER name is canonical — the server path is the one this
 * port reproduces, and the demo path is being deleted. The drifted name is kept
 * as a case anyway, marked READ-ONLY below, because rows carrying it already
 * exist in the production database and the audit screen has to render them.
 *
 *   | event                      | demo path            | server path          | canonical here        |
 *   |----------------------------|----------------------|----------------------|-----------------------|
 *   | assignee starts a task     | TASK_STARTED         | TASK_IN_PROGRESS     | TASK_IN_PROGRESS      |
 *   | reviewer changes assignee  | TASK_REASSIGNED      | TASK_UPDATED         | TASK_UPDATED          |
 *   | employee photo replaced    | EMPLOYEE_PHOTO_UPDATED | EMPLOYEE_UPDATED   | EMPLOYEE_UPDATED      |
 *
 * Server-only names the demo never wrote — all canonical, all reachable here:
 * USER_ROLE_CHANGED, USER_ACTIVATED, USER_DEACTIVATED (the demo collapsed all
 * three into USER_UPDATED), REQUEST_WITHDRAWN, TASK_PENDING, DTR_CORRECTED,
 * DTR_REMARK_SET, DTR_REMARK_CLEARED.
 *
 * Seed-only names, emitted by no writer at all: PAYROLL_POSTED, SHIFT_UPDATED.
 * They exist so the seeded historical rows stay legal. Do not write them.
 *
 * TWO CLAIMS WORTH CORRECTING, because they are easy to repeat:
 *
 *   - PROJECT_STATUS_CHANGED is NOT demo-only, and PROJECT_UPDATED is NOT
 *     server-only. Both paths call the SAME helper, `projectAuditAction(from,
 *     to)` (`src/lib/projects.ts:32-33`), which returns PROJECT_UPDATED when
 *     the status did not move and PROJECT_STATUS_CHANGED when it did. They are
 *     two different events, not two names for one. {@see self::project()}
 *   - TASK_COMMENTED is NOT demo-only either. `src/app/api/tasks/[id]/
 *     comments/route.ts` writes it server-side, exactly as the demo did.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * USING IT
 * ─────────────────────────────────────────────────────────────────────────────
 * {@see AuditService} accepts `AuditEvent|string`, so the factory
 * methods at the bottom may return a plain string: they are ported from
 * interpolations that were never guaranteed to land on a known name, and an
 * audit helper that throws would defeat the point of a fail-soft trail.
 *
 * `audit_logs.action` is `varchar(80)`; the longest case here is 22 characters.
 */
enum AuditEvent: string
{
    // ── Session and credentials ─────────────────────────────────────────────
    // No model behind any of these. The first five are written through
    // AuditService::anonymous() — see the LOGIN_SUCCESS note there, it is a
    // quirk, not an oversight.

    /** First-run bootstrap: the very first System Admin account. */
    case BOOTSTRAP_ADMIN = 'BOOTSTRAP_ADMIN';

    case LOGIN_SUCCESS = 'LOGIN_SUCCESS';

    /** Wrong email or wrong password — the trail cannot say which, and neither could the response. */
    case LOGIN_FAILED = 'LOGIN_FAILED';

    /** Correct password, deactivated account. */
    case LOGIN_BLOCKED = 'LOGIN_BLOCKED';

    case LOGOUT = 'LOGOUT';

    /** The actor changing their OWN password. An admin resetting someone else's is USER_PASSWORD_RESET. */
    case PASSWORD_CHANGED = 'PASSWORD_CHANGED';

    // ── Employees and their documents ───────────────────────────────────────

    case EMPLOYEE_CREATED = 'EMPLOYEE_CREATED';

    case EMPLOYEE_UPDATED = 'EMPLOYEE_UPDATED';

    case EMPLOYEE_DELETED = 'EMPLOYEE_DELETED';

    /**
     * READ-ONLY. Demo-only name: `employees/[id]/employee-detail.tsx` wrote it
     * for a photo replacement, while the server path folds a photo change into
     * EMPLOYEE_UPDATED. Kept so existing rows render; do not emit it.
     */
    case EMPLOYEE_PHOTO_UPDATED = 'EMPLOYEE_PHOTO_UPDATED';

    /**
     * The same employee row edited by its own owner rather than by HR, and the
     * account's own display name. {@see self::employeePatch()}
     */
    case PROFILE_UPDATED = 'PROFILE_UPDATED';

    case DOCUMENT_UPLOADED = 'DOCUMENT_UPLOADED';

    case DOCUMENT_DELETED = 'DOCUMENT_DELETED';

    // ── Accounts, roles and module grants ───────────────────────────────────

    case USER_CREATED = 'USER_CREATED';

    /** A multi-field account PATCH, or one the three specific names below do not cover. */
    case USER_UPDATED = 'USER_UPDATED';

    case USER_DELETED = 'USER_DELETED';

    /** An administrator setting someone ELSE'S password. */
    case USER_PASSWORD_RESET = 'USER_PASSWORD_RESET';

    /** Server-only. A PATCH whose single field was `role_id`. {@see self::accountPatch()} */
    case USER_ROLE_CHANGED = 'USER_ROLE_CHANGED';

    /** Server-only. A PATCH whose single field was `is_active`, resolving true. */
    case USER_ACTIVATED = 'USER_ACTIVATED';

    /** Server-only. A PATCH whose single field was `is_active`, resolving false. */
    case USER_DEACTIVATED = 'USER_DEACTIVATED';

    case ROLE_CREATED = 'ROLE_CREATED';

    case ROLE_UPDATED = 'ROLE_UPDATED';

    /** A module granted to a role. There is deliberately no ROLE_DELETED — roles are never deleted. */
    case GRANT_ADDED = 'GRANT_ADDED';

    case GRANT_REMOVED = 'GRANT_REMOVED';

    // ── Reference lists and records settings ────────────────────────────────
    // REFERENCE_* covers every generic list behind `/records` (positions,
    // employment statuses, leave types, document types, …); the four lists with
    // their own screens get their own names.

    case REFERENCE_CREATED = 'REFERENCE_CREATED';

    case REFERENCE_UPDATED = 'REFERENCE_UPDATED';

    case REFERENCE_DELETED = 'REFERENCE_DELETED';

    case HOLIDAY_CREATED = 'HOLIDAY_CREATED';

    case HOLIDAY_UPDATED = 'HOLIDAY_UPDATED';

    case HOLIDAY_DELETED = 'HOLIDAY_DELETED';

    case SITE_CREATED = 'SITE_CREATED';

    case SITE_UPDATED = 'SITE_UPDATED';

    case SITE_DELETED = 'SITE_DELETED';

    case SHIFT_TYPE_CREATED = 'SHIFT_TYPE_CREATED';

    case SHIFT_TYPE_UPDATED = 'SHIFT_TYPE_UPDATED';

    case SHIFT_TYPE_DELETED = 'SHIFT_TYPE_DELETED';

    /** Payroll formulas are seeded and edited, never created or deleted — hence one case, not three. */
    case FORMULA_UPDATED = 'FORMULA_UPDATED';

    // ── Attendance, DTR and the roster ──────────────────────────────────────

    /** {@see self::punch()} — `log_type` is the two-value enum `TIME_IN | TIME_OUT`. */
    case PUNCH_TIME_IN = 'PUNCH_TIME_IN';

    case PUNCH_TIME_OUT = 'PUNCH_TIME_OUT';

    /** Server-only. A manual DTR correction on the EDTR screen. */
    case DTR_CORRECTED = 'DTR_CORRECTED';

    /** Server-only. {@see self::dtrRemark()} */
    case DTR_REMARK_SET = 'DTR_REMARK_SET';

    case DTR_REMARK_CLEARED = 'DTR_REMARK_CLEARED';

    case SHIFT_ASSIGNED = 'SHIFT_ASSIGNED';

    /**
     * SEED-ONLY. `src/lib/seed.ts` writes one historical row with this action;
     * no writer in either path emits it — editing a roster entry re-writes it
     * as SHIFT_ASSIGNED. Kept so the seeded row stays legal. Do not emit it.
     */
    case SHIFT_UPDATED = 'SHIFT_UPDATED';

    // ── Leave and travel requests ───────────────────────────────────────────
    // REQUEST_*, not LEAVE_*: one table, `leave_travel_requests`, carries leave,
    // official business and travel alike.

    case REQUEST_FILED = 'REQUEST_FILED';

    /** {@see self::leaveReview()} */
    case REQUEST_APPROVED = 'REQUEST_APPROVED';

    case REQUEST_REJECTED = 'REQUEST_REJECTED';

    /** Server-only. The filer taking back a still-pending request. */
    case REQUEST_WITHDRAWN = 'REQUEST_WITHDRAWN';

    // ── Projects ────────────────────────────────────────────────────────────

    case PROJECT_CREATED = 'PROJECT_CREATED';

    /** Details edited, status unchanged. {@see self::project()} */
    case PROJECT_UPDATED = 'PROJECT_UPDATED';

    /** Status moved. A DIFFERENT event from PROJECT_UPDATED, not a second name for it. */
    case PROJECT_STATUS_CHANGED = 'PROJECT_STATUS_CHANGED';

    // ── Tasks ───────────────────────────────────────────────────────────────

    case TASK_ASSIGNED = 'TASK_ASSIGNED';

    /** Details edited by a reviewer — including a change of assignee. {@see self::taskDetails()} */
    case TASK_UPDATED = 'TASK_UPDATED';

    /**
     * READ-ONLY. Demo-only name: `projects/[id]/task-review-dialog.tsx` split a
     * details edit into TASK_REASSIGNED when the assignee changed, while the
     * server path always writes TASK_UPDATED and records the changed field list
     * in `details`. Kept so existing rows render; do not emit it.
     */
    case TASK_REASSIGNED = 'TASK_REASSIGNED';

    /**
     * READ-ONLY. Demo-only name for "the assignee moved their task to
     * IN_PROGRESS"; the server path writes TASK_IN_PROGRESS for the same move.
     * Kept so existing rows render; do not emit it.
     */
    case TASK_STARTED = 'TASK_STARTED';

    /** Server-only: a reviewer reopening a task back to PENDING. {@see self::taskStatus()} */
    case TASK_PENDING = 'TASK_PENDING';

    case TASK_IN_PROGRESS = 'TASK_IN_PROGRESS';

    case TASK_SUBMITTED = 'TASK_SUBMITTED';

    case TASK_RETURNED = 'TASK_RETURNED';

    case TASK_COMPLETED = 'TASK_COMPLETED';

    case TASK_CANCELLED = 'TASK_CANCELLED';

    /** Written by BOTH paths — not demo-only. */
    case TASK_COMMENTED = 'TASK_COMMENTED';

    /** {@see self::taskFile()} — a BRIEF is the handout, anything else is work product. */
    case TASK_BRIEF_ATTACHED = 'TASK_BRIEF_ATTACHED';

    case TASK_FILE_ATTACHED = 'TASK_FILE_ATTACHED';

    case TASK_FILE_REMOVED = 'TASK_FILE_REMOVED';

    // ── Payroll ─────────────────────────────────────────────────────────────

    case PAYROLL_RUN_CREATED = 'PAYROLL_RUN_CREATED';

    case PAYROLL_RUN_UPDATED = 'PAYROLL_RUN_UPDATED';

    case PAYROLL_RUN_DELETED = 'PAYROLL_RUN_DELETED';

    /** The one irreversible payroll event. */
    case PAYROLL_FINALIZED = 'PAYROLL_FINALIZED';

    /**
     * SEED-ONLY. `src/lib/seed.ts` writes one historical row with this action
     * ("5 payslips posted for the previous cut-off"); finalising a run emits
     * PAYROLL_FINALIZED instead. Kept so the seeded row stays legal. Do not
     * emit it.
     */
    case PAYROLL_POSTED = 'PAYROLL_POSTED';

    /*
     |--------------------------------------------------------------------------
     | Computed forms
     |--------------------------------------------------------------------------
     | Ports of the interpolations and ternaries the reference used at the call
     | site. They return `string`, not `self`, on purpose: every one of them is
     | fed a value that came off the wire, and `self::from()` on an unexpected
     | value would throw a ValueError inside an action that has ALREADY HAPPENED.
     | The service records whatever string it is handed; a name that is not a
     | case above is a bug to find in the trail, not a 500 for the user.
     |
     | Every value these can produce for a valid input is enumerated as a case.
     */

    /**
     * `PUNCH_${input.log_type}` — src/app/api/attendance/punch/route.ts:200,
     * src/components/PunchStation.tsx.
     *
     * `$logType` is `TIME_IN` or `TIME_OUT`, so this yields PUNCH_TIME_IN or
     * PUNCH_TIME_OUT.
     */
    public static function punch(string $logType): string
    {
        return 'PUNCH_'.$logType;
    }

    /**
     * `TASK_${input.status}` — src/app/api/tasks/[id]/route.ts:200.
     *
     * Covers all six task statuses. The demo's `AUDIT_ACTION` map in
     * `task-review-dialog.tsx` (COMPLETED/RETURNED/CANCELLED → TASK_*) is the
     * same function over three of them, so it does not get a second helper.
     *
     * The one place the demo differed: an assignee starting a task wrote
     * TASK_STARTED, not TASK_IN_PROGRESS. The server name wins here.
     */
    public static function taskStatus(string $status): string
    {
        return 'TASK_'.$status;
    }

    /**
     * `input.kind === "BRIEF" ? "TASK_BRIEF_ATTACHED" : "TASK_FILE_ATTACHED"`
     * — src/app/api/tasks/[id]/files/route.ts, src/components/TaskAttachments.tsx.
     */
    public static function taskFile(string $kind): string
    {
        return $kind === 'BRIEF'
            ? self::TASK_BRIEF_ATTACHED->value
            : self::TASK_FILE_ATTACHED->value;
    }

    /**
     * `changedAssignee ? "TASK_REASSIGNED" : "TASK_UPDATED"` — the DEMO's
     * task-review-dialog.tsx:231.
     *
     * Resolved to the server behaviour: a details edit is always TASK_UPDATED,
     * whether or not the assignee moved, because the server path records the
     * changed field list in `details` and the reassignment separately raises a
     * TASK_REASSIGNED *notification*. This helper exists only so the divergence
     * is documented where somebody porting the review dialog will find it —
     * pass `false`, or just use TASK_UPDATED.
     */
    public static function taskDetails(bool $changedAssignee): string
    {
        // The argument is accepted and deliberately IGNORED. The demo returned
        // TASK_REASSIGNED when it was true; TASK_REASSIGNED is read-only here.
        return self::TASK_UPDATED->value;
    }

    /**
     * `REQUEST_${next}` — src/app/(protected)/leaves/leaves-page.tsx:142, and
     * `status === "APPROVED" ? "REQUEST_APPROVED" : "REQUEST_REJECTED"` in
     * src/app/api/leave-requests/[id]/review/route.ts. `$status` is APPROVED or
     * REJECTED, so the two forms agree on every reachable input.
     */
    public static function leaveReview(string $status): string
    {
        return 'REQUEST_'.$status;
    }

    /**
     * `note ? "DTR_REMARK_SET" : "DTR_REMARK_CLEARED"` —
     * src/app/api/attendance/remarks/route.ts.
     *
     * Note the reference tests the note's TRUTHINESS, so an empty string clears
     * the remark rather than setting a blank one. `$hasNote` must be computed
     * the same way: `$note !== null && $note !== ''`.
     */
    public static function dtrRemark(bool $hasNote): string
    {
        return $hasNote ? self::DTR_REMARK_SET->value : self::DTR_REMARK_CLEARED->value;
    }

    /** `on ? "GRANT_ADDED" : "GRANT_REMOVED"` — src/app/(protected)/users/users-page.tsx. */
    public static function grant(bool $granted): string
    {
        return $granted ? self::GRANT_ADDED->value : self::GRANT_REMOVED->value;
    }

    /**
     * `isHr ? "EMPLOYEE_UPDATED" : "PROFILE_UPDATED"` —
     * src/app/api/employees/[id]/route.ts.
     *
     * The SAME row, named for who touched it: HR maintaining a record, versus
     * an employee maintaining their own. `$isHr` is "the actor holds EMPLOYEES",
     * never "the actor is not the subject".
     */
    public static function employeePatch(bool $isHr): string
    {
        return $isHr ? self::EMPLOYEE_UPDATED->value : self::PROFILE_UPDATED->value;
    }

    /**
     * `from === to ? "PROJECT_UPDATED" : "PROJECT_STATUS_CHANGED"` —
     * src/lib/projects.ts:32-33, called by BOTH the demo and the server.
     *
     * Ported including its quirk: the comparison is on the STATUS ONLY, so a
     * project whose name and dates changed while its status stood still is
     * PROJECT_UPDATED, and a project whose status moved is
     * PROJECT_STATUS_CHANGED *even if the name changed in the same request*.
     *
     * `\App\Domain\Projects\ProjectPolicy::auditAction()` is the same function,
     * ported alongside the rest of `src/lib/projects.ts`. Both are correct and
     * they agree on every input; this one exists so the two names it can return
     * are declared where the rest of the vocabulary is.
     */
    public static function project(string $from, string $to): string
    {
        return $from === $to
            ? self::PROJECT_UPDATED->value
            : self::PROJECT_STATUS_CHANGED->value;
    }

    /**
     * The account PATCH ternary — src/app/api/accounts/[id]/route.ts:89-96.
     *
     * PORTED LITERALLY, and it is fussier than it looks. The reference is:
     *
     *   keys.length === 1 && patch.is_active === undefined && patch.role_id !== undefined
     *     ? "USER_ROLE_CHANGED"
     *     : keys.length === 1 && patch.is_active !== undefined
     *       ? (account.is_active ? "USER_ACTIVATED" : "USER_DEACTIVATED")
     *       : "USER_UPDATED";
     *
     * So the specific names are reserved for a SINGLE-FIELD patch. Change a role
     * and a name in one request and the trail says USER_UPDATED, not
     * USER_ROLE_CHANGED. That is deliberate — the specific names are a promise
     * that nothing else moved.
     *
     * 01-STARTER-PACK.md §5.4 sketches this as `in_array('role_id', $keys)`,
     * which would label a two-field patch USER_ROLE_CHANGED. The reference wins;
     * the sketch is wrong.
     *
     * @param  list<string>  $keys  the keys actually present in the validated patch
     * @param  bool  $isActive  `is_active` AFTER the update, read back off the row —
     *                          not the value that was submitted
     */
    public static function accountPatch(array $keys, bool $isActive): string
    {
        $single = count($keys) === 1;
        $touchesActive = in_array('is_active', $keys, true);

        if ($single && ! $touchesActive && in_array('role_id', $keys, true)) {
            return self::USER_ROLE_CHANGED->value;
        }

        if ($single && $touchesActive) {
            return $isActive ? self::USER_ACTIVATED->value : self::USER_DEACTIVATED->value;
        }

        return self::USER_UPDATED->value;
    }

    /*
     |--------------------------------------------------------------------------
     | Helpers
     |--------------------------------------------------------------------------
     */

    /**
     * Whether a stored `action` is part of the vocabulary.
     *
     * For tests and for the audit screen's filter, never for a guard: an
     * unknown action must still be DISPLAYED, or a row written by an older
     * release disappears from the trail, which is the one thing an append-only
     * table exists to prevent.
     */
    public static function isKnown(string $action): bool
    {
        return self::tryFrom($action) !== null;
    }

    /** @return list<string> every action name, in declaration order */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
