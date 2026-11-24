<?php

declare(strict_types=1);

use App\Platform\Transfer\CsvReader;
use App\Platform\Transfer\ImportRow;
use App\Support\Csv;

/**
 * An import reads back what an export wrote, and what Excel, Sheets or
 * Numbers make of it: BOM or none, Windows-1252, semicolons, quoted line
 * breaks, the formula guard's apostrophe, and Excel's reformatted dates,
 * times, amounts and long numbers. Nothing is guessed silently: an
 * ambiguous or mangled cell is refused with its column's name.
 */
/** @param array<string, string> $cells */
function csvParseRow(array $cells, string $order = ImportRow::MONTH_FIRST): ImportRow
{
    return new ImportRow(2, $cells, $order);
}

it('reads back exactly what Csv::build wrote, formula guard included', function () {
    $bytes = Csv::build(['ID', 'Name', 'Description'], [
        ['7', '=HYPERLINK("x")', "Line one\r\nLine \"two\""],
        ['8', '+63 917 555 0101', '-'],
    ]);

    $sheet = CsvReader::parse($bytes, 10);

    expect($sheet->headers)->toBe(['ID', 'Name', 'Description'])
        ->and($sheet->rows)->toBe([
            ['line' => 2, 'cells' => ['7', '=HYPERLINK("x")', "Line one\nLine \"two\""]],
            ['line' => 3, 'cells' => ['8', '+63 917 555 0101', '-']],
        ]);
});

it('reads Windows-1252 and UTF-16 files, and semicolon or tab delimiters', function () {
    $ansi = mb_convert_encoding("Name;City\r\nPeña;Parañaque\r\n", 'Windows-1252', 'UTF-8');
    expect(CsvReader::parse($ansi, 10)->rows[0]['cells'])->toBe(['Peña', 'Parañaque']);

    $utf16 = "\xFF\xFE".mb_convert_encoding("Name\tCity\nJosé\tCebú\n", 'UTF-16LE', 'UTF-8');
    expect(CsvReader::parse($utf16, 10)->rows[0]['cells'])->toBe(['José', 'Cebú']);
});

it('numbers rows as the spreadsheet does, skipping blank ones and trimming stray columns', function () {
    $sheet = CsvReader::parse("\n\"ID\",\"Name\",,\r\n1,Ana,,\r\n,,,\r\n2,\"Ben\nReyes\",,\r\n3,Cy,,\r\n", 10);

    expect($sheet->headers)->toBe(['ID', 'Name'])
        ->and(array_column($sheet->rows, 'line'))->toBe([3, 5, 6]);
});

it('refuses a workbook, a binary file, an empty file and too many rows', function (string $bytes, string $message) {
    expect(fn () => CsvReader::parse($bytes, 2))->toThrow(RuntimeException::class, $message);
})->with([
    'xlsx' => ["PK\x03\x04rest", 'This is an Excel workbook'],
    'binary' => ["ID,Name\n1,\0x", 'This is not a CSV file'],
    'empty' => ["\r\n\r\n", 'The file is empty.'],
    'rows' => ["ID\n1\n2\n3\n", 'more than 2 rows'],
]);

it('reads dates in ISO, spelled-out and the file\'s own slashed order', function () {
    $row = csvParseRow(['A' => '2026-09-28', 'B' => '28 Sep 2026', 'C' => 'Sep 28, 2026', 'D' => '9/28/2026', 'E' => '']);

    expect($row->date('A'))->toBe('2026-09-28')
        ->and($row->date('B'))->toBe('2026-09-28')
        ->and($row->date('C'))->toBe('2026-09-28')
        ->and($row->date('D'))->toBe('2026-09-28')
        ->and($row->date('E'))->toBeNull()
        ->and(csvParseRow(['D' => '28/09/2026'], ImportRow::DAY_FIRST)->date('D'))->toBe('2026-09-28');
});

it('refuses impossible, two-digit-year and mixed-order dates', function (string $cell, string $order, string $message) {
    expect(fn () => csvParseRow(['Birthday' => $cell], $order)->date('Birthday'))
        ->toThrow(RuntimeException::class, $message);
})->with([
    'impossible' => ['2/30/2026', ImportRow::MONTH_FIRST, 'Birthday: 2/30/2026 is not a date.'],
    'short year' => ['9/28/26', ImportRow::MONTH_FIRST, 'write the year of 9/28/26 in full'],
    'mixed' => ['4/9/2026', ImportRow::MIXED, 'mixes day-first and month-first'],
    'serial' => ['46293', ImportRow::MONTH_FIRST, 'Birthday: 46293 is not a date.'],
]);

it('reads times, amounts, whole numbers, flags and labelled choices as the screens store them', function () {
    $row = csvParseRow([
        'In' => '8:00 AM', 'Out' => '17:30:00', 'Late' => '12:15 pm',
        'Rate' => '$25,000.50', 'Loan' => '(1,200)', 'Plain' => '-15',
        'Days' => '1,000', 'Active' => 'Yes', 'SSS' => 'no', 'Status' => 'active',
    ]);

    expect($row->time('In'))->toBe('08:00')
        ->and($row->time('Out'))->toBe('17:30')
        ->and($row->time('Late'))->toBe('12:15')
        ->and($row->money('Rate'))->toBe('25000.50')
        ->and($row->money('Loan'))->toBe('-1200')
        ->and($row->money('Plain'))->toBe('-15')
        ->and($row->integer('Days'))->toBe(1000)
        ->and($row->flag('Active'))->toBeTrue()
        ->and($row->flag('SSS'))->toBeFalse()
        ->and($row->choice('Status', ['ACTIVE' => 'Active', 'ON_HOLD' => 'On hold']))->toBe('ACTIVE');
});

it('refuses a decimal comma, Excel\'s shortened numbers and unknown labels', function (Closure $read, string $message) {
    expect($read)->toThrow(RuntimeException::class, $message);
})->with([
    'decimal comma' => [fn () => csvParseRow(['Rate' => '1000,50'])->money('Rate'), 'Rate: 1000,50 is not an amount.'],
    'scientific' => [fn () => csvParseRow(['Account' => '1.23457E+11'])->identifier('Account'), 'Excel shortened this number'],
    'label' => [fn () => csvParseRow(['Status' => 'Paused'])->choice('Status', ['ACTIVE' => 'Active']), 'Status: Paused is not one of Active.'],
    'flag' => [fn () => csvParseRow(['Active' => 'maybe'])->flag('Active'), 'Active: write Yes or No'],
    'time' => [fn () => csvParseRow(['In' => '25:00'])->time('In'), 'In: 25:00 is not a time.'],
]);
