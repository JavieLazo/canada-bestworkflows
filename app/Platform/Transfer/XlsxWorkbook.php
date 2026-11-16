<?php

declare(strict_types=1);

namespace App\Platform\Transfer;

use DateTimeImmutable;
use RuntimeException;
use XMLWriter;
use ZipArchive;

/** Typed tables keep their filters, expanding formulas and editable inputs in Excel. */
final class XlsxWorkbook
{
    private const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /** @param list<array{name: string, title: string, context: string, columns: array<string, array{label: string, type: string, total: bool, formula?: string}>, rows: list<array<string, mixed>>, hidden?: bool, blank_rows?: int}> $sheets */
    public function bytes(array $sheets): string
    {
        if ($sheets === [] || count($sheets) > 32) {
            throw new RuntimeException('A workbook needs 1 to 32 worksheets.');
        }
        $path = tempnam(sys_get_temp_dir(), 'ems-workbook-');
        if ($path === false) {
            throw new RuntimeException('The workbook could not be prepared.');
        }
        $zip = new ZipArchive;
        $opened = false;
        try {
            if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('The workbook could not be opened.');
            }
            $opened = true;
            $book = $this->xml('workbook');
            $book->writeAttribute('xmlns:r', self::REL);
            $book->startElement('sheets');
            $rels = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
            $types = '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
            $names = [];
            foreach ($sheets as $i => $sheet) {
                $n = $i + 1;
                if (! preg_match('/^[^\\\\\/\[\]*?:]{1,31}$/uD', $sheet['name']) || isset($names[mb_strtolower($sheet['name'])]) || count($sheet['columns']) > 128 || count($sheet['rows']) > 10000) {
                    throw new RuntimeException('A workbook sheet name or table size is invalid.');
                }
                $names[mb_strtolower($sheet['name'])] = true;
                $book->startElement('sheet');
                $book->writeAttribute('name', $sheet['name']);
                $book->writeAttribute('sheetId', (string) $n);
                $book->writeAttribute('r:id', 'rId'.$n);
                if ($sheet['hidden'] ?? false) {
                    $book->writeAttribute('state', 'veryHidden');
                }
                $book->endElement();
                [$xml, $table] = $this->sheet($sheet, $n);
                $this->put($zip, 'xl/worksheets/sheet'.$n.'.xml', $xml);
                $this->put($zip, 'xl/tables/table'.$n.'.xml', $table);
                $this->put($zip, 'xl/worksheets/_rels/sheet'.$n.'.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="'.self::REL.'/table" Target="../tables/table'.$n.'.xml"/></Relationships>');
                $rels .= '<Relationship Id="rId'.$n.'" Type="'.self::REL.'/worksheet" Target="worksheets/sheet'.$n.'.xml"/>';
                $types .= '<Override PartName="/xl/worksheets/sheet'.$n.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/tables/table'.$n.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.table+xml"/>';
            }
            $book->endElement();
            $book->startElement('calcPr');
            $book->writeAttribute('calcId', '191029');
            $book->writeAttribute('fullCalcOnLoad', '1');
            $book->endElement();
            $this->put($zip, 'xl/workbook.xml', $this->finish($book));
            $this->put($zip, 'xl/_rels/workbook.xml.rels', $rels.'<Relationship Id="styles" Type="'.self::REL.'/styles" Target="styles.xml"/></Relationships>');
            $this->put($zip, '_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="office" Type="'.self::REL.'/officeDocument" Target="xl/workbook.xml"/></Relationships>');
            $this->put($zip, '[Content_Types].xml', $types.'</Types>');
            $this->put($zip, 'xl/styles.xml', $this->styles());
            if (! $zip->close()) {
                throw new RuntimeException('The workbook could not be finished.');
            }
            $opened = false;
            $bytes = file_get_contents($path);
            if ($bytes === false) {
                throw new RuntimeException('The workbook could not be read.');
            }

            return $bytes;
        } finally {
            if ($opened) {
                $zip->close();
            } unlink($path);
        }
    }

    public static function column(int $number): string
    {
        $name = '';
        while ($number > 0) {
            $number--;
            $name = chr(65 + $number % 26).$name;
            $number = intdiv($number, 26);
        }

        return $name;
    }

    /** @param array{name: string, title: string, context: string, columns: array<string, array{label: string, type: string, total: bool, formula?: string}>, rows: list<array<string, mixed>>, hidden?: bool, blank_rows?: int} $sheet
     * @return array{string, string}
     */
    private function sheet(array $sheet, int $n): array
    {
        $columns = $sheet['columns'];
        $rows = $sheet['rows'];
        $labels = [];
        foreach ($columns as $key => &$column) {
            $label = $column['label'];
            $suffix = 1;
            while (isset($labels[mb_strtolower($label)])) {
                $label = $column['label'].' ('.++$suffix.')';
            }
            $labels[mb_strtolower($label)] = true;
            $column['label'] = $label;
        }
        unset($column);
        for ($i = 0; $i < max(1, min(20, $sheet['blank_rows'] ?? 1)); $i++) {
            $rows[] = [];
        }
        $total = count(array_filter($columns, static fn (array $column): bool => $column['total'])) > 0;
        $last = count($rows) + 4;
        $ref = 'A4:'.self::column(count($columns)).($last + ($total ? 1 : 0));
        $xml = $this->xml('worksheet');
        $xml->writeAttribute('xmlns:r', self::REL);
        $xml->startElement('sheetViews');
        $xml->startElement('sheetView');
        $xml->writeAttribute('workbookViewId', '0');
        $xml->writeAttribute('showGridLines', '0');
        $xml->startElement('pane');
        $xml->writeAttribute('xSplit', '1');
        $xml->writeAttribute('ySplit', '4');
        $xml->writeAttribute('topLeftCell', 'B5');
        $xml->writeAttribute('activePane', 'bottomRight');
        $xml->writeAttribute('state', 'frozen');
        $xml->endElement();
        $xml->endElement();
        $xml->endElement();
        $xml->startElement('cols');
        $firstVisible = null;
        foreach (array_values($columns) as $i => $column) {
            $key = array_keys($columns)[$i];
            $hidden = $key === 'id' || str_ends_with($key, '_id');
            $firstVisible ??= $hidden ? null : self::column($i + 1);
            $xml->startElement('col');
            $xml->writeAttribute('min', (string) ($i + 1));
            $xml->writeAttribute('max', (string) ($i + 1));
            $xml->writeAttribute('width', in_array($column['type'], ['NUMBER', 'MONEY', 'INTEGER', 'DATE'], true) ? '20' : '36');
            $xml->writeAttribute('customWidth', '1');
            if ($hidden) {
                $xml->writeAttribute('hidden', '1');
            }
            $xml->endElement();
        }
        $xml->endElement();
        $xml->startElement('sheetData');
        $firstVisible ??= 'A';
        $xml->startElement('row');
        $xml->writeAttribute('r', '1');
        $xml->writeAttribute('ht', '27');
        $xml->writeAttribute('customHeight', '1');
        $this->cell($xml, $firstVisible.'1', $sheet['title'], 'TEXT', 5);
        $xml->endElement();
        $xml->startElement('row');
        $xml->writeAttribute('r', '2');
        $this->cell($xml, $firstVisible.'2', $sheet['context'], 'TEXT', 6);
        $xml->endElement();
        $xml->startElement('row');
        $xml->writeAttribute('r', '3');
        $xml->writeAttribute('hidden', '1');
        foreach (array_keys($columns) as $i => $key) {
            $this->cell($xml, self::column($i + 1).'3', $key, 'TEXT');
        }$xml->endElement();
        $xml->startElement('row');
        $xml->writeAttribute('r', '4');
        $xml->writeAttribute('ht', '32');
        $xml->writeAttribute('customHeight', '1');
        foreach (array_values($columns) as $i => $column) {
            $this->cell($xml, self::column($i + 1).'4', $column['label'], 'TEXT', 1);
        }$xml->endElement();
        foreach ($rows as $i => $row) {
            $line = $i + 5;
            $xml->startElement('row');
            $xml->writeAttribute('r', (string) $line);
            $xml->writeAttribute('ht', '34');
            $xml->writeAttribute('customHeight', '1');
            foreach ($columns as $key => $column) {
                $col = array_search($key, array_keys($columns), true);
                $type = is_array($row['_types'] ?? null) && is_string($row['_types'][$key] ?? null) ? $row['_types'][$key] : $column['type'];
                $this->cell($xml, self::column($col + 1).$line, $row[$key] ?? null, $type, null, isset($column['formula']) ? $this->formula($column['formula'], array_keys($columns), $line) : null);
            }
            $xml->endElement();
        }
        if ($total) {
            $xml->startElement('row');
            $xml->writeAttribute('r', (string) ($last + 1));
            foreach ($columns as $key => $column) {
                $values = array_filter(array_column($sheet['rows'], $key), static fn ($value): bool => $value !== null && $value !== '');
                $formula = $column['total'] ? 'SUBTOTAL(109,'.self::column(array_search($key, array_keys($columns), true) + 1).'5:'.self::column(array_search($key, array_keys($columns), true) + 1).$last.')' : null;
                $value = $column['total'] && $values !== [] ? array_sum($values) : null;
                $index = array_search($key, array_keys($columns), true);
                $this->cell($xml, self::column($index + 1).($last + 1), $value, $column['type'], null, $formula);
            }
            $xml->endElement();
        }
        $xml->endElement();
        $xml->startElement('tableParts');
        $xml->writeAttribute('count', '1');
        $xml->startElement('tablePart');
        $xml->writeAttribute('r:id', 'rId1');
        $xml->endElement();
        $xml->endElement();
        $table = $this->xml('table');
        $table->writeAttribute('id', (string) $n);
        $table->writeAttribute('name', 'EMS_'.$n);
        $table->writeAttribute('displayName', 'EMS_'.$n);
        $table->writeAttribute('ref', $ref);
        $table->writeAttribute('totalsRowCount', $total ? '1' : '0');
        $table->startElement('autoFilter');
        $table->writeAttribute('ref', 'A4:'.self::column(count($columns)).$last);
        $table->endElement();
        $table->startElement('tableColumns');
        $table->writeAttribute('count', (string) count($columns));
        foreach (array_values($columns) as $i => $column) {
            $table->startElement('tableColumn');
            $table->writeAttribute('id', (string) ($i + 1));
            $table->writeAttribute('name', $column['label']);
            if ($column['total']) {
                $table->writeAttribute('totalsRowFunction', 'sum');
            }
            if (isset($column['formula'])) {
                $table->writeElement('calculatedColumnFormula', $this->formula($column['formula'], array_keys($columns), 5));
            }
            $table->endElement();
        }
        $table->endElement();
        $table->startElement('tableStyleInfo');
        $table->writeAttribute('name', 'TableStyleMedium2');
        $table->writeAttribute('showRowStripes', '1');
        $table->writeAttribute('showColumnStripes', '0');
        $table->endElement();

        return [$this->finish($xml), $this->finish($table)];
    }

    /** @param list<string> $keys */
    private function formula(string $formula, array $keys, int $row): string
    {
        $references = [];
        foreach ($keys as $index => $key) {
            $references['{'.$key.'}'] = self::column($index + 1).$row;
        }

        return strtr($formula, $references);
    }

    private function cell(XMLWriter $xml, string $address, mixed $value, string $type, ?int $style = null, ?string $formula = null): void
    {
        $xml->startElement('c');
        $xml->writeAttribute('r', $address);
        $numeric = $value !== null && $value !== '' && in_array($type, ['NUMBER', 'MONEY', 'INTEGER', 'DATE'], true);
        if ($numeric && $type === 'DATE') {
            $value = (new DateTimeImmutable('1899-12-30'))->diff(new DateTimeImmutable((string) $value))->days;
        } elseif ($numeric && $type !== 'INTEGER') {
            $scale = $type === 'MONEY' ? 100 : 10000;
            $absolute = abs((int) $value);
            $value = ($value < 0 ? '-' : '').intdiv($absolute, $scale).'.'.str_pad((string) ($absolute % $scale), $type === 'MONEY' ? 2 : 4, '0', STR_PAD_LEFT);
        }
        if (is_array($value)) {
            $value = implode('; ', $value);
        }
        $xml->writeAttribute('s', (string) ($style ?? match ($type) {
            'MONEY' => 3, 'NUMBER' => 2, 'DATE' => 4, 'INTEGER' => 7, default => 0
        }));
        if ($formula !== null) {
            if (! $numeric) {
                $xml->writeAttribute('t', 'str');
            }
            $xml->writeElement('f', $formula);
            $xml->writeElement('v', (string) ($value ?? ''));
        } elseif ($numeric) {
            $xml->writeElement('v', (string) $value);
        } elseif ($value !== null && $value !== '') {
            $xml->writeAttribute('t', 'inlineStr');
            $xml->startElement('is');
            $xml->startElement('t');
            $xml->writeAttribute('xml:space', 'preserve');
            $xml->text(preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', (string) $value) ?? '');
            $xml->endElement();
            $xml->endElement();
        }
        $xml->endElement();
    }

    private function xml(string $root): XMLWriter
    {
        $xml = new XMLWriter;
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement($root);
        $xml->writeAttribute('xmlns', self::NS);

        return $xml;
    }

    private function finish(XMLWriter $xml): string
    {
        $xml->endElement();
        $xml->endDocument();

        return $xml->outputMemory();
    }

    private function put(ZipArchive $zip, string $path, string $bytes): void
    {
        if (! $zip->addFromString($path, $bytes)) {
            throw new RuntimeException('A workbook part could not be written.');
        }
    }

    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><styleSheet xmlns="'.self::NS.'"><numFmts count="3"><numFmt numFmtId="164" formatCode="#,##0.####"/><numFmt numFmtId="165" formatCode="#,##0.00;[Red](#,##0.00);0.00"/><numFmt numFmtId="166" formatCode="yyyy-mm-dd"/></numFmts><fonts count="4"><font><sz val="10"/><name val="Arial"/><color rgb="FF25314D"/></font><font><b/><sz val="10"/><name val="Arial"/><color rgb="FFFFFFFF"/></font><font><b/><sz val="14"/><name val="Arial"/><color rgb="FF25314D"/></font><font><sz val="10"/><name val="Arial"/><color rgb="FF667085"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF25314D"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="8"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf><xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="166" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0"/><xf numFmtId="3" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    }
}
