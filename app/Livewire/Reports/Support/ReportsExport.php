<?php

declare(strict_types=1);

namespace App\Livewire\Reports\Support;

use App\Platform\Reports\ReportCriteria;
use App\Platform\Transfer\CsvExport;
use App\Platform\Transfer\TableExport;
use App\Support\Access;

/** Downloads the same authorized document as the preview; attendance remains print-only. */
final class ReportsExport implements TableExport
{
    public function export(Access $actor, array $query): CsvExport
    {
        $criteria = ReportCriteria::from($query);
        abort_if($criteria->type === 'attendance-reports', 403);
        $document = ReportCatalog::generate($actor, $criteria);

        return new CsvExport($criteria->type.'-'.$criteria->from.'-'.$criteria->to.'.csv', $document->columns,
            static fn (): array => $document->rows);
    }
}
