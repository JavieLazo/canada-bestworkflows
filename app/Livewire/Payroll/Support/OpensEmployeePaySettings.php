<?php

declare(strict_types=1);

namespace App\Livewire\Payroll\Support;

use App\Domain\Enums\ModuleCode;
use App\Modules\Workforce\Application\Queries\EmployeePaySettingsRecords;
use App\Support\Access;
use Livewire\Attributes\On;

/**
 * The "Open another employee" search on an employee's pay settings page: the
 * page answers its pick here — an all-data PAYROLL holder is taken to that
 * employee's pay settings, anyone else and any id the owner query does not
 * return stay where they are. The event is client-dispatchable, which is why
 * the pick is checked again here. THE OTHER SETTINGS PAGES HAVE NO EMPLOYEE
 * SEARCH: an employee is opened from their row on the Employees page.
 */
trait OpensEmployeePaySettings
{
    #[On('kit-employee-picked')]
    public function settingsEmployeePicked(string $name, string $id): void
    {
        $access = app(Access::class);

        if ($name !== 'settings-employee' || $id === '' || ! $access->mayReadAllData(ModuleCode::PAYROLL)) {
            return;
        }

        if (app(EmployeePaySettingsRecords::class)->find($access, $id) === null) {
            return;
        }

        $this->redirectRoute('records.employee-pay-settings.show', ['employee' => $id], navigate: true);
    }
}
