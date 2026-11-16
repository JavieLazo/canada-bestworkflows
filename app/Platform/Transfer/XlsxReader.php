<?php

declare(strict_types=1);

namespace App\Platform\Transfer;

use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use RuntimeException;
use ZipArchive;

/** Reads bounded worksheet values without extracting archives or executing formulas. */
final class XlsxReader
{
    private const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    /** @return list<string> */
    public function sheets(string $path): array
    {
        return $this->withZip($path, fn (ZipArchive $zip): array => array_keys($this->sheetPaths($zip)));
    }

    /** @return array{sheet: CsvSheet, formula_cells: list<string>} */
    public function read(string $path, string $sheet, int $headerRow = 1, int $maxRows = 1000): array
    {
        if ($headerRow < 1 || $headerRow > 100 || $maxRows < 1 || $maxRows > 10000) {
            throw new RuntimeException('Choose a header row from 1 to 100 and at most 10,000 data rows.');
        }

        return $this->withZip($path, function (ZipArchive $zip) use ($sheet, $headerRow, $maxRows): array {
            $paths = $this->sheetPaths($zip);
            if (! isset($paths[$sheet])) {
                throw new RuntimeException('Choose a worksheet from this workbook.');
            }
            $strings = [];
            if ($zip->locateName('xl/sharedStrings.xml') !== false) {
                $shared = $this->xml($zip, 'xl/sharedStrings.xml');
                foreach ($shared->getElementsByTagNameNS(self::NS, 'si') as $item) {
                    $text = '';
                    foreach ($item->getElementsByTagNameNS(self::NS, 't') as $run) {
                        $text .= $run->textContent;
                    }
                    $strings[] = $text;
                    if (count($strings) > 100000) {
                        throw new RuntimeException('This workbook has too many shared values. Split it before importing.');
                    }
                }
            }
            unset($shared, $item, $run);
            $dateStyles = [];
            if ($zip->locateName('xl/styles.xml') !== false) {
                $styles = $this->xml($zip, 'xl/styles.xml');
                $formats = [];
                foreach ($styles->getElementsByTagNameNS(self::NS, 'numFmt') as $format) {
                    $code = preg_replace('/"[^"]*"|\[[^\]]*\]|\\\\./', '', $format->getAttribute('formatCode')) ?? '';
                    if (preg_match('/[ymd]/i', $code)) {
                        $formats[(int) $format->getAttribute('numFmtId')] = true;
                    }
                }
                $xfs = $styles->getElementsByTagNameNS(self::NS, 'cellXfs')->item(0);
                if ($xfs instanceof DOMElement) {
                    foreach ($xfs->getElementsByTagNameNS(self::NS, 'xf') as $i => $xf) {
                        $format = (int) $xf->getAttribute('numFmtId');
                        if (($format >= 14 && $format <= 22) || isset($formats[$format])) {
                            $dateStyles[$i] = true;
                        }
                    }
                }
            }
            $book = $this->xml($zip, 'xl/workbook.xml');
            $properties = $book->getElementsByTagNameNS(self::NS, 'workbookPr')->item(0);
            $date1904 = $properties instanceof DOMElement && in_array($properties->getAttribute('date1904'), ['1', 'true'], true);
            $doc = $this->xml($zip, $paths[$sheet]);
            $skipRows = [];
            $relPath = dirname($paths[$sheet]).'/_rels/'.basename($paths[$sheet]).'.rels';
            if ($zip->locateName($relPath) !== false) {
                foreach ($this->xml($zip, $relPath)->getElementsByTagName('Relationship') as $relationship) {
                    if (! str_ends_with($relationship->getAttribute('Type'), '/table')) {
                        continue;
                    }
                    $target = $relationship->getAttribute('Target');
                    $target = str_starts_with($target, '../tables/') ? 'xl/tables/'.substr($target, 10) : ltrim($target, '/');
                    if (! preg_match('#^xl/tables/[^/]+\.xml$#D', $target)) {
                        throw new RuntimeException('A worksheet table relationship is unsafe.');
                    }
                    $table = $this->xml($zip, $target)->documentElement;
                    if ($table !== null && preg_match('/^[A-Z]+([0-9]+):[A-Z]+([0-9]+)$/D', str_replace('$', '', $table->getAttribute('ref')), $range)) {
                        if ($table->getAttribute('totalsRowCount') === '1') {
                            $skipRows[(int) $range[2]] = true;
                        }
                        if ($headerRow === (int) $range[1] - 1) {
                            $skipRows[(int) $range[1]] = true;
                        }
                    }
                }
            }
            $rows = [];
            $headers = [];
            $formulas = [];
            foreach ($doc->getElementsByTagNameNS(self::NS, 'row') as $row) {
                $line = (int) $row->getAttribute('r');
                if ($line < $headerRow || isset($skipRows[$line])) {
                    continue;
                }
                $cells = [];
                foreach ($row->getElementsByTagNameNS(self::NS, 'c') as $cell) {
                    $address = $cell->getAttribute('r');
                    if (preg_match('/^([A-Z]{1,3})([1-9][0-9]*)$/D', $address, $match) !== 1) {
                        throw new RuntimeException('A worksheet cell has an invalid address.');
                    }
                    $column = 0;
                    foreach (str_split($match[1]) as $letter) {
                        $column = $column * 26 + ord($letter) - 64;
                    }
                    if ($column > 128) {
                        throw new RuntimeException('Import a sheet with at most 128 columns.');
                    }
                    $type = $cell->getAttribute('t');
                    $value = $cell->getElementsByTagNameNS(self::NS, 'v')->item(0)->textContent ?? '';
                    if ($cell->getElementsByTagNameNS(self::NS, 'f')->length > 0) {
                        if ($cell->getElementsByTagNameNS(self::NS, 'v')->length === 0 || ($value === '' && $type !== 'str')) {
                            throw new RuntimeException($address.' has a formula without a saved result. Recalculate and save the workbook first.');
                        }
                        $formulas[] = $address;
                    }
                    if ($type === 's') {
                        if (! ctype_digit($value) || ! array_key_exists((int) $value, $strings)) {
                            throw new RuntimeException('A shared worksheet value is missing.');
                        }
                        $value = $strings[(int) $value];
                    } elseif ($type === 'inlineStr') {
                        $value = '';
                        foreach ($cell->getElementsByTagNameNS(self::NS, 't') as $text) {
                            $value .= $text->textContent;
                        }
                    } elseif ($type === 'e') {
                        throw new RuntimeException($address.' contains a spreadsheet error. Fix it before importing.');
                    } elseif (isset($dateStyles[(int) $cell->getAttribute('s')]) && is_numeric($value)) {
                        $days = (int) floor((float) $value);
                        if ($days < 0 || $days > 2958465) {
                            throw new RuntimeException($address.' has an invalid spreadsheet date.');
                        }
                        $value = (new DateTimeImmutable($date1904 ? '1904-01-01' : '1899-12-30'))->modify('+'.$days.' days')->format('Y-m-d');
                    }
                    if (mb_strlen($value) > 16000) {
                        throw new RuntimeException($address.' exceeds 16,000 characters.');
                    }
                    $cells[$column - 1] = trim($value);
                }
                if ($cells === []) {
                    continue;
                }
                $dense = array_fill(0, max(array_keys($cells)) + 1, '');
                foreach ($cells as $column => $value) {
                    $dense[$column] = $value;
                }
                if ($line === $headerRow) {
                    $headers = array_values($dense);

                    continue;
                }
                if (implode('', $dense) === '') {
                    continue;
                }
                $rows[] = ['line' => $line, 'cells' => array_values($dense)];
                if (count($rows) > $maxRows) {
                    throw new RuntimeException('Import at most '.$maxRows.' rows at a time.');
                }
            }
            if ($headers === []) {
                throw new RuntimeException('The selected header row is empty.');
            }

            return ['sheet' => new CsvSheet($headers, $rows), 'formula_cells' => $formulas];
        });
    }

    /** @template T
     * @param  callable(ZipArchive): T  $callback
     * @return T
     */
    private function withZip(string $path, callable $callback): mixed
    {
        if (! is_file($path) || filesize($path) > 10000000) {
            throw new RuntimeException('Choose an XLSX workbook up to 10 MB.');
        }
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('This file is not a readable XLSX workbook.');
        }
        try {
            if ($zip->numFiles > 1000) {
                throw new RuntimeException('This workbook contains too many archive parts.');
            }
            $total = 0;
            $names = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat === false) {
                    throw new RuntimeException('A workbook part could not be read.');
                }
                $name = $stat['name'];
                if (str_starts_with($name, '/') || str_contains($name, '\\') || str_contains($name, ':') || in_array('..', explode('/', $name), true) || isset($names[$name])) {
                    throw new RuntimeException('The workbook has an unsafe or duplicate archive path.');
                }
                $names[$name] = true;
                $total += $stat['size'];
                if ($total > 32000000 || $stat['size'] > 8000000 || ($stat['size'] > 1000000 && $stat['size'] > max(1, $stat['comp_size']) * 300)) {
                    throw new RuntimeException('This workbook expands beyond the import limit.');
                }
                if (preg_match('#vbaProject|externalLinks/|embeddings/|activeX/#i', $name)) {
                    throw new RuntimeException('Remove macros, external links and embedded objects before importing.');
                }
                if (str_ends_with($name, '.rels')) {
                    $relationships = $this->xml($zip, $name);
                    foreach ($relationships->getElementsByTagName('Relationship') as $relationship) {
                        if (strcasecmp($relationship->getAttribute('TargetMode'), 'External') === 0) {
                            throw new RuntimeException('Remove external workbook relationships before importing.');
                        }
                    }
                }
            }

            return $callback($zip);
        } finally {
            $zip->close();
        }
    }

    /** @return array<string, string> */
    private function sheetPaths(ZipArchive $zip): array
    {
        $rels = $this->xml($zip, 'xl/_rels/workbook.xml.rels');
        $targets = [];
        foreach ($rels->getElementsByTagName('Relationship') as $relationship) {
            $target = $relationship->getAttribute('Target');
            $path = str_starts_with($target, '/xl/') ? ltrim($target, '/') : 'xl/'.$target;
            if (str_contains($target, '..') || str_contains($target, '\\') || str_contains($target, ':')) {
                throw new RuntimeException('A worksheet relationship is unsafe.');
            }
            $targets[$relationship->getAttribute('Id')] = $path;
        }
        $book = $this->xml($zip, 'xl/workbook.xml');
        $sheets = [];
        foreach ($book->getElementsByTagNameNS(self::NS, 'sheet') as $sheet) {
            if ($sheet->getAttribute('state') === 'veryHidden') {
                continue;
            }
            $id = $sheet->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
            if (isset($targets[$id])) {
                $sheets[$sheet->getAttribute('name')] = $targets[$id];
            }
        }
        if ($sheets === [] || count($sheets) > 32) {
            throw new RuntimeException('Choose a workbook with 1 to 32 worksheets.');
        }

        return $sheets;
    }

    private function xml(ZipArchive $zip, string $part): DOMDocument
    {
        $bytes = $zip->getFromName($part);
        if ($bytes === false || preg_match('/<!DOCTYPE|<!ENTITY/i', $bytes)) {
            throw new RuntimeException('A workbook XML part is missing or unsafe.');
        }
        $doc = new DOMDocument;
        $old = libxml_use_internal_errors(true);
        try {
            if (! $doc->loadXML($bytes, LIBXML_NONET | LIBXML_COMPACT) || $doc->doctype !== null) {
                throw new RuntimeException('A workbook XML part is invalid.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($old);
        }

        return $doc;
    }
}
