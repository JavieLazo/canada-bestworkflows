<?php

declare(strict_types=1);

// Business ownership is independent of navigation grants and Livewire aliases.
// The existing model identities remain stable during incremental extraction.
return [
    'models' => [
        'IdentityAccess' => ['User', 'UserPasswordHistory', 'Role', 'RoleModuleGrant', 'EdtrMobileToken'],
        'Workforce' => ['EmployeeRecordChangeRequest', 'EmployeeProfile', 'EmployeeChild', 'EmployeePdsDetail', 'EmployeeAddress', 'EmployeeEducation', 'EmployeeEligibility', 'EmployeeWorkExperience', 'EmployeeTraining', 'EmployeeVoluntaryWork', 'EmployeeOtherItem', 'EmployeeEmploymentHistory', 'EmployeeDocument', 'DocumentType', 'EmploymentStatusOption', 'EmployeeSalaryRate'],
        'Organization' => ['WorkSite', 'Position', 'Holiday', 'OrgNode'],
        'Timekeeping' => ['AttendanceLog', 'AttendanceRemark', 'Shift', 'ShiftType', 'RosterLocation', 'RosterShift', 'RosterDayOverride', 'RosterScheduleChange', 'LeaveTravelRequest', 'LeaveSignatory', 'LeaveRequestCancellation', 'LeaveType', 'LeaveCredit', 'LeaveCreditEntry', 'OvertimeApproval', 'LeaveConversionTable', 'LeaveEarningPosting', 'LeaveAttendanceCharge', 'LeaveCashOut', 'LeaveRule', 'LeaveAcquisition', 'EdtrMobilePunchReceipt'],
        'WorkManagement' => ['Project', 'ProjectTask', 'ProjectTaskAssignee', 'ProjectTaskFile', 'ProjectTaskSubmission', 'TaskComment', 'ProjectClient', 'ProjectClientMember', 'ProjectTaskGroup', 'ProjectMember', 'ProjectPortfolio', 'ProjectPortfolioMember', 'ProjectFinance', 'ProjectFinanceMember', 'ProjectCostCategory', 'ProjectBudget', 'ProjectBudgetLine', 'ProjectCashEntry', 'ProjectAvailability', 'ProjectTaskEffort', 'ProjectOutcome', 'ProjectIndicator', 'ProjectResultEntry', 'ProjectRecordFile', 'ProjectReportingPeriod', 'ProjectReportField', 'ProjectRecordValue', 'ProjectReportTemplate', 'ProjectReport', 'ProjectReportSubmission', 'ProjectFunding', 'ProjectFundingInstallment', 'ProjectContribution', 'ProjectContributionDelivery', 'ProjectCashAllocation'],
        'Inventory' => ['InventoryItem', 'InventoryCategory', 'InventoryLocation', 'InventoryReceipt', 'InventoryReceiptLine', 'InventoryMovement', 'InventoryIncident', 'InventoryDisposal', 'InventoryCount', 'InventoryCountLine', 'InventoryAttachment'],
        'Payroll' => ['PayrollRun', 'PayrollRunMember', 'Payslip', 'PayrollFormula', 'PayrollDeduction', 'PayrollFinalizationSnapshot', 'PayrollIncentiveType', 'PayrollRunMemberIncentive', 'PayslipIncentive', 'PayrollRunMemberDeduction', 'PayslipDeduction', 'PayrollStatutoryTable', 'PayrollPayRule', 'PayrollRecurringLine', 'PayslipSettlement', 'PayrollSignatory', 'PayrollGovDeductionPolicy', 'PayrollPaySchedule', 'PayrollAdjustmentCategory', 'PayrollAdjustment', 'PayrollBenefitCeiling', 'PayrollEmployeeBenefit', 'PayrollTaxBalance', 'PayrollRecurringLineEntry', 'PayrollEmployeeTaxDetail'],
        'Social' => [
            'CommunityGroup', 'CommunityGroupMember', 'CommunityPost', 'CommunityAttachment', 'CommunityComment',
            'CommunityReaction', 'CommunityCommentReaction', 'ChatConversation', 'ChatParticipant', 'ChatMessage',
            'ChatAttachment', 'ChatReaction', 'SocialPreference', 'CommunityTag',
        ],
        'Platform' => ['AuditLog', 'Notification'],
    ],
];
