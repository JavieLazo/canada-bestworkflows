<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;

/**
 * The module registry — based on `MODULES` (src/lib/hris.ts:31-122),
 * with the sidebar's icon map (src/components/AppSidebar.tsx:45-58) folded in
 * as an `icon` key so one file describes a module completely.
 *
 * ORDER IS THE SPEC. DO NOT SORT THIS ALPHABETICALLY. It is load-bearing three
 * separate times:
 *
 *  1. **Sidebar order** — `ProtectedShell` renders
 *     `MODULES.filter(m => me.modules.includes(m.code))` as-is
 *     (src/app/(protected)/protected-shell.tsx:25).
 *  2. **Sign-in landing** — `landingPath()` returns the first module the role
 *     holds *in this order*, "not in the order the grants happen to come back
 *     from the store" (src/lib/hris.ts:127-134). This is why PORTAL precedes
 *     TASKS: an employee holds both, and must land on My Portal.
 *  3. **Path → module resolution** — `ModuleRegistry::forPath()` walks this
 *     list in order and takes the first match.
 *
 * PROFILE is last on purpose: everyone holds it, and it must never become the
 * module a role lands on at sign-in. It is additionally filtered out of the
 * nav (AppSidebar.tsx:126) because the identity block in the footer is the way
 * into it — see `ModuleRegistry::navFor()` vs `::grantedFor()`.
 *
 * `groups` has TWO shapes, both of which appear here and both of which every
 * consumer must handle (src/lib/hris.ts:25-29):
 *
 *   ['label' => …, 'path'  => …]        a page of its own   (Holiday Settings)
 *   ['label' => …, 'items' => [ … ]]    a folder of pages   (General Records)
 *
 * Ported labels, paths, descriptions and group structure follow hris.ts.
 * Community, Chat and Reports extend that registry with their own grants.
 *
 * @var list<array{
 *     code: string,
 *     label: string,
 *     path: string,
 *     description: string,
 *     icon: string,
 *     groups?: list<array{label: string, path?: string, items?: list<array{label: string, path: string}>}>
 * }>
 */
return [
    // Community (which has no counterpart in the reference) comes FIRST: it
    // heads the sidebar and is the sign-in landing page of every account that
    // holds it — any role can (a fresh install seeds it into every role, an
    // existing one grants it per role in User Management).
    [
        'code' => ModuleCode::COMMUNITY,
        'label' => 'Community',
        'path' => '/community',
        'description' => 'Company-wide posts, groups and informal discussion',
        'icon' => 'newspaper',
    ],
    [
        'code' => ModuleCode::EMPLOYEES,
        'label' => 'Employee Records',
        'path' => '/employees',
        'description' => 'The 201 file: hiring, position, rates and status',
        'icon' => 'id-card',
    ],
    [
        'code' => ModuleCode::EDTR,
        'label' => 'eDTR & Attendance',
        'path' => '/edtr',
        'description' => 'Punch station and live attendance log',
        'icon' => 'fingerprint',
    ],
    [
        'code' => ModuleCode::ROSTER,
        'label' => 'Shift Roster',
        'path' => '/roster',
        'description' => 'Shift pattern and weekly work location per employee',
        'icon' => 'calendar-clock',
    ],
    [
        'code' => ModuleCode::LEAVES,
        'label' => 'Leave Management',
        'path' => '/leaves',
        'description' => 'Leave and business travel requests, and their approval',
        'icon' => 'calendar-check',
    ],
    [
        'code' => ModuleCode::PROJECTS,
        'label' => 'Project Management',
        'path' => '/projects',
        'description' => 'Projects, the tasks inside them, and their review',
        'icon' => 'folder-kanban',
    ],
    [
        'code' => ModuleCode::INVENTORY,
        'label' => 'Inventory',
        'path' => '/inventory',
        'description' => 'Office property, custody and Memorandum Receipts',
        'icon' => 'package',
    ],
    [
        'code' => ModuleCode::PAYROLL,
        'label' => 'Payroll',
        'path' => '/payroll',
        'description' => 'Run and review payroll',
        'icon' => 'banknote',
        // Expands in place like Records Management, with the labels spelled
        // exactly as PayrollRunKind::LABELS spells them.
        'groups' => [
            ['label' => 'Basic Payroll', 'path' => '/payroll'],
            ['label' => '13th Month Payroll', 'path' => '/payroll/thirteenth-month'],
        ],
    ],
    [
        'code' => ModuleCode::REPORTS,
        'label' => 'Reports',
        'path' => '/reports',
        'description' => 'Payroll, employee, leave, attendance and audit reports',
        'icon' => 'chart-no-axes-combined',
    ],
    [
        'code' => ModuleCode::RECORDS,
        'label' => 'Records Management',
        // The module's own path is the first child, not a bare `/records`
        // index — there is no such page. `forPath()` still maps `/records`
        // onto RECORDS, exactly as `module-access.ts:13` does.
        'path' => '/records/positions',
        'description' => 'The option lists the rest of the system chooses from',
        'icon' => 'folder-tree',
        'groups' => [
            [
                'label' => 'General Records',
                'items' => [
                    ['label' => 'Position', 'path' => '/records/positions'],
                    ['label' => 'Departments', 'path' => '/records/departments'],
                    ['label' => 'Document', 'path' => '/records/documents'],
                    ['label' => 'Employment', 'path' => '/records/employment'],
                ],
            ],
            ['label' => 'Holiday Settings', 'path' => '/records/holidays'],
            ['label' => 'Leave Settings', 'path' => '/records/leave-types'],
            ['label' => 'Shifting Settings', 'path' => '/records/shifts'],
            ['label' => 'Site Settings', 'path' => '/records/sites'],
            [
                'label' => 'Payroll Settings',
                'items' => [
                    ['label' => 'Contribution & Tax Tables', 'path' => '/records/contribution-tables'],
                    ['label' => 'Pay Basis & Rates', 'path' => '/records/pay-rates'],
                    ['label' => 'Payroll Deductions', 'path' => '/records/payroll-deductions'],
                    ['label' => 'Incentives & Bonuses', 'path' => '/records/incentive-types'],
                ],
            ],
        ],
    ],
    [
        'code' => ModuleCode::USERS,
        'label' => 'User Management',
        'path' => '/users',
        'description' => 'Accounts, roles and permissions',
        'icon' => 'users',
    ],
    [
        'code' => ModuleCode::AUDIT,
        'label' => 'Audit Trail',
        'path' => '/audit',
        'description' => 'Read-only activity log',
        'icon' => 'scroll-text',
    ],
    [
        'code' => ModuleCode::PORTAL,
        'label' => 'My Portal',
        'path' => '/portal',
        'description' => 'Employee self-service',
        'icon' => 'circle-user-round',
    ],
    // After PORTAL on purpose: an employee holds both, and the first granted
    // module in this order is the sign-in landing — which stays My Portal.
    [
        'code' => ModuleCode::TASKS,
        'label' => 'My Tasks',
        'path' => '/my-tasks',
        'description' => 'Tasks assigned to me, and what I owe on each',
        'icon' => 'list-checks',
    ],
    // Chat sits after every work module but My Profile on purpose: any role
    // can hold it, so a role with a work module still lands on that module
    // rather than on Chat when Community is not granted.
    [
        'code' => ModuleCode::CHAT,
        'label' => 'Chat',
        'path' => '/chat',
        'description' => 'Private one-to-one and group conversations',
        'icon' => 'messages-square',
    ],
    // Last on purpose: everyone holds it, and it must never become the module a
    // role lands on at sign-in.
    [
        'code' => ModuleCode::PROFILE,
        'label' => 'My Profile',
        'path' => '/profile',
        'description' => 'The details you maintain about yourself',
        'icon' => 'user-round-cog',
    ],
];
