<?php

declare(strict_types=1);

namespace App\Domain\Enums;

use App\Domain\Projects\ProjectPolicy;
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
 * The reference cases were extracted from those three sources, with the computed
 * forms (`PUNCH_${log_type}`, `TASK_${status}`, `REQUEST_${next}`, the account
 * PATCH ternary, `projectAuditAction`) expanded to their reachable values. That
 * union is 70 cases, against the 43-literals-plus-7-factories estimate in
 * 01-STARTER-PACK.md §5.4 — the estimate counted the factories instead of the
 * strings they produce, and did not count the demo-only or seed-only names.
 * The deliberate additions below extend that reference vocabulary.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE DIVERGENCES, AND HOW THEY ARE RESOLVED
 * ─────────────────────────────────────────────────────────────────────────────
 * Laravel gets ONE vocabulary. Where the two paths named the same event
 * differently, the SERVER name is canonical — the server path is the one this
 * port reproduces, and the demo path is not ported. The drifted name is kept
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
 * DELIBERATE ADDITIONS beyond the union, the only cases here with no
 * reference provenance:
 *
 *   - PROJECT_DELETED. The reference had no project delete at all (no DELETE
 *     /api/projects/[id], no dialog), so no path ever wrote it; this port adds
 *     the action and, with it, the event.
 *   - TASK_RESPONSE_SUBMITTED, TASK_RESPONSE_APPROVED, TASK_RESPONSE_RETURNED.
 *     The reference has one assignee per task and no per-member response;
 *     ems's INDIVIDUAL tasks add both, and with them the three events a
 *     member's own response can go through.
 *   - ROSTER_LOCATIONS_SET. The reference has one Assigned Worksite per
 *     employee; ems's weekly locations (work from home or a site per
 *     weekday) add a roster write the reference never had.
 *   - ROSTER_SHIFTS_SET. Weekday hours or explicit rest days independently
 *     override an employee's standing shift.
 *   - ROSTER_DATE_SET. One date overrides weekly hours or place.
 *   - ROSTER_PERIOD_SET, ROSTER_PERIOD_CANCELLED, ROSTER_CHANGE_CANCELLED.
 *     Temporary schedules for a date range, and cancelling a scheduled
 *     change: schedules are dated history the reference never kept.
 *   - SHIFT_CLEARED. The reference could assign a shift but never take one
 *     away; here clearing one is a dated change back to no shift.
 *   - ROSTER_UNSCHEDULED_FILLED. The reference judged a date with no
 *     schedule by its default and never asked; here such past dates hold
 *     payroll until HR gives them a schedule.
 *   - LEAVE_CREDITS_SET. The reference stored two credit numbers on the
 *     employee record and never wrote them down; ems's leave credits (a row
 *     per employee, year and Leave Settings type) add the write that sets
 *     them.
 *   - REQUEST_CANCELLED. Leave Management excludes selected future dates
 *     from approved leave while retaining its original approval evidence.
 *   - DTR_OVERTIME_APPROVED, DTR_OVERTIME_REJECTED. The reference paid
 *     overtime once a payroll officer acknowledged it for a whole run; ems
 *     decides it per day on the Daily Time Record, and each decision is its
 *     own audit row, written under RequiredAudit because it authorises pay.
 *   - INCENTIVE_TYPE_CREATED, INCENTIVE_TYPE_UPDATED, INCENTIVE_TYPE_DELETED.
 *     The reference paid no bonus at all; ems's catalog of incentive and
 *     bonus types under Records → Payroll Settings adds the three writes
 *     that maintain it (best-effort AuditService, like every other catalog).
 *   - PAY_SETTINGS_UPDATED. The reference edited an employee's statutory
 *     switches and figures as part of the record (EMPLOYEE_UPDATED); ems
 *     edits them under Records → Payroll Settings → Employee Pay Settings,
 *     a payroll write with a reason, under RequiredAudit because it changes
 *     pay.
 *   - RECURRING_LINE_SAVED, RECURRING_LINE_ENDED, RECURRING_LINE_DELETED.
 *     The reference retyped every loan and allowance on each payroll; ems
 *     keeps an employee's standing deductions and incentives, with a loan's
 *     balance, and audits each change under RequiredAudit.
 *   - COMMUNITY_POST_PINNED, COMMUNITY_POST_UNPINNED, COMMUNITY_POST_REMOVED,
 *     COMMUNITY_COMMENT_REMOVED, COMMUNITY_GROUP_CREATED, _UPDATED, _DELETED,
 *     COMMUNITY_MEMBER_REMOVED, COMMUNITY_GROUP_ROLE_CHANGED and
 *     COMMUNITY_TAG_CREATED, _UPDATED, _DELETED (twelve). The reference had
 *     no Community at all; the Social module audits its moderation only
 *     (best-effort AuditService), never posting, commenting or reacting.
 *     COMMUNITY_POST_REMOVED is READ-ONLY: RemovePost is the author's alone.
 *
 *   - SITE_DEACTIVATED, SITE_REACTIVATED. Sites can be withheld from new
 *     assignments while existing attendance and roster history retain them.
 *   - STRUCTURE_CREATED, STRUCTURE_UPDATED, STRUCTURE_DELETED. Organization
 *     owns departments and units independently of the Position reference list.
 *   - INVENTORY_ITEM_CREATED, _UPDATED, _DELETED; INVENTORY_CATALOG_SAVED,
 *     _DELETED; INVENTORY_ISSUED, _TRANSFERRED, _RETURNED;
 *     INVENTORY_RECEIPT_CORRECTED; INVENTORY_INCIDENT_RECORDED, _RESOLVED;
 *     INVENTORY_DISPOSAL_RECORDED; INVENTORY_COUNT_RECORDED, _RESOLVED;
 *     INVENTORY_SIGNED_MR_ATTACHED, _DELETED (sixteen). Property custody,
 *     immutable receipts and their supporting evidence have no reference
 *     counterpart. Inventory uses the existing best-effort AuditService.
 *
 * TWO CLAIMS WORTH CORRECTING, because they are easy to repeat:
 *
 *   - PROJECT_STATUS_CHANGED is NOT demo-only, and PROJECT_UPDATED is NOT
 *     server-only. Both paths call the SAME helper, `projectAuditAction(from,
 *     to)` (`src/lib/projects.ts:32-33`), which returns PROJECT_UPDATED when
 *     the status did not move and PROJECT_STATUS_CHANGED when it did. They are
 *     two different events, not two names for one.
 *     {@see ProjectPolicy::auditAction()}
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
 * `audit_logs.action` is `varchar(80)`; the longest case here is 28 characters.
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
     * An employee proposing, or withdrawing, a change to their own record from
     * My Profile. It is recorded on the change request
     * (`employee_record_change_requests`), not on the employee row.
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
    // REFERENCE_* covers every generic list behind `/records` (employment
    // statuses, leave types, document types, …) and the positions, which keep
    // it on their own page so their trail stays one series; the other lists
    // with their own screens get their own names.

    case REFERENCE_CREATED = 'REFERENCE_CREATED';

    case REFERENCE_UPDATED = 'REFERENCE_UPDATED';

    case REFERENCE_DELETED = 'REFERENCE_DELETED';

    /** A department or a unit added; the sentence opens with "Department" or "Unit". */
    case STRUCTURE_CREATED = 'STRUCTURE_CREATED';

    /** A department or unit renamed, described, moved to another department or reordered. */
    case STRUCTURE_UPDATED = 'STRUCTURE_UPDATED';

    case STRUCTURE_DELETED = 'STRUCTURE_DELETED';

    case HOLIDAY_CREATED = 'HOLIDAY_CREATED';

    case HOLIDAY_UPDATED = 'HOLIDAY_UPDATED';

    case HOLIDAY_DELETED = 'HOLIDAY_DELETED';

    case SITE_CREATED = 'SITE_CREATED';

    case SITE_UPDATED = 'SITE_UPDATED';

    case SITE_DELETED = 'SITE_DELETED';

    /** A site withheld from new assignments; existing ones keep it. */
    case SITE_DEACTIVATED = 'SITE_DEACTIVATED';

    case SITE_REACTIVATED = 'SITE_REACTIVATED';

    case SHIFT_TYPE_CREATED = 'SHIFT_TYPE_CREATED';

    case SHIFT_TYPE_UPDATED = 'SHIFT_TYPE_UPDATED';

    case SHIFT_TYPE_DELETED = 'SHIFT_TYPE_DELETED';

    /** Payroll formulas are seeded and edited, never created or deleted — hence one case, not three. */
    case FORMULA_UPDATED = 'FORMULA_UPDATED';

    /** READ-ONLY: a contribution or withholding table version added; displayed, never emitted — no command writes those tables. */
    case STATUTORY_TABLE_ADDED = 'STATUTORY_TABLE_ADDED';

    /** READ-ONLY: rows written while a version carried a coverage end that payroll refused to run past; displayed, never emitted. */
    case STATUTORY_TABLE_EXTENDED = 'STATUTORY_TABLE_EXTENDED';

    /** READ-ONLY: a contribution or withholding table's figures confirmed good through a later date; displayed, never emitted. */
    case STATUTORY_TABLE_CONFIRMED = 'STATUTORY_TABLE_CONFIRMED';

    /** READ-ONLY: a contribution or withholding table version corrected before taking effect; displayed, never emitted. */
    case STATUTORY_TABLE_UPDATED = 'STATUTORY_TABLE_UPDATED';

    /** READ-ONLY: a contribution or withholding table version withdrawn before taking effect; displayed, never emitted. */
    case STATUTORY_TABLE_WITHDRAWN = 'STATUTORY_TABLE_WITHDRAWN';

    /** A Pay Basis & Rates version saved — the company's or a status's in force, an employee's as a draft (RequiredAudit, SavePayRulesVersion, SaveEmployeePayRules). */
    case PAY_RULES_SAVED = 'PAY_RULES_SAVED';

    /** An employee's own Pay Basis & Rates draft approved (RequiredAudit, ApproveEmployeePayRules). */
    case PAY_RULES_APPROVED = 'PAY_RULES_APPROVED';

    /** An employee's own Pay Basis & Rates draft discarded (RequiredAudit, DiscardEmployeePayRules). */
    case PAY_RULES_DISCARDED = 'PAY_RULES_DISCARDED';

    /** One employee's hourly-rate override saved (RequiredAudit, UpdateEmployeePaySettings); rows before 2026-11-02 also carry their contribution and withholding settings, now dated versions. */
    case PAY_SETTINGS_UPDATED = 'PAY_SETTINGS_UPDATED';

    /** A dated version of the yearly tax-free ceiling on exempt benefits saved (RequiredAudit, SaveBenefitCeiling). */
    case BENEFIT_CEILING_SAVED = 'BENEFIT_CEILING_SAVED';

    /** A scheduled benefit assigned to one employee as a draft (RequiredAudit, SaveBenefitAssignment). */
    case BENEFIT_ASSIGNED = 'BENEFIT_ASSIGNED';

    /** A benefit assignment approved (RequiredAudit, ApproveBenefitAssignment). */
    case BENEFIT_ASSIGNMENT_APPROVED = 'BENEFIT_ASSIGNMENT_APPROVED';

    /** A benefit assignment draft discarded (RequiredAudit, DiscardBenefitAssignment). */
    case BENEFIT_ASSIGNMENT_DISCARDED = 'BENEFIT_ASSIGNMENT_DISCARDED';

    /** READ-ONLY: a tax year's opening balance or previous-employer figures recorded as a draft; displayed, never emitted — no command writes tax balances. */
    case TAX_BALANCE_RECORDED = 'TAX_BALANCE_RECORDED';

    /** READ-ONLY: a tax balance approved; displayed, never emitted. */
    case TAX_BALANCE_APPROVED = 'TAX_BALANCE_APPROVED';

    /** READ-ONLY: a tax balance draft discarded; displayed, never emitted. */
    case TAX_BALANCE_DISCARDED = 'TAX_BALANCE_DISCARDED';

    /** One employee's tax details saved: province of employment, claim amounts, extra tax, and whether CPP and EI are deducted (RequiredAudit, SaveEmployeeTaxDetails); the entity id is the employee's. */
    case TAX_DETAILS_SAVED = 'TAX_DETAILS_SAVED';

    /** An outside payment or balance adjustment on a loan recorded as a draft (RequiredAudit, SaveRecurringLineEntry); never the amount. */
    case LOAN_ENTRY_RECORDED = 'LOAN_ENTRY_RECORDED';

    /** A loan entry approved (RequiredAudit, ApproveRecurringLineEntry). */
    case LOAN_ENTRY_APPROVED = 'LOAN_ENTRY_APPROVED';

    /** A loan entry draft discarded (RequiredAudit, DiscardRecurringLineEntry). */
    case LOAN_ENTRY_DISCARDED = 'LOAN_ENTRY_DISCARDED';

    /** A dated salary change recorded for one employee (AuditService, RecordSalaryChange); never the amounts. */
    case SALARY_CHANGE_RECORDED = 'SALARY_CHANGE_RECORDED';

    /** A past-dated salary change approved or rejected (AuditService, ReviewSalaryChange). */
    case SALARY_CHANGE_REVIEWED = 'SALARY_CHANGE_REVIEWED';

    /** An approved salary version copied onto the record on its date by the schedule (AuditService::anonymous, SalaryRates::applyDue). */
    case SALARY_CHANGE_APPLIED = 'SALARY_CHANGE_APPLIED';

    /** One employee's Contributions & tax version prepared as a draft (RequiredAudit, SaveGovDeductionPolicy). */
    case GOV_POLICY_SAVED = 'GOV_POLICY_SAVED';

    /** One employee's Contributions & tax draft approved (RequiredAudit, ApproveGovDeductionPolicy). */
    case GOV_POLICY_APPROVED = 'GOV_POLICY_APPROVED';

    /** One employee's Contributions & tax draft discarded (RequiredAudit, DiscardGovDeductionPolicy). */
    case GOV_POLICY_DISCARDED = 'GOV_POLICY_DISCARDED';

    /** A payroll document's signatory set or cleared (AuditService, SaveSignatory); the entity id is the slot. */
    case SIGNATORY_SET = 'SIGNATORY_SET';

    /** An officer named on the Application for Leave set or cleared (AuditService, SaveLeaveSignatory); the entity id is the slot. */
    case LEAVE_SIGNATORY_SET = 'LEAVE_SIGNATORY_SET';

    /** A pay schedule added or changed (RequiredAudit, SavePaySchedule). */
    case PAY_SCHEDULE_SAVED = 'PAY_SCHEDULE_SAVED';

    /** A pay schedule deleted (RequiredAudit, DeletePaySchedule). */
    case PAY_SCHEDULE_DELETED = 'PAY_SCHEDULE_DELETED';

    /** READ-ONLY: a finalized payroll marked released. Nothing emits it — a payroll's last status is FINALIZED; the case stays so stored rows keep their label. */
    case PAYROLL_RELEASED = 'PAYROLL_RELEASED';

    /** An adjustment category added or changed (RequiredAudit, SaveAdjustmentCategory). */
    case ADJUSTMENT_CATEGORY_SAVED = 'ADJUSTMENT_CATEGORY_SAVED';

    /** An adjustment category deleted (RequiredAudit, DeleteAdjustmentCategory). */
    case ADJUSTMENT_CATEGORY_DELETED = 'ADJUSTMENT_CATEGORY_DELETED';

    /** An adjustment prepared or corrected while pending (RequiredAudit, SaveAdjustment). */
    case ADJUSTMENT_SAVED = 'ADJUSTMENT_SAVED';

    /** An adjustment approved (RequiredAudit, ReviewAdjustment). */
    case ADJUSTMENT_APPROVED = 'ADJUSTMENT_APPROVED';

    /** An adjustment rejected (RequiredAudit, ReviewAdjustment). */
    case ADJUSTMENT_REJECTED = 'ADJUSTMENT_REJECTED';

    /** A pending adjustment discarded (RequiredAudit, DiscardAdjustment). */
    case ADJUSTMENT_DISCARDED = 'ADJUSTMENT_DISCARDED';

    /** An employee's recurring deduction or incentive added or changed (RequiredAudit, SaveRecurringLine). */
    case RECURRING_LINE_SAVED = 'RECURRING_LINE_SAVED';

    /** An employee's recurring line given its last date (RequiredAudit, EndRecurringLine). */
    case RECURRING_LINE_ENDED = 'RECURRING_LINE_ENDED';

    /** An employee's recurring line never paid on a payslip, deleted (RequiredAudit, DeleteRecurringLine). */
    case RECURRING_LINE_DELETED = 'RECURRING_LINE_DELETED';

    // ── Attendance, DTR and the roster ──────────────────────────────────────

    /** {@see self::punch()} — `log_type` is the two-value enum `TIME_IN | TIME_OUT`. */
    case PUNCH_TIME_IN = 'PUNCH_TIME_IN';

    case PUNCH_TIME_OUT = 'PUNCH_TIME_OUT';

    /** Server-only. A manual DTR correction on the EDTR screen. */
    case DTR_CORRECTED = 'DTR_CORRECTED';

    /** Server-only. {@see self::dtrRemark()} */
    case DTR_REMARK_SET = 'DTR_REMARK_SET';

    case DTR_REMARK_CLEARED = 'DTR_REMARK_CLEARED';

    /**
     * Not in the reference vocabulary. An all-data EDTR holder decided one
     * day's overtime on the Daily Time Record — approved (possibly fewer
     * minutes than the day computed) or rejected. Entity `overtime_approvals`,
     * id the EMPLOYEE's; one row per decision, never for a no-op, written with
     * RequiredAudit so a failed audit cancels the decision. {@see self::dtrOvertime()}
     */
    case DTR_OVERTIME_APPROVED = 'DTR_OVERTIME_APPROVED';

    case DTR_OVERTIME_REJECTED = 'DTR_OVERTIME_REJECTED';

    case SHIFT_ASSIGNED = 'SHIFT_ASSIGNED';

    /**
     * SEED-ONLY. `src/lib/seed.ts` writes one historical row with this action;
     * no writer in either path emits it — editing a roster entry re-writes it
     * as SHIFT_ASSIGNED. Kept so the seeded row stays legal. Do not emit it.
     */
    case SHIFT_UPDATED = 'SHIFT_UPDATED';

    /**
     * Not in the reference vocabulary. The Shift Roster's Clear shift: from
     * its effective date the employee has no default shift and no weekday
     * hours (`ClearShift`). Entity `shifts`, id the EMPLOYEE's; one row per
     * clearing that changed something, never for a no-op.
     */
    case SHIFT_CLEARED = 'SHIFT_CLEARED';

    /**
     * The Shift Roster's Default schedule saved its places: where an employee
     * works on each weekday, on `roster_locations`. Not in the reference
     * vocabulary — the reference had one Assigned Worksite per employee and
     * no such write.
     * One row per save that changed something, never for a no-op.
     */
    case ROSTER_LOCATIONS_SET = 'ROSTER_LOCATIONS_SET';

    /** Weekday hours or explicit rest days were changed in Shift Roster. */
    case ROSTER_SHIFTS_SET = 'ROSTER_SHIFTS_SET';

    /** A one-date change overrides or returns to the employee's weekly schedule. */
    case ROSTER_DATE_SET = 'ROSTER_DATE_SET';

    /** A temporary schedule for a date range was saved or changed. */
    case ROSTER_PERIOD_SET = 'ROSTER_PERIOD_SET';

    /** A temporary schedule was cancelled: before it began, or from the next eligible date. */
    case ROSTER_PERIOD_CANCELLED = 'ROSTER_PERIOD_CANCELLED';

    /** A default-shift or weekly change was cancelled before it took effect. */
    case ROSTER_CHANGE_CANCELLED = 'ROSTER_CHANGE_CANCELLED';

    /**
     * Past dates that had no shift were given one, or a Rest day
     * (`FillUnscheduledDates`): the one roster write that reaches back,
     * and only onto dates nothing was scheduled on. Entity
     * `roster_day_overrides`, id the EMPLOYEE's; one row per save.
     */
    case ROSTER_UNSCHEDULED_FILLED = 'ROSTER_UNSCHEDULED_FILLED';

    // ── Leave and travel requests ───────────────────────────────────────────
    // REQUEST_*, not LEAVE_*: one table, `leave_travel_requests`, carries leave,
    // official business and travel alike. LEAVE_CREDITS_SET is the one LEAVE_
    // case: it is on `leave_credits`, not on the requests table.

    case REQUEST_FILED = 'REQUEST_FILED';

    case REQUEST_APPROVED = 'REQUEST_APPROVED';

    case REQUEST_REJECTED = 'REQUEST_REJECTED';

    /**
     * A leave manager recorded "For approval" or "For disapproval" on a
     * pending request (RecommendAbsence); the request stays pending. The
     * reason for a recommended disapproval stays on the request.
     */
    case REQUEST_RECOMMENDED = 'REQUEST_RECOMMENDED';

    /** Server-only. The filer taking back a still-pending request. */
    case REQUEST_WITHDRAWN = 'REQUEST_WITHDRAWN';

    /** Selected approved leave dates are cancelled; original approval evidence stays. */
    case REQUEST_CANCELLED = 'REQUEST_CANCELLED';

    /**
     * Leave Management's Set credits dialog saved: one employee's leave
     * credits for one year, per Leave Settings type, on `leave_credits`.
     * Not in the reference vocabulary — the reference carried two credit
     * numbers on the employee record that nothing ever wrote. Entity id is
     * the EMPLOYEE's; one row per save that changed something, never for a
     * no-op.
     */
    case LEAVE_CREDITS_SET = 'LEAVE_CREDITS_SET';

    /** One leave type's credit rules set on Leave Credits settings (SaveLeaveTypeCredits). Entity id: the type's. */
    case LEAVE_TYPE_CREDITS_SET = 'LEAVE_TYPE_CREDITS_SET';

    /** READ-ONLY: the minutes-to-days conversion table saved. Nothing emits it — the table is fixed (ConversionTables) and nobody edits it; the case stays so stored rows keep their label. */
    case LEAVE_CONVERSION_TABLE_SAVED = 'LEAVE_CONVERSION_TABLE_SAVED';

    /** READ-ONLY: a conversion table version approved. Nothing emits it — the table is fixed (ConversionTables); the case stays so stored rows keep their label. */
    case LEAVE_CONVERSION_TABLE_APPROVED = 'LEAVE_CONVERSION_TABLE_APPROVED';

    /** READ-ONLY: a conversion table draft discarded. Nothing emits it — there are no drafts to discard; the case stays so stored rows keep their label. */
    case LEAVE_CONVERSION_TABLE_DELETED = 'LEAVE_CONVERSION_TABLE_DELETED';

    /** READ-ONLY: an earning preset saved or deleted on Leave Credits settings. Nothing emits it — presets fed an employee's own leave policy, and the rules are set on the leave type (LEAVE_RULE_SAVED); the case stays so stored rows keep their label. */
    case LEAVE_PRESET_SAVED = 'LEAVE_PRESET_SAVED';

    /** READ-ONLY: one employee's own leave policy draft saved or discarded. Nothing emits it — the rules are set on the leave type (LEAVE_RULE_SAVED); the case stays so stored rows keep their label. Entity id: the employee's. */
    case LEAVE_POLICY_SAVED = 'LEAVE_POLICY_SAVED';

    /** READ-ONLY: one employee's own leave policy version approved. Nothing emits it — the rules are set on the leave type (LEAVE_RULE_SAVED); the case stays so stored rows keep their label. Entity id: the employee's. */
    case LEAVE_POLICY_APPROVED = 'LEAVE_POLICY_APPROVED';

    /** A leave type's rule saved on Leave Settings, in force from that day (RequiredAudit, SaveLeaveRule). Entity id: the version's. */
    case LEAVE_RULE_SAVED = 'LEAVE_RULE_SAVED';

    /** A month's leave earning posted to an employee's ledger by the schedule, or a waiting leave type acquired (PostLeaveEarnings). Entity id: the employee's. */
    case LEAVE_EARNINGS_POSTED = 'LEAVE_EARNINGS_POSTED';

    /** Attendance charged to an employee's leave credits by a finalized payroll (LeaveChargeQuery::post; best effort, after the finalizing transaction commits — the charge rows themselves commit with the payroll). Entity id: the employee's. */
    case LEAVE_CHARGED = 'LEAVE_CHARGED';

    /** An employee asked to cash part of a leave type's credits out (RequestLeaveCashOut). Entity id: the employee's. */
    case LEAVE_CASH_OUT_REQUESTED = 'LEAVE_CASH_OUT_REQUESTED';

    /** The employee withdrew a pending cash-out (WithdrawLeaveCashOut). Entity id: the employee's. */
    case LEAVE_CASH_OUT_WITHDRAWN = 'LEAVE_CASH_OUT_WITHDRAWN';

    /** A leave manager approved or rejected a cash-out (RequiredAudit, ReviewLeaveCashOut). Entity id: the employee's. */
    case LEAVE_CASH_OUT_REVIEWED = 'LEAVE_CASH_OUT_REVIEWED';

    /** A finalized payroll paid a cash-out and took the days off the ledger (LeaveCashOutQuery::post; best effort after commit, the rows themselves commit with the payroll). Entity id: the employee's. */
    case LEAVE_CASH_OUT_PAID = 'LEAVE_CASH_OUT_PAID';

    /** A leave manager filed terminal leave for an employee who has left: one pending cash-out per type paid out on separation (FileTerminalLeave; best effort after commit). Entity id: the employee's. */
    case TERMINAL_LEAVE_FILED = 'TERMINAL_LEAVE_FILED';

    // ── Projects ────────────────────────────────────────────────────────────

    case PROJECT_FINANCE_ENABLED = 'PROJECT_FINANCE_ENABLED';
    case PROJECT_FINANCE_CURRENCY_CHANGED = 'PROJECT_FINANCE_CURRENCY_CHANGED';
    case PROJECT_FINANCE_ACCESS_CHANGED = 'PROJECT_FINANCE_ACCESS_CHANGED';
    case PROJECT_COST_CATEGORY_SAVED = 'PROJECT_COST_CATEGORY_SAVED';
    case PROJECT_BUDGET_DRAFTED = 'PROJECT_BUDGET_DRAFTED';
    case PROJECT_BUDGET_EDITED = 'PROJECT_BUDGET_EDITED';
    case PROJECT_BUDGET_APPROVED = 'PROJECT_BUDGET_APPROVED';
    case PROJECT_CASH_RECORDED = 'PROJECT_CASH_RECORDED';
    case PROJECT_CASH_CORRECTED = 'PROJECT_CASH_CORRECTED';
    case PROJECT_CASH_VOIDED = 'PROJECT_CASH_VOIDED';
    case PROJECT_AVAILABILITY_SAVED = 'PROJECT_AVAILABILITY_SAVED';
    case TASK_EFFORT_SAVED = 'TASK_EFFORT_SAVED';
    case PROJECT_OUTCOME_SAVED = 'PROJECT_OUTCOME_SAVED';
    case PROJECT_INDICATOR_SAVED = 'PROJECT_INDICATOR_SAVED';
    case PROJECT_RESULT_RECORDED = 'PROJECT_RESULT_RECORDED';
    case PROJECT_RESULT_VOIDED = 'PROJECT_RESULT_VOIDED';
    case PROJECT_RECORD_FILE_ATTACHED = 'PROJECT_RECORD_FILE_ATTACHED';
    case PROJECT_RECORD_FILE_REMOVED = 'PROJECT_RECORD_FILE_REMOVED';
    case PROJECT_REPORT_CONFIGURED = 'PROJECT_REPORT_CONFIGURED';
    case PROJECT_REPORT_DRAFTED = 'PROJECT_REPORT_DRAFTED';
    case PROJECT_REPORT_SUBMITTED = 'PROJECT_REPORT_SUBMITTED';
    case PROJECT_REPORT_REVIEWED = 'PROJECT_REPORT_REVIEWED';
    case PROJECT_RECORD_VALUES_SAVED = 'PROJECT_RECORD_VALUES_SAVED';
    case PROJECT_FUNDING_SAVED = 'PROJECT_FUNDING_SAVED';
    case PROJECT_CONTRIBUTION_SAVED = 'PROJECT_CONTRIBUTION_SAVED';
    case PROJECT_CONTRIBUTION_DELIVERED = 'PROJECT_CONTRIBUTION_DELIVERED';
    case PROJECT_CASH_ALLOCATED = 'PROJECT_CASH_ALLOCATED';
    case PROJECT_OPENING_RECORDED = 'PROJECT_OPENING_RECORDED';

    case PROJECT_CREATED = 'PROJECT_CREATED';

    case PORTFOLIO_CREATED = 'PORTFOLIO_CREATED';
    case PORTFOLIO_UPDATED = 'PORTFOLIO_UPDATED';
    case PORTFOLIO_ARCHIVED = 'PORTFOLIO_ARCHIVED';
    case PORTFOLIO_RESTORED = 'PORTFOLIO_RESTORED';
    case PORTFOLIO_MEMBER_ADDED = 'PORTFOLIO_MEMBER_ADDED';
    case PORTFOLIO_MEMBER_REMOVED = 'PORTFOLIO_MEMBER_REMOVED';
    case CLIENT_ORGANIZED = 'CLIENT_ORGANIZED';

    case CLIENT_CREATED = 'CLIENT_CREATED';
    case CLIENT_RENAMED = 'CLIENT_RENAMED';
    case CLIENT_UPDATED = 'CLIENT_UPDATED';
    case CLIENT_MEMBER_ADDED = 'CLIENT_MEMBER_ADDED';
    case CLIENT_MEMBER_REMOVED = 'CLIENT_MEMBER_REMOVED';

    case TASK_GROUP_CREATED = 'TASK_GROUP_CREATED';
    case TASK_GROUP_RENAMED = 'TASK_GROUP_RENAMED';
    case TASK_GROUP_DELETED = 'TASK_GROUP_DELETED';

    case PROJECT_MEMBER_SAVED = 'PROJECT_MEMBER_SAVED';
    case PROJECT_MEMBER_REMOVED = 'PROJECT_MEMBER_REMOVED';

    /** Details edited, status unchanged. {@see ProjectPolicy::auditAction()} */
    case PROJECT_UPDATED = 'PROJECT_UPDATED';

    /** Status moved. A DIFFERENT event from PROJECT_UPDATED, not a second name for it. */
    case PROJECT_STATUS_CHANGED = 'PROJECT_STATUS_CHANGED';

    /**
     * NOT in the reference vocabulary — a deliberate addition (see the
     * provenance note above). The project row and, through the DB cascades,
     * its tasks, files and comments are gone; `details` carries the code, name
     * and task count so the trail still says what was removed.
     */
    case PROJECT_DELETED = 'PROJECT_DELETED';

    // ── Tasks ───────────────────────────────────────────────────────────────

    case TASK_ASSIGNED = 'TASK_ASSIGNED';

    /** Details edited by a reviewer — including a change of the assignee set. {@see self::taskDetails()} */
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

    /**
     * Server-only: a reviewer reopening a task back to PENDING. Reachable in
     * ems only as a status set by hand (ChangeTaskStatus), which writes
     * TASK_IN_PROGRESS the same way. {@see self::taskStatus()}
     */
    case TASK_PENDING = 'TASK_PENDING';

    case TASK_IN_PROGRESS = 'TASK_IN_PROGRESS';

    case TASK_SUBMITTED = 'TASK_SUBMITTED';

    case TASK_RETURNED = 'TASK_RETURNED';

    case TASK_COMPLETED = 'TASK_COMPLETED';

    case TASK_CANCELLED = 'TASK_CANCELLED';

    /**
     * DELIBERATE DIVERGENCE — not in the reference vocabulary (see the
     * provenance note above). One member's own response on an
     * INDIVIDUAL task moving: handed in (or resubmitted), approved, or
     * returned for rework. The entity is the `project_task_submissions` row,
     * not the task, and `details` never carries the note: a member's response
     * is private to them and the reviewers, and the trail is not. The task's
     * own completion is still TASK_COMPLETED. {@see self::taskResponse()}
     */
    case TASK_RESPONSE_SUBMITTED = 'TASK_RESPONSE_SUBMITTED';

    case TASK_RESPONSE_APPROVED = 'TASK_RESPONSE_APPROVED';

    case TASK_RESPONSE_RETURNED = 'TASK_RESPONSE_RETURNED';

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

    /** The incentive and bonus catalog — a deliberate addition. */
    case INCENTIVE_TYPE_CREATED = 'INCENTIVE_TYPE_CREATED';

    case INCENTIVE_TYPE_UPDATED = 'INCENTIVE_TYPE_UPDATED';

    case INCENTIVE_TYPE_DELETED = 'INCENTIVE_TYPE_DELETED';

    /**
     * SEED-ONLY. `src/lib/seed.ts` writes one historical row with this action
     * ("5 payslips posted for the previous cut-off"); finalising a run emits
     * PAYROLL_FINALIZED instead. Kept so the seeded row stays legal. Do not
     * emit it.
     */
    case PAYROLL_POSTED = 'PAYROLL_POSTED';

    // ── Community ───────────────────────────────────────────────────────────
    // Only moderation goes in the trail. Posting, commenting and reacting are
    // what the module is for, and a trail full of likes would bury the events
    // someone actually comes here to find.

    case COMMUNITY_POST_PINNED = 'COMMUNITY_POST_PINNED';

    case COMMUNITY_POST_UNPINNED = 'COMMUNITY_POST_UNPINNED';

    /**
     * READ-ONLY. A moderator removed someone else's post. Nothing writes it:
     * RemovePost is the author's alone and is not audited, and posts that go
     * with their group are recorded as COMMUNITY_GROUP_DELETED. Kept so
     * existing rows render; do not emit it.
     */
    case COMMUNITY_POST_REMOVED = 'COMMUNITY_POST_REMOVED';

    /** A moderator removed someone else's comment, and its replies with it. An author removing their own is not audited. */
    case COMMUNITY_COMMENT_REMOVED = 'COMMUNITY_COMMENT_REMOVED';

    /** A moderator made a group. Joining one is membership, not moderation, and is not audited. */
    case COMMUNITY_GROUP_CREATED = 'COMMUNITY_GROUP_CREATED';

    /** A group's name, description, privacy or photo changed, by someone who runs it. */
    case COMMUNITY_GROUP_UPDATED = 'COMMUNITY_GROUP_UPDATED';

    /** A moderator deleted a group, and every post in it with it. */
    case COMMUNITY_GROUP_DELETED = 'COMMUNITY_GROUP_DELETED';

    /** Someone who runs a group took a member out of it. Leaving on your own is not audited. */
    case COMMUNITY_MEMBER_REMOVED = 'COMMUNITY_MEMBER_REMOVED';

    /** A group's owner made someone a moderator, took it back, or handed the group on. */
    case COMMUNITY_GROUP_ROLE_CHANGED = 'COMMUNITY_GROUP_ROLE_CHANGED';

    case COMMUNITY_TAG_CREATED = 'COMMUNITY_TAG_CREATED';

    case COMMUNITY_TAG_UPDATED = 'COMMUNITY_TAG_UPDATED';

    /** Posts that carried the tag stay, untagged. */
    case COMMUNITY_TAG_DELETED = 'COMMUNITY_TAG_DELETED';

    // ── Inventory custody and evidence ────────────────────────────────────

    case INVENTORY_ITEM_CREATED = 'INVENTORY_ITEM_CREATED';

    case INVENTORY_ITEM_UPDATED = 'INVENTORY_ITEM_UPDATED';

    case INVENTORY_ITEM_DELETED = 'INVENTORY_ITEM_DELETED';

    case INVENTORY_CATALOG_SAVED = 'INVENTORY_CATALOG_SAVED';

    case INVENTORY_CATALOG_DELETED = 'INVENTORY_CATALOG_DELETED';

    case INVENTORY_ISSUED = 'INVENTORY_ISSUED';

    case INVENTORY_TRANSFERRED = 'INVENTORY_TRANSFERRED';

    case INVENTORY_RETURNED = 'INVENTORY_RETURNED';

    case INVENTORY_RECEIPT_CORRECTED = 'INVENTORY_RECEIPT_CORRECTED';

    case INVENTORY_INCIDENT_RECORDED = 'INVENTORY_INCIDENT_RECORDED';

    case INVENTORY_INCIDENT_RESOLVED = 'INVENTORY_INCIDENT_RESOLVED';

    case INVENTORY_DISPOSAL_RECORDED = 'INVENTORY_DISPOSAL_RECORDED';

    case INVENTORY_COUNT_RECORDED = 'INVENTORY_COUNT_RECORDED';

    case INVENTORY_COUNT_RESOLVED = 'INVENTORY_COUNT_RESOLVED';

    case INVENTORY_SIGNED_MR_ATTACHED = 'INVENTORY_SIGNED_MR_ATTACHED';

    case INVENTORY_SIGNED_MR_DELETED = 'INVENTORY_SIGNED_MR_DELETED';

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
     * No reference counterpart (see the TASK_RESPONSE_* cases). `$state` is
     * the member state the response moved TO — SUBMITTED, APPROVED or
     * RETURNED — so this yields one of the three TASK_RESPONSE_* names.
     */
    public static function taskResponse(string $state): string
    {
        return 'TASK_RESPONSE_'.$state;
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

    /** The overtime decision's event: APPROVED or REJECTED (OvertimeApprovalPolicy::DECISIONS). */
    public static function dtrOvertime(string $decision): self
    {
        return $decision === 'APPROVED' ? self::DTR_OVERTIME_APPROVED : self::DTR_OVERTIME_REJECTED;
    }

    /** `on ? "GRANT_ADDED" : "GRANT_REMOVED"` — src/app/(protected)/users/users-page.tsx. */
    public static function grant(bool $granted): string
    {
        return $granted ? self::GRANT_ADDED->value : self::GRANT_REMOVED->value;
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
}
