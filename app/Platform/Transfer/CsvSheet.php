<?php

declare(strict_types=1);

namespace App\Platform\Transfer;

/**
 * An uploaded CSV as {@see CsvReader} hands it on: the header cells and the
 * non-blank data rows, each with the spreadsheet row number a person would
 * look for (the header is row 1).
 */
final readonly class CsvSheet
{
    /**
     * @param  list<string>  $headers
     * @param  list<array{line: int, cells: list<string>}>  $rows
     */
    public function __construct(
        public array $headers,
        public array $rows,
    ) {}
}
