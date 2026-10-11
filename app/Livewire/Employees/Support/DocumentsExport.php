<?php

declare(strict_types=1);

namespace App\Livewire\Employees\Support;

use App\Domain\Enums\ModuleCode;
use App\Livewire\Employees\EmployeeFields;
use App\Modules\Workforce\Application\Queries\EmployeeDocuments;
use App\Modules\Workforce\Application\Queries\EmployeeRecords;
use App\Platform\Transfer\CsvExport;
use App\Platform\Transfer\TableExport;
use App\Support\Access;
use App\Support\ManilaClock;
use Generator;

/**
 * One employee's Documents card as CSV — the list of what is on file, newest
 * first, through the same {@see EmployeeDocuments::page()} read and its
 * Employee Records management rule. The files themselves never leave through
 * here; each still downloads through the same authorization boundary. Export only: a document is
 * attached one file at a time.
 */
final class DocumentsExport implements TableExport
{
    public function export(Access $actor, array $query): CsvExport
    {
        abort_unless($actor->mayReadAllData(ModuleCode::EMPLOYEES), 403);
        $employee = app(EmployeeRecords::class)->find($actor, $query['employee'] ?? '');
        abort_if($employee === null, 404);
        $employeeId = (string) $employee->getKey();

        $category = $query['category'] ?? '';
        $rows = static function () use ($actor, $employeeId, $category): Generator {
            $documents = app(EmployeeDocuments::class);
            foreach (CsvExport::pages(static fn (int $page, int $perPage) => $documents->page($actor, $employeeId, $page, $perPage, $category)) as $doc) {
                yield [
                    $doc->document_no ?? '', $doc->name, $doc->file_name, EmployeeFields::humanSize((int) $doc->size),
                    EmployeeFields::categoryLabel($doc->category), $doc->expires_on?->format('Y-m-d') ?? '',
                ];
            }
        };

        return new CsvExport(
            'documents-'.$employee->employee_code.'-'.ManilaClock::todayKey().'.csv',
            ['ID', 'Document', 'File', 'Size', 'Category', 'Expires'],
            $rows,
        );
    }
}
