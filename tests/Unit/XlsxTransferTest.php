<?php

declare(strict_types=1);

use App\Platform\Transfer\XlsxReader;
use App\Platform\Transfer\XlsxWorkbook;

function transferWorkbook(): string
{
    return (new XlsxWorkbook)->bytes([['name' => 'Work', 'title' => 'Project work', 'context' => 'November', 'columns' => [
        'name' => ['label' => 'Name', 'type' => 'TEXT', 'total' => false], 'value' => ['label' => 'Amount', 'type' => 'MONEY', 'total' => true], 'date' => ['label' => 'Date', 'type' => 'DATE', 'total' => false],
    ], 'rows' => [['name' => '=WEBSERVICE("https://invalid.test")', 'value' => 12345, 'date' => '2026-11-03'], ['name' => 'Zero', 'value' => 0, 'date' => null]]]]);
}

it('round trips typed money dates zero and literal formula text with native tables', function () {
    $path = tempnam(sys_get_temp_dir(), 'xlsx-test-');
    try {
        file_put_contents($path, transferWorkbook());
        $reader = new XlsxReader;
        expect($reader->sheets($path))->toBe(['Work']);
        $read = $reader->read($path, 'Work', 4);
        expect($read['sheet']->headers)->toBe(['Name', 'Amount', 'Date'])
            ->and($read['sheet']->rows[0]['cells'])->toBe(['=WEBSERVICE("https://invalid.test")', '123.45', '2026-11-03'])
            ->and($read['sheet']->rows[1]['cells'][1])->toBe('0.00');
        $zip = new ZipArchive;
        $zip->open($path);
        expect($zip->getFromName('xl/tables/table1.xml'))->toContain('totalsRowFunction="sum"', 'name="Amount"')
            ->and($zip->getFromName('xl/worksheets/sheet1.xml'))->not->toContain('<f>WEBSERVICE');
        $zip->close();
    } finally {
        unlink($path);
    }
});

it('refuses unsafe workbook archive content and external relationships', function (string $part, string $bytes) {
    $path = tempnam(sys_get_temp_dir(), 'xlsx-test-');
    try {
        file_put_contents($path, transferWorkbook());
        $zip = new ZipArchive;
        $zip->open($path);
        $zip->addFromString($part, $bytes);
        $zip->close();
        expect(fn () => (new XlsxReader)->sheets($path))->toThrow(RuntimeException::class);
    } finally {
        unlink($path);
    }
})->with([
    ['../unsafe.xml', '<x/>'], ['xl/vbaProject.bin', 'macro'],
    ['xl/_rels/unsafe.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="x" TargetMode="External" Target="https://invalid.test"/></Relationships>'],
    ['xl/workbook.xml', '<!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><x>&e;</x>'],
]);
