<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Queries;

use App\Domain\Enums\ModuleCode;
use App\Modules\Workforce\Infrastructure\PdsWorkbookTemplate;
use App\Support\Access;
use Illuminate\Support\Str;

/** Exports the official employee record through the same scope as Employee Records. */
final class EmployeePdsWorkbook
{
    /** @return array{filename: string, content: string} */
    public function download(Access $actor, string $employeeId): array
    {
        abort_unless($actor->hasModule(ModuleCode::EMPLOYEES), 403);
        $employee = app(EmployeeRecords::class)->find($actor, $employeeId, details: true);
        abort_if($employee === null, 403);

        $sheet = app(PdsRows::class)->sheet($actor, $employee->id);
        $name = Str::slug($employee->last_name.' '.$employee->first_name.' '.$employee->employee_code);

        return [
            'filename' => 'PDS-'.($name === '' ? $employee->id : $name).'.xlsx',
            'content' => app(PdsWorkbookTemplate::class)->render($employee, $sheet),
        ];
    }
}
