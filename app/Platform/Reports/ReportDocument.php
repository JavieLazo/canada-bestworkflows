<?php

declare(strict_types=1);

namespace App\Platform\Reports;

use Illuminate\Validation\ValidationException;

/** Detached printable tables; no persistence or hidden queries can enter the document. */
final readonly class ReportDocument
{
    public const int MAX_ROWS = 5000;

    /**
     * @param  list<string>  $columns
     * @param  list<list<string>>  $rows
     * @param  list<string>  $notes
     * @param  list<array{title: string, columns: list<string>, rows: list<list<string>>, notes?: list<string>, kind?: 'table'|'payslip'|'attendance', identity?: list<array{label: string, value: string}>, highlights?: list<array{label: string, value: string}>, groups?: list<array{title: string, rows: list<list<string>>, notes?: list<string>}>, totals?: list<string>, numericColumns?: list<int>}>  $sections
     * @param  list<array{label: string, value: string}>  $summary
     * @param  list<string>  $totals
     * @param  list<int>  $numericColumns
     * @param  list<string>  $columnKeys
     * @param  list<array{title: string, unit: string, points: list<array{label: string, value: int|float}>, note: string, kind?: 'bar'|'line'}>  $charts
     */
    public function __construct(
        public string $title,
        public array $columns,
        public array $rows,
        public array $notes = [],
        public array $sections = [],
        public array $summary = [],
        public array $totals = [],
        public array $numericColumns = [],
        public array $columnKeys = [],
        public array $charts = [],
    ) {}

    public static function guardSize(int $count): void
    {
        if ($count > self::MAX_ROWS) {
            throw ValidationException::withMessages(['report' => 'This report exceeds 5,000 source records. Choose a shorter period or fewer employees. No partial report was generated.']);
        }
    }
}
