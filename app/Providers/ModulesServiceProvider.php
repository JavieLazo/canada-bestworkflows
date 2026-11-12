<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Middleware\EnsureModuleAccess;
use App\Livewire\Audit\Support\AuditExport;
use App\Livewire\Edtr\Support\PunchesExport;
use App\Livewire\Employees\Support\DocumentsExport;
use App\Livewire\Employees\Support\EmployeesExport;
use App\Livewire\Employees\Support\EmploymentHistoryExport;
use App\Livewire\Inventory\Support\CountsExport;
use App\Livewire\Inventory\Support\DisposalsExport;
use App\Livewire\Inventory\Support\EmployeeItemsExport;
use App\Livewire\Inventory\Support\IncidentsExport;
use App\Livewire\Inventory\Support\InventoryReportsExport;
use App\Livewire\Inventory\Support\ItemsExport;
use App\Livewire\Inventory\Support\ReceiptsExport;
use App\Livewire\Leaves\Support\LeaveCreditsExport;
use App\Livewire\Leaves\Support\LeaveLedgerExport;
use App\Livewire\Leaves\Support\LeaveRequestsExport;
use App\Livewire\Payroll\Support\AdjustmentsExport;
use App\Livewire\Payroll\Support\PayrollRunsExport;
use App\Livewire\Records\ReferenceListPage;
use App\Livewire\Records\Support\HolidaysExport;
use App\Livewire\Records\Support\IncentiveTypesExport;
use App\Livewire\Records\Support\PaySchedulesExport;
use App\Livewire\Records\Support\ShiftTypesExport;
use App\Livewire\Records\Support\SitesExport;
use App\Livewire\Reports\Support\ReportsExport;
use App\Livewire\Tasks\Support\MyTasksExport;
use App\Livewire\Users\Support\AccountsExport;
use App\Livewire\Users\Support\RolesExport;
use App\Modules\IdentityAccess\Application\AccountRetention;
use App\Modules\IdentityAccess\Contracts\AccountNames;
use App\Modules\IdentityAccess\Contracts\ModuleAccounts;
use App\Modules\IdentityAccess\Contracts\ProjectAccounts;
use App\Modules\IdentityAccess\Infrastructure\AccountEmployeeLinkQuery;
use App\Modules\IdentityAccess\Infrastructure\AccountNameQuery;
use App\Modules\IdentityAccess\Infrastructure\EmployeeReferences;
use App\Modules\IdentityAccess\Infrastructure\ModuleAccountQuery;
use App\Modules\IdentityAccess\Infrastructure\NotificationRecipientQuery;
use App\Modules\IdentityAccess\Infrastructure\ProjectAccountQuery;
use App\Modules\Inventory\Application\Commands\ImportItems;
use App\Modules\Organization\Application\Commands\ImportHolidays;
use App\Modules\Organization\Application\Commands\ImportSites;
use App\Modules\Organization\Application\PositionRenames;
use App\Modules\Organization\Application\PositionRetention;
use App\Modules\Organization\Application\SiteRetention;
use App\Modules\Organization\Contracts\HolidayCalendar;
use App\Modules\Organization\Contracts\OrganizationDirectory;
use App\Modules\Organization\Contracts\OrganizationReferences;
use App\Modules\Organization\Contracts\PunchSites;
use App\Modules\Organization\Infrastructure\HolidayCalendarQuery;
use App\Modules\Organization\Infrastructure\OrganizationDirectoryQuery;
use App\Modules\Organization\Infrastructure\OrganizationReferenceQuery;
use App\Modules\Organization\Infrastructure\PunchSiteQuery;
use App\Modules\Payroll\Application\Catalogs\Deductions;
use App\Modules\Payroll\Application\Commands\ImportIncentiveTypes;
use App\Modules\Payroll\Infrastructure\OpenPayrollDraftQuery;
use App\Modules\Payroll\Infrastructure\OpenPunchLimitQuery;
use App\Modules\Payroll\Infrastructure\PostedPayrollDateQuery;
use App\Modules\Timekeeping\Application\Catalogs\LeaveTypes;
use App\Modules\Timekeeping\Application\Commands\ImportShiftTypes;
use App\Modules\Timekeeping\Contracts\AttendanceSource;
use App\Modules\Timekeeping\Contracts\LeaveCashOutPoster;
use App\Modules\Timekeeping\Contracts\LeaveCashOutSource;
use App\Modules\Timekeeping\Contracts\LeaveChargePoster;
use App\Modules\Timekeeping\Contracts\LeaveChargeSource;
use App\Modules\Timekeeping\Contracts\OpenPayrollDrafts;
use App\Modules\Timekeeping\Contracts\OpenPunchLimit;
use App\Modules\Timekeeping\Contracts\PostedPayrollDates;
use App\Modules\Timekeeping\Contracts\ScheduleNotices;
use App\Modules\Timekeeping\Infrastructure\AttendanceInputQuery;
use App\Modules\Timekeeping\Infrastructure\LeaveCashOutQuery;
use App\Modules\Timekeeping\Infrastructure\LeaveChargeQuery;
use App\Modules\Timekeeping\Infrastructure\ScheduleNoticeQuery;
use App\Modules\Workforce\Application\Catalogs\DocumentTypes;
use App\Modules\Workforce\Application\Catalogs\EmploymentStatuses;
use App\Modules\Workforce\Application\EmployeeRetention;
use App\Modules\Workforce\Contracts\AssignmentEmployees;
use App\Modules\Workforce\Contracts\ColleagueDirectory;
use App\Modules\Workforce\Contracts\EmployeeDirectory;
use App\Modules\Workforce\Contracts\EmployeeLeaveFacts;
use App\Modules\Workforce\Contracts\EmployeeNames;
use App\Modules\Workforce\Contracts\EmployeePayContexts;
use App\Modules\Workforce\Contracts\EmployeeReportContext;
use App\Modules\Workforce\Contracts\InventoryEmployees;
use App\Modules\Workforce\Contracts\OperationalDirectory;
use App\Modules\Workforce\Contracts\PunchEmployees;
use App\Modules\Workforce\Contracts\SalaryHistory;
use App\Modules\Workforce\Infrastructure\AssignmentEmployeeQuery;
use App\Modules\Workforce\Infrastructure\ColleagueDirectoryQuery;
use App\Modules\Workforce\Infrastructure\EmployeeDirectoryQuery;
use App\Modules\Workforce\Infrastructure\EmployeeLeaveFactQuery;
use App\Modules\Workforce\Infrastructure\EmployeeNameQuery;
use App\Modules\Workforce\Infrastructure\EmployeePayContextQuery;
use App\Modules\Workforce\Infrastructure\EmployeeReportContextQuery;
use App\Modules\Workforce\Infrastructure\InventoryEmployeeQuery;
use App\Modules\Workforce\Infrastructure\OperationalDirectoryQuery;
use App\Modules\Workforce\Infrastructure\PositionNameCopies;
use App\Modules\Workforce\Infrastructure\PositionReferences;
use App\Modules\Workforce\Infrastructure\PunchEmployeeQuery;
use App\Modules\Workforce\Infrastructure\RecordChangeStorageUsage;
use App\Modules\Workforce\Infrastructure\ReminderData;
use App\Modules\Workforce\Infrastructure\SalaryHistoryQuery;
use App\Modules\Workforce\Infrastructure\SiteReferences;
use App\Modules\Workforce\Infrastructure\StorageUsage;
use App\Modules\WorkManagement\Application\Commands\ImportProjects;
use App\Modules\WorkManagement\Application\Commands\ImportTasks;
use App\Platform\Notifications\NotificationRecipients;
use App\Platform\Notifications\ReminderCatalog;
use App\Platform\Records\AccountEmployeeLinks;
use App\Platform\Records\CatalogExport;
use App\Platform\Records\CatalogImport;
use App\Platform\Records\ReferenceCatalogs;
use App\Platform\Retention\AccountReferences;
use App\Platform\Retention\EmployeeObligations;
use App\Platform\Transfer\TableExport;
use App\Platform\Transfer\TableImport;
use App\Platform\Transfer\TableTransfers;
use Closure;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

/** Composition root for module contracts; actor state remains request scoped. */
final class ModulesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(InventoryEmployees::class, InventoryEmployeeQuery::class);
        $this->app->tag([\App\Modules\Inventory\Infrastructure\StorageUsage::class], 'attachment-usage-sources');
        $this->app->tag([\App\Modules\Inventory\Infrastructure\StorageUsage::class], 'stored-file-sources');
        $this->app->tag([\App\Modules\Inventory\Infrastructure\EmployeeReferences::class], 'employee-reference-sources');
        $this->app->tag([\App\Modules\Inventory\Infrastructure\AccountReferences::class], 'account-reference-sources');
        $this->app->tag([\App\Modules\Inventory\Infrastructure\EmployeeObligations::class, \App\Modules\Payroll\Infrastructure\EmployeeObligations::class], 'employee-obligation-sources');
        $this->app->bind(EmployeeObligations::class, fn ($app) => new EmployeeObligations($app->tagged('employee-obligation-sources')));
        $this->app->bind(AccountNames::class, AccountNameQuery::class);
        $this->app->bind(EmployeeNames::class, EmployeeNameQuery::class);
        $this->app->bind(AccountEmployeeLinks::class, AccountEmployeeLinkQuery::class);
        $this->app->bind(OperationalDirectory::class, OperationalDirectoryQuery::class);
        $this->app->bind(OrganizationDirectory::class, OrganizationDirectoryQuery::class);
        $this->app->tag([StorageUsage::class, \App\Modules\WorkManagement\Infrastructure\StorageUsage::class], 'attachment-usage-sources');
        $this->app->tag([RecordChangeStorageUsage::class], 'attachment-usage-sources');
        $this->app->tag([RecordChangeStorageUsage::class], 'stored-file-sources');
        // Every owner with files on the disk, for `ems:check-files`. Community
        // and Chat are here but not in the budget above: they keep their own
        // (copems.uploads.social_storage_ceiling_bytes).
        $this->app->tag([StorageUsage::class, \App\Modules\WorkManagement\Infrastructure\StorageUsage::class, \App\Modules\Social\Infrastructure\StorageUsage::class], 'stored-file-sources');
        $this->app->tag([ReminderData::class, \App\Modules\Timekeeping\Infrastructure\ReminderData::class, \App\Modules\WorkManagement\Infrastructure\ReminderData::class, \App\Modules\Payroll\Infrastructure\ReminderData::class], 'reminder-data-sources');
        $this->app->bind(NotificationRecipients::class, NotificationRecipientQuery::class);
        $this->app->bind(ReminderCatalog::class, fn ($app) => new ReminderCatalog($app->tagged('reminder-data-sources')));
        $this->app->bind(OpenPunchLimit::class, OpenPunchLimitQuery::class);
        $this->app->bind(PostedPayrollDates::class, PostedPayrollDateQuery::class);
        $this->app->bind(OpenPayrollDrafts::class, OpenPayrollDraftQuery::class);
        $this->app->bind(PunchSites::class, PunchSiteQuery::class);
        $this->app->bind(PunchEmployees::class, PunchEmployeeQuery::class);
        $this->app->bind(ReferenceCatalogs::class, fn () => new ReferenceCatalogs(['documents' => new DocumentTypes, 'employment' => new EmploymentStatuses, 'leave-types' => new LeaveTypes, 'payroll-deductions' => new Deductions]));
        $this->app->tag([SiteReferences::class, \App\Modules\Timekeeping\Infrastructure\SiteReferences::class, \App\Modules\WorkManagement\Infrastructure\SiteReferences::class], 'site-reference-sources');
        $this->app->bind(SiteRetention::class, fn ($app) => new SiteRetention($app->tagged('site-reference-sources')));
        // A position stays while any employee record holds it, and a rename
        // carries the new name onto every holder's copy in the same
        // transaction: Workforce answers both through Platform ports, so
        // Organization never reaches into Workforce.
        $this->app->tag([PositionReferences::class], 'position-reference-sources');
        $this->app->bind(PositionRetention::class, fn ($app) => new PositionRetention($app->tagged('position-reference-sources')));
        $this->app->tag([PositionNameCopies::class], 'position-name-copies');
        $this->app->bind(PositionRenames::class, fn ($app) => new PositionRenames($app->tagged('position-name-copies')));
        $this->app->bind(ProjectAccounts::class, ProjectAccountQuery::class);
        $this->app->bind(AssignmentEmployees::class, AssignmentEmployeeQuery::class);
        $this->app->tag([EmployeeReferences::class, \App\Modules\Workforce\Infrastructure\EmployeeReferences::class, \App\Modules\Timekeeping\Infrastructure\EmployeeReferences::class, \App\Modules\Payroll\Infrastructure\EmployeeReferences::class, \App\Modules\WorkManagement\Infrastructure\EmployeeReferences::class], 'employee-reference-sources');
        $this->app->bind(EmployeeRetention::class, fn ($app) => new EmployeeRetention($app->tagged('employee-reference-sources')));
        $this->app->bind(OrganizationReferences::class, OrganizationReferenceQuery::class);
        $this->app->tag([AccountReferences::class, \App\Modules\WorkManagement\Infrastructure\AccountReferences::class, \App\Modules\Payroll\Infrastructure\AccountReferences::class, \App\Modules\Timekeeping\Infrastructure\AccountReferences::class, \App\Modules\Workforce\Infrastructure\AccountReferences::class], 'account-reference-sources');
        $this->app->bind(AccountRetention::class, fn ($app) => new AccountRetention($app->tagged('account-reference-sources')));
        $this->app->bind(EmployeeDirectory::class, EmployeeDirectoryQuery::class);
        $this->app->bind(EmployeeReportContext::class, EmployeeReportContextQuery::class);
        $this->app->bind(EmployeePayContexts::class, EmployeePayContextQuery::class);
        $this->app->bind(SalaryHistory::class, SalaryHistoryQuery::class);
        $this->app->bind(EmployeeLeaveFacts::class, EmployeeLeaveFactQuery::class);
        $this->app->bind(AttendanceSource::class, AttendanceInputQuery::class);
        $this->app->bind(LeaveChargeSource::class, LeaveChargeQuery::class);
        $this->app->bind(LeaveChargePoster::class, LeaveChargeQuery::class);
        $this->app->bind(LeaveCashOutSource::class, LeaveCashOutQuery::class);
        $this->app->bind(LeaveCashOutPoster::class, LeaveCashOutQuery::class);
        $this->app->bind(ScheduleNotices::class, ScheduleNoticeQuery::class);
        $this->app->bind(HolidayCalendar::class, HolidayCalendarQuery::class);
        $this->app->bind(ModuleAccounts::class, ModuleAccountQuery::class);
        $this->app->bind(ColleagueDirectory::class, ColleagueDirectoryQuery::class);
        // Community and Chat (the Social module) join the account references
        // above. tag() adds to a tag instead of replacing it, so this line
        // leaves that list as it is.
        $this->app->tag([\App\Modules\Social\Infrastructure\AccountReferences::class], 'account-reference-sources');
        $this->app->bind(TableTransfers::class, fn (): TableTransfers => new TableTransfers($this->tableExports(), $this->tableImports()));
    }

    /**
     * Every table's CSV export, keyed as its route default names it. Each
     * owner reads through the same query as its screen. My Portal has no
     * CSV exports. These other tables have
     * no CSV at all: the Shift Roster (no export, import or Scheduled
     * changes file); the Daily Time Record, whose computed days are read on
     * the tab and corrected in its dialog, never downloaded; and the
     * General Records lists — Position and Departments & Units, which have
     * pages of their own, and Document and Employment — and the leave types
     * on Leave Settings, the three {@see ReferenceListPage::csvLists()}
     * leaves out.
     *
     * @return array<string, Closure(): TableExport>
     */
    private function tableExports(): array
    {
        $exports = [];
        foreach (ReferenceListPage::csvLists() as $slug => $list) {
            $exports['records.'.$slug] = static fn (): TableExport => new CatalogExport($slug, $list);
        }

        return $exports + [
            'inventory.items' => static fn (): TableExport => new ItemsExport,
            'inventory.receipts' => static fn (): TableExport => new ReceiptsExport,
            'inventory.reports' => static fn (): TableExport => new InventoryReportsExport,
            'inventory.incidents' => static fn (): TableExport => new IncidentsExport,
            'inventory.disposals' => static fn (): TableExport => new DisposalsExport,
            'inventory.counts' => static fn (): TableExport => new CountsExport,
            'employees.inventory' => static fn (): TableExport => new EmployeeItemsExport,
            'reports' => static fn (): TableExport => new ReportsExport,
            'records.sites' => static fn (): TableExport => new SitesExport,
            'records.holidays' => static fn (): TableExport => new HolidaysExport,
            'records.shifts' => static fn (): TableExport => new ShiftTypesExport,
            'records.incentive-types' => static fn (): TableExport => new IncentiveTypesExport,
            'records.pay-schedules' => static fn (): TableExport => new PaySchedulesExport,
            'employees' => static fn (): TableExport => new EmployeesExport,
            'employees.history' => static fn (): TableExport => new EmploymentHistoryExport,
            'leaves.credits' => static fn (): TableExport => new LeaveCreditsExport,
            'leaves.ledger' => static fn (): TableExport => new LeaveLedgerExport,
            'audit' => static fn (): TableExport => new AuditExport,
            'leaves' => static fn (): TableExport => new LeaveRequestsExport,
            'payroll' => static fn (): TableExport => new PayrollRunsExport,
            'payroll.adjustments' => static fn (): TableExport => new AdjustmentsExport,
            'users' => static fn (): TableExport => new AccountsExport,
            'users.roles' => static fn (): TableExport => new RolesExport,
            'edtr.feed' => static fn (): TableExport => new PunchesExport,
            'tasks' => static fn (): TableExport => new MyTasksExport,
            'employees.documents' => static fn (): TableExport => new DocumentsExport,
        ];
    }

    /**
     * The tables that take a CSV import: the Records settings (payroll
     * deductions, sites, holidays, shift patterns, incentive types),
     * projects and tasks. Audit, payroll evidence, punches, employees, the
     * roster, leave credits, the leave types, self-service rows and the
     * General Records lists (Position, Departments & Units, Document,
     * Employment) have no import.
     *
     * @return array<string, Closure(): TableImport>
     */
    private function tableImports(): array
    {
        $imports = [];
        foreach (ReferenceListPage::csvLists() as $slug => $list) {
            $imports['records.'.$slug] = static fn (): TableImport => new CatalogImport($slug, $list);
        }

        return $imports + [
            'inventory.items' => static fn (): TableImport => new ImportItems,
            'records.sites' => static fn (): TableImport => new ImportSites,
            'records.holidays' => static fn (): TableImport => new ImportHolidays,
            'records.shifts' => static fn (): TableImport => new ImportShiftTypes,
            'records.incentive-types' => static fn (): TableImport => new ImportIncentiveTypes,
            // Both read back WorkExports' existing CSVs (projects.export, projects.tasks.export).
            'projects' => static fn (): TableImport => new ImportProjects,
            'projects.tasks' => static fn (): TableImport => new ImportTasks,
        ];
    }

    public function boot(): void
    {
        Livewire::addPersistentMiddleware([EnsureModuleAccess::class]);
    }
}
