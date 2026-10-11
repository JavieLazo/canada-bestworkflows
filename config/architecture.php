<?php

declare(strict_types=1);

// Business ownership is independent of navigation grants and Livewire aliases.
// The existing model identities remain stable during incremental extraction.
return [
    'models' => [
        'IdentityAccess' => ['User', 'UserPasswordHistory', 'Role', 'RoleModuleGrant'],
        'Workforce' => ['EmployeeRecordChangeRequest', 'EmployeeProfile', 'EmployeeChild', 'EmployeePdsDetail', 'EmployeeAddress', 'EmployeeEducation', 'EmployeeEligibility', 'EmployeeWorkExperience', 'EmployeeTraining', 'EmployeeVoluntaryWork', 'EmployeeOtherItem', 'EmployeeEmploymentHistory', 'EmployeeDocument', 'DocumentType', 'EmploymentStatusOption'],
        'Organization' => ['WorkSite', 'Position', 'Holiday', 'OrgNode'],
        'Timekeeping' => ['AttendanceLog', 'AttendanceRemark', 'Shift', 'ShiftType', 'RosterLocation', 'RosterShift', 'RosterDayOverride', 'RosterScheduleChange', 'LeaveTravelRequest', 'LeaveRequestCancellation', 'LeaveType', 'LeaveCredit', 'LeaveCreditEntry', 'OvertimeApproval'],
        'WorkManagement' => ['Project', 'ProjectTask', 'ProjectTaskAssignee', 'ProjectTaskFile', 'ProjectTaskSubmission', 'TaskComment'],
        'Inventory' => ['InventoryItem', 'InventoryCategory', 'InventoryLocation', 'InventoryReceipt', 'InventoryReceiptLine', 'InventoryMovement', 'InventoryIncident', 'InventoryDisposal', 'InventoryCount', 'InventoryCountLine', 'InventoryAttachment'],
        'Payroll' => ['PayrollRun', 'PayrollRunMember', 'Payslip', 'PayrollFormula', 'PayrollDeduction', 'PayrollFinalizationSnapshot', 'PayrollIncentiveType', 'PayrollRunMemberIncentive', 'PayslipIncentive', 'PayrollRunMemberDeduction', 'PayslipDeduction', 'PayrollStatutoryTable', 'PayrollPayRule'],
        'Social' => [
            'CommunityGroup', 'CommunityGroupMember', 'CommunityPost', 'CommunityAttachment', 'CommunityComment',
            'CommunityReaction', 'CommunityCommentReaction', 'ChatConversation', 'ChatParticipant', 'ChatMessage',
            'ChatAttachment', 'ChatReaction', 'SocialPreference', 'CommunityTag',
        ],
        'Platform' => ['AuditLog', 'Notification'],
    ],
];
