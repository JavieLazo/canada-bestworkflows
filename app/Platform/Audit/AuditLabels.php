<?php

declare(strict_types=1);

namespace App\Platform\Audit;

use App\Domain\Enums\AuditEvent;

/**
 * Plain-language names for what `audit_logs` stores as codes: the action
 * (an {@see AuditEvent} value such as `REQUEST_APPROVED`) and the entity (the
 * table a row describes, such as `leave_travel_requests`). The Audit Trail
 * shows these instead of the raw codes, so a reader sees "Leave/OB request
 * approved" on a "Leave/OB request" rather than database names.
 *
 * Labels are presentation only: the stored codes never change, and a code
 * this map does not know — a row written before a case existed, or a computed
 * action off the wire — still reads as words through {@see self::humanize()}
 * ("SOMETHING_NEW" → "Something new") rather than disappearing. Every
 * AuditEvent case has its own label, and no two cases share one, so the
 * action filter can never merge two different events.
 */
final class AuditLabels
{
    /**
     * One label per {@see AuditEvent} case, READ-ONLY and SEED-ONLY cases
     * included, because their stored rows still render.
     *
     * @var array<string, string>
     */
    public const array ACTIONS = [
        // Session and credentials
        'BOOTSTRAP_ADMIN' => 'First admin set up',
        'LOGIN_SUCCESS' => 'Signed in',
        'LOGIN_FAILED' => 'Sign-in failed',
        'LOGIN_BLOCKED' => 'Sign-in blocked',
        'LOGOUT' => 'Signed out',
        'PASSWORD_CHANGED' => 'Own password changed',

        // Employees and their documents
        'EMPLOYEE_CREATED' => 'Employee added',
        'EMPLOYEE_UPDATED' => 'Employee record updated',
        'EMPLOYEE_DELETED' => 'Employee deleted',
        'EMPLOYEE_PHOTO_UPDATED' => 'Employee photo updated',
        'PROFILE_UPDATED' => 'Profile change request updated',
        'DOCUMENT_UPLOADED' => 'Document uploaded',
        'DOCUMENT_DELETED' => 'Document deleted',

        // Accounts, roles and module grants
        'USER_CREATED' => 'Account created',
        'USER_UPDATED' => 'Account updated',
        'USER_DELETED' => 'Account deleted',
        'USER_PASSWORD_RESET' => 'Account password reset',
        'USER_ROLE_CHANGED' => 'Account role changed',
        'USER_ACTIVATED' => 'Account activated',
        'USER_DEACTIVATED' => 'Account deactivated',
        'ROLE_CREATED' => 'Role created',
        'ROLE_UPDATED' => 'Role updated',
        'GRANT_ADDED' => 'Module access granted',
        'GRANT_REMOVED' => 'Module access removed',

        // Reference lists and records settings
        'REFERENCE_CREATED' => 'List entry added',
        'REFERENCE_UPDATED' => 'List entry updated',
        'REFERENCE_DELETED' => 'List entry deleted',
        'STRUCTURE_CREATED' => 'Department or unit added',
        'STRUCTURE_UPDATED' => 'Department or unit updated',
        'STRUCTURE_DELETED' => 'Department or unit deleted',
        'HOLIDAY_CREATED' => 'Holiday added',
        'HOLIDAY_UPDATED' => 'Holiday updated',
        'HOLIDAY_DELETED' => 'Holiday deleted',
        'SITE_CREATED' => 'Worksite added',
        'SITE_UPDATED' => 'Worksite updated',
        'SITE_DELETED' => 'Worksite deleted',
        'SITE_DEACTIVATED' => 'Worksite deactivated',
        'SITE_REACTIVATED' => 'Worksite reactivated',
        'SHIFT_TYPE_CREATED' => 'Shift pattern added',
        'SHIFT_TYPE_UPDATED' => 'Shift pattern updated',
        'SHIFT_TYPE_DELETED' => 'Shift pattern deleted',
        'FORMULA_UPDATED' => 'Payroll formula updated',
        'STATUTORY_TABLE_ADDED' => 'Contribution & tax table added',
        'STATUTORY_TABLE_EXTENDED' => 'Contribution & tax table extended',
        'STATUTORY_TABLE_CONFIRMED' => 'Contribution & tax table confirmed',
        'STATUTORY_TABLE_UPDATED' => 'Contribution & tax table corrected',
        'STATUTORY_TABLE_WITHDRAWN' => 'Contribution & tax table withdrawn',
        'PAY_RULES_SAVED' => 'Pay basis & rates saved',
        'PAY_RULES_APPROVED' => 'Pay basis & rates approved',
        'PAY_RULES_DISCARDED' => 'Pay basis & rates discarded',
        'PAY_SETTINGS_UPDATED' => 'Employee pay settings updated',
        'BENEFIT_CEILING_SAVED' => 'Tax-free benefit ceiling saved',
        'BENEFIT_ASSIGNED' => 'Benefit assigned',
        'BENEFIT_ASSIGNMENT_APPROVED' => 'Benefit assignment approved',
        'BENEFIT_ASSIGNMENT_DISCARDED' => 'Benefit assignment discarded',
        'TAX_BALANCE_RECORDED' => 'Tax balance recorded',
        'TAX_BALANCE_APPROVED' => 'Tax balance approved',
        'TAX_BALANCE_DISCARDED' => 'Tax balance discarded',
        'LOAN_ENTRY_RECORDED' => 'Loan entry recorded',
        'LOAN_ENTRY_APPROVED' => 'Loan entry approved',
        'LOAN_ENTRY_DISCARDED' => 'Loan entry discarded',
        'SALARY_CHANGE_RECORDED' => 'Salary change recorded',
        'SALARY_CHANGE_REVIEWED' => 'Salary change reviewed',
        'SALARY_CHANGE_APPLIED' => 'Salary change applied',
        'GOV_POLICY_SAVED' => 'Contributions & tax setting saved',
        'GOV_POLICY_APPROVED' => 'Contributions & tax setting approved',
        'GOV_POLICY_DISCARDED' => 'Contributions & tax setting discarded',
        'SIGNATORY_SET' => 'Signatory set',
        'LEAVE_SIGNATORY_SET' => 'Leave form officer set',
        'PAY_SCHEDULE_SAVED' => 'Pay schedule saved',
        'PAY_SCHEDULE_DELETED' => 'Pay schedule deleted',
        'PAYROLL_RELEASED' => 'Payroll released',
        'ADJUSTMENT_CATEGORY_SAVED' => 'Adjustment category saved',
        'ADJUSTMENT_CATEGORY_DELETED' => 'Adjustment category deleted',
        'ADJUSTMENT_SAVED' => 'Adjustment saved',
        'ADJUSTMENT_APPROVED' => 'Adjustment approved',
        'ADJUSTMENT_REJECTED' => 'Adjustment rejected',
        'ADJUSTMENT_DISCARDED' => 'Adjustment discarded',
        'RECURRING_LINE_SAVED' => 'Recurring line saved',
        'RECURRING_LINE_ENDED' => 'Recurring line ended',
        'RECURRING_LINE_DELETED' => 'Recurring line deleted',

        // Attendance, DTR and the roster
        'PUNCH_TIME_IN' => 'Timed in',
        'PUNCH_TIME_OUT' => 'Timed out',
        'DTR_CORRECTED' => 'DTR corrected',
        'DTR_REMARK_SET' => 'DTR remark set',
        'DTR_REMARK_CLEARED' => 'DTR remark cleared',
        'DTR_OVERTIME_APPROVED' => 'Overtime approved',
        'DTR_OVERTIME_REJECTED' => 'Overtime rejected',
        'SHIFT_ASSIGNED' => 'Shift assigned',
        'SHIFT_UPDATED' => 'Shift updated',
        'SHIFT_CLEARED' => 'Shift cleared',
        'ROSTER_LOCATIONS_SET' => 'Weekly locations set',
        'ROSTER_SHIFTS_SET' => 'Weekly schedule set',
        'ROSTER_DATE_SET' => 'One-day schedule set',
        'ROSTER_PERIOD_SET' => 'Temporary schedule set',
        'ROSTER_PERIOD_CANCELLED' => 'Temporary schedule cancelled',
        'ROSTER_CHANGE_CANCELLED' => 'Scheduled change cancelled',
        'ROSTER_UNSCHEDULED_FILLED' => 'Past dates without a shift filled',

        // Leave and travel requests
        'REQUEST_FILED' => 'Leave/OB request filed',
        'REQUEST_APPROVED' => 'Leave/OB request approved',
        'REQUEST_REJECTED' => 'Leave/OB request disapproved',
        'REQUEST_RECOMMENDED' => 'Leave/OB request recommendation recorded',
        'REQUEST_WITHDRAWN' => 'Leave/OB request withdrawn',
        'REQUEST_CANCELLED' => 'Leave dates cancelled',
        'LEAVE_CREDITS_SET' => 'Leave credits set',
        'LEAVE_TYPE_CREDITS_SET' => 'Leave type credits set',
        'LEAVE_CONVERSION_TABLE_SAVED' => 'Conversion table saved',
        'LEAVE_CONVERSION_TABLE_APPROVED' => 'Conversion table approved',
        'LEAVE_CONVERSION_TABLE_DELETED' => 'Conversion table discarded',
        'LEAVE_PRESET_SAVED' => 'Leave preset saved',
        'LEAVE_POLICY_SAVED' => 'Leave policy saved',
        'LEAVE_POLICY_APPROVED' => 'Leave policy approved',
        'LEAVE_RULE_SAVED' => 'Leave rule saved',
        'LEAVE_EARNINGS_POSTED' => 'Leave earnings posted',
        'LEAVE_CHARGED' => 'Leave charged',
        'LEAVE_CASH_OUT_REQUESTED' => 'Cash conversion requested',
        'LEAVE_CASH_OUT_WITHDRAWN' => 'Cash conversion withdrawn',
        'LEAVE_CASH_OUT_REVIEWED' => 'Cash conversion reviewed',
        'LEAVE_CASH_OUT_PAID' => 'Cash conversion paid',
        'TERMINAL_LEAVE_FILED' => 'Terminal leave filed',

        // Projects
        'PROJECT_CREATED' => 'Project created',
        'PROJECT_UPDATED' => 'Project updated',
        'PROJECT_STATUS_CHANGED' => 'Project status changed',
        'PROJECT_DELETED' => 'Project deleted',

        // Tasks
        'TASK_ASSIGNED' => 'Task assigned',
        'TASK_UPDATED' => 'Task updated',
        'TASK_REASSIGNED' => 'Task reassigned',
        'TASK_STARTED' => 'Task started',
        'TASK_PENDING' => 'Task set to pending',
        'TASK_IN_PROGRESS' => 'Task in progress',
        'TASK_SUBMITTED' => 'Task submitted',
        'TASK_RETURNED' => 'Task returned',
        'TASK_COMPLETED' => 'Task completed',
        'TASK_CANCELLED' => 'Task cancelled',
        'TASK_RESPONSE_SUBMITTED' => 'Task response submitted',
        'TASK_RESPONSE_APPROVED' => 'Task response approved',
        'TASK_RESPONSE_RETURNED' => 'Task response returned',
        'TASK_COMMENTED' => 'Task comment added',
        'TASK_BRIEF_ATTACHED' => 'Task brief attached',
        'TASK_FILE_ATTACHED' => 'Task file attached',
        'TASK_FILE_REMOVED' => 'Task file removed',

        // Project Management: team, activities and planning
        'PROJECT_MEMBER_SAVED' => 'Project member added or changed',
        'PROJECT_MEMBER_REMOVED' => 'Project member removed',
        'TASK_GROUP_CREATED' => 'Activity added',
        'TASK_GROUP_RENAMED' => 'Activity renamed',
        'TASK_GROUP_DELETED' => 'Activity deleted',
        'TASK_EFFORT_SAVED' => 'Task effort estimated',
        'PROJECT_AVAILABILITY_SAVED' => 'Availability updated',

        // Project Management: clients and portfolios
        'CLIENT_CREATED' => 'Client added',
        'CLIENT_RENAMED' => 'Client renamed',
        'CLIENT_UPDATED' => 'Client details updated',
        'CLIENT_ORGANIZED' => 'Client portfolio changed',
        'CLIENT_MEMBER_ADDED' => 'Client member added',
        'CLIENT_MEMBER_REMOVED' => 'Client member removed',
        'PORTFOLIO_CREATED' => 'Portfolio added',
        'PORTFOLIO_UPDATED' => 'Portfolio updated',
        'PORTFOLIO_ARCHIVED' => 'Portfolio archived',
        'PORTFOLIO_RESTORED' => 'Portfolio restored',
        'PORTFOLIO_MEMBER_ADDED' => 'Portfolio member added',
        'PORTFOLIO_MEMBER_REMOVED' => 'Portfolio member removed',

        // Project Management: finance
        'PROJECT_FINANCE_ENABLED' => 'Project finance set up',
        'PROJECT_FINANCE_CURRENCY_CHANGED' => 'Project currency changed',
        'PROJECT_FINANCE_ACCESS_CHANGED' => 'Finance access changed',
        'PROJECT_COST_CATEGORY_SAVED' => 'Cost category saved',
        'PROJECT_BUDGET_DRAFTED' => 'Budget drafted',
        'PROJECT_BUDGET_EDITED' => 'Budget draft edited',
        'PROJECT_BUDGET_APPROVED' => 'Budget approved',
        'PROJECT_CASH_RECORDED' => 'Receipt or expense recorded',
        'PROJECT_CASH_CORRECTED' => 'Receipt or expense corrected',
        'PROJECT_CASH_VOIDED' => 'Receipt or expense voided',
        'PROJECT_CASH_ALLOCATED' => 'Cash allocation saved',
        'PROJECT_OPENING_RECORDED' => 'Opening cash recorded',
        'PROJECT_FUNDING_SAVED' => 'Funding commitment saved',
        'PROJECT_CONTRIBUTION_SAVED' => 'Contribution saved',
        'PROJECT_CONTRIBUTION_DELIVERED' => 'Contribution delivery recorded',

        // Project Management: results and reports
        'PROJECT_OUTCOME_SAVED' => 'Objective or deliverable saved',
        'PROJECT_INDICATOR_SAVED' => 'Indicator saved',
        'PROJECT_RESULT_RECORDED' => 'Accomplishment recorded',
        'PROJECT_RESULT_VOIDED' => 'Accomplishment voided',
        'PROJECT_RECORD_FILE_ATTACHED' => 'Evidence file attached',
        'PROJECT_RECORD_FILE_REMOVED' => 'Evidence file removed',
        'PROJECT_RECORD_VALUES_SAVED' => 'Report fields saved',
        'PROJECT_REPORT_CONFIGURED' => 'Report template or field saved',
        'PROJECT_REPORT_DRAFTED' => 'Report drafted',
        'PROJECT_REPORT_SUBMITTED' => 'Report submitted',
        'PROJECT_REPORT_REVIEWED' => 'Report reviewed',

        // Payroll
        'PAYROLL_RUN_CREATED' => 'Payroll created',
        'PAYROLL_RUN_UPDATED' => 'Payroll updated',
        'PAYROLL_RUN_DELETED' => 'Payroll deleted',
        'PAYROLL_FINALIZED' => 'Payroll finalized',
        'INCENTIVE_TYPE_CREATED' => 'Incentive type added',
        'INCENTIVE_TYPE_UPDATED' => 'Incentive type updated',
        'INCENTIVE_TYPE_DELETED' => 'Incentive type deleted',
        'PAYROLL_POSTED' => 'Payroll posted',

        // Community
        'COMMUNITY_POST_PINNED' => 'Community post pinned',
        'COMMUNITY_POST_UNPINNED' => 'Community post unpinned',
        'COMMUNITY_POST_REMOVED' => 'Community post removed',
        'COMMUNITY_COMMENT_REMOVED' => 'Community comment removed',
        'COMMUNITY_GROUP_CREATED' => 'Community group created',
        'COMMUNITY_GROUP_UPDATED' => 'Community group updated',
        'COMMUNITY_GROUP_DELETED' => 'Community group deleted',
        'COMMUNITY_MEMBER_REMOVED' => 'Community group member removed',
        'COMMUNITY_GROUP_ROLE_CHANGED' => 'Community group role changed',
        'COMMUNITY_TAG_CREATED' => 'Community tag created',
        'COMMUNITY_TAG_UPDATED' => 'Community tag updated',
        'COMMUNITY_TAG_DELETED' => 'Community tag deleted',

        // Inventory custody and evidence
        'INVENTORY_ITEM_CREATED' => 'Inventory item added',
        'INVENTORY_ITEM_UPDATED' => 'Inventory item updated',
        'INVENTORY_ITEM_DELETED' => 'Inventory item deleted',
        'INVENTORY_CATALOG_SAVED' => 'Inventory category or location saved',
        'INVENTORY_CATALOG_DELETED' => 'Inventory category or location deleted',
        'INVENTORY_ISSUED' => 'Inventory issued',
        'INVENTORY_TRANSFERRED' => 'Inventory transferred',
        'INVENTORY_RETURNED' => 'Inventory returned',
        'INVENTORY_RECEIPT_CORRECTED' => 'Memorandum receipt corrected',
        'INVENTORY_INCIDENT_RECORDED' => 'Inventory incident recorded',
        'INVENTORY_INCIDENT_RESOLVED' => 'Inventory incident resolved',
        'INVENTORY_DISPOSAL_RECORDED' => 'Inventory disposal recorded',
        'INVENTORY_COUNT_RECORDED' => 'Inventory count recorded',
        'INVENTORY_COUNT_RESOLVED' => 'Inventory count resolved',
        'INVENTORY_SIGNED_MR_ATTACHED' => 'Signed MR attached',
        'INVENTORY_SIGNED_MR_DELETED' => 'Signed MR deleted',
    ];

    /**
     * The tables the audit writers name, plus three that only stored rows
     * carry because nothing writes them (`employee_leave_policies`,
     * `leave_policy_presets`, `leave_conversion_tables`). Inventory's catalog
     * writer names `inventory_<kind>`, singular.
     *
     * @var array<string, string>
     */
    public const array ENTITIES = [
        'attendance_logs' => 'Attendance punch',
        'attendance_remarks' => 'DTR remark',
        'community_comments' => 'Community comment',
        'community_groups' => 'Community group',
        'community_posts' => 'Community post',
        'community_tags' => 'Community tag',
        'document_types' => 'Document type',
        'employee_documents' => 'Employee document',
        'employee_leave_policies' => 'Employee leave policy',
        'employee_profiles' => 'Employee record',
        'employee_record_change_requests' => 'Profile change request',
        'employee_salary_rates' => 'Salary change',
        'employment_statuses' => 'Employment status',
        'holidays' => 'Holiday',
        'inventory_attachments' => 'Signed MR file',
        'inventory_category' => 'Inventory category',
        'inventory_counts' => 'Inventory count',
        'inventory_disposals' => 'Inventory disposal',
        'inventory_incidents' => 'Inventory incident',
        'inventory_items' => 'Inventory item',
        'inventory_location' => 'Inventory location',
        'inventory_receipts' => 'Memorandum receipt',
        'leave_cash_outs' => 'Leave cash conversion',
        'leave_conversion_tables' => 'Leave conversion table',
        'leave_credits' => 'Leave credits',
        'leave_policy_presets' => 'Leave policy preset',
        'leave_rules' => 'Leave rule',
        'leave_travel_requests' => 'Leave/OB request',
        'leave_types' => 'Leave type',
        'org_nodes' => 'Department or unit',
        'overtime_approvals' => 'Overtime decision',
        'payroll_adjustment_categories' => 'Adjustment category',
        'payroll_adjustments' => 'Payroll adjustment',
        'payroll_benefit_ceilings' => 'Tax-free benefit ceiling',
        'payroll_deductions' => 'Payroll deduction',
        'payroll_employee_benefits' => 'Benefit assignment',
        'payroll_formulas' => 'Payroll formula',
        'payroll_gov_deduction_policies' => 'Contributions & tax setting',
        'payroll_incentive_types' => 'Incentive type',
        'payroll_pay_rules' => 'Pay basis & rates',
        'payroll_pay_schedules' => 'Pay schedule',
        'payroll_recurring_line_entries' => 'Loan entry',
        'payroll_recurring_lines' => 'Recurring line',
        'payroll_runs' => 'Payroll',
        'payroll_signatories' => 'Payroll signatory',
        'leave_signatories' => 'Leave form officer',
        'payroll_statutory_tables' => 'Contribution & tax table',
        'positions' => 'Position',
        'project_task_files' => 'Task file',
        'project_task_submissions' => 'Task response',
        'project_tasks' => 'Task',
        'projects' => 'Project',
        'role_module_grants' => 'Module access',
        'roles' => 'Role',
        'roster_day_overrides' => 'Schedule override',
        'roster_locations' => 'Weekly locations',
        'roster_schedule_changes' => 'Scheduled change',
        'roster_shifts' => 'Weekly schedule',
        'shift_types' => 'Shift pattern',
        'shifts' => 'Shift assignment',
        'task_comments' => 'Task comment',
        'user_accounts' => 'User account',
        'users' => 'User account',
        'work_sites' => 'Worksite',
    ];

    /** The stored action code as words; never blank for a non-blank code. */
    public static function action(string $code): string
    {
        return self::ACTIONS[$code] ?? self::humanize($code);
    }

    /** The stored entity (a table name) as words, or null when the row names none. */
    public static function entity(?string $table): ?string
    {
        if ($table === null || trim($table) === '') {
            return null;
        }

        return self::ENTITIES[$table] ?? self::humanize($table);
    }

    /** "SOMETHING_NEW" or "legacy_table" → "Something new" / "Legacy table". */
    private static function humanize(string $code): string
    {
        $words = trim((string) preg_replace('/[\s_]+/', ' ', mb_strtolower($code)));

        return $words === '' ? $code : mb_strtoupper(mb_substr($words, 0, 1)).mb_substr($words, 1);
    }
}
