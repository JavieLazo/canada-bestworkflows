<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Infrastructure;

use App\Modules\Workforce\Application\PdsWorkbookValues;
use App\Modules\Workforce\Contracts\Views\EmployeeProfileView;
use App\Modules\Workforce\Contracts\Views\PdsRowView;
use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;
use ZipArchive;

/**
 * Fills the supplied CSC workbook without round-tripping its Excel controls,
 * drawings or printer settings through a spreadsheet engine that drops them.
 * Only addressed XML parts change; each request uses a private temporary copy.
 */
final class PdsWorkbookTemplate
{
    private const string MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const string REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private const string PACKAGE = 'http://schemas.openxmlformats.org/package/2006/relationships';

    private ZipArchive $zip;

    /** @var array<string, DOMDocument> */
    private array $documents = [];

    /** @var array<string, true> */
    private array $dirty = [];

    /** @var array<string, int> */
    private array $styles = [];

    private int $sheetCount = 5;

    private string $employeeLabel = '';

    /** @var array<string, string> */
    private array $fullText = [];

    /**
     * @param  array{education: list<PdsRowView>, eligibility: list<PdsRowView>, experience: list<PdsRowView>, training: list<PdsRowView>, voluntary: list<PdsRowView>, other: array<string, list<string>>}  $sheet
     */
    public function render(EmployeeProfileView $employee, array $sheet): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ems-pds-');
        if ($path === false) {
            throw new RuntimeException('Unable to prepare the PDS workbook.');
        }

        $this->documents = [];
        $this->dirty = [];
        $this->styles = [];
        $this->sheetCount = 5;
        $this->fullText = [];
        $this->employeeLabel = $employee->last_name.', '.$employee->first_name.' — '.$employee->employee_code;
        $this->zip = new ZipArchive;
        $opened = false;

        try {
            if (! copy(resource_path('templates/CS-Form-No.-212-Revised-2026-PDS.xlsx'), $path)
                || $this->zip->open($path) !== true) {
                throw new RuntimeException('Unable to open the PDS workbook template.');
            }
            $opened = true;
            $this->inputMerges();
            (new PdsWorkbookValues)->fill($this, $employee, $sheet);
            $fullText = [];
            foreach ($this->fullText as $reference => $value) {
                $fullText[] = [$reference, $value];
            }
            $this->continuation('Full text', ['Form page and cell', 'Full recorded value'], $fullText);

            foreach (array_keys($this->dirty) as $part) {
                $document = $this->documents[$part];
                $xml = $document->saveXML();
                if ($xml === false || ! $this->zip->addFromString($part, $xml)) {
                    throw new RuntimeException('Unable to fill the PDS workbook.');
                }
            }
            if (! $this->zip->close()) {
                throw new RuntimeException('Unable to finish the PDS workbook.');
            }
            $opened = false;
            $bytes = file_get_contents($path);
            if ($bytes === false) {
                throw new RuntimeException('Unable to read the PDS workbook.');
            }

            return $bytes;
        } finally {
            if ($opened) {
                $this->zip->close();
            }
            unlink($path);
            $this->documents = [];
        }
    }

    /** Join blank input cells where the template omits the neighboring rows' merges. */
    private function inputMerges(): void
    {
        $ranges = [
            1 => ['D11:K11', 'D12:N12', 'I29:K29', 'L29:N29'],
            3 => [],
            4 => ['J20:L20', 'J21:L21', 'K44:L44', 'K46:L46', 'K48:L48'],
        ];
        foreach (range(29, 34) as $row) {
            $ranges[3][] = 'A'.$row.':D'.$row;
        }
        foreach ([...range(19, 25), ...range(29, 34)] as $row) {
            $ranges[3][] = 'I'.$row.':K'.$row;
        }
        foreach ($ranges as $sheet => $merges) {
            $document = $this->document('xl/worksheets/sheet'.$sheet.'.xml');
            $xpath = $this->xpath($document);
            $container = $this->element($xpath, '//s:mergeCells');
            foreach ($merges as $range) {
                if ($this->find($xpath, '//s:mergeCell[@ref="'.$range.'"]') !== null) {
                    continue;
                }
                $merge = $document->createElementNS(self::MAIN, 'mergeCell');
                $merge->setAttribute('ref', $range);
                $container->appendChild($merge);
            }
            $container->setAttribute('count', (string) $container->childElementCount);
        }
    }

    /** Text is always literal, including leading zeros and spreadsheet formula prefixes. */
    public function cell(int $sheet, string $reference, string|int|float $value, ?string $format = null): void
    {
        $document = $this->document('xl/worksheets/sheet'.$sheet.'.xml');
        $xpath = $this->xpath($document);
        $cell = $this->find($xpath, '//s:sheetData/s:row/s:c[@r="'.$reference.'"]');
        if (! $cell instanceof DOMElement) {
            preg_match('/^([A-Z]+)([0-9]+)$/D', $reference, $parts);
            if (count($parts) !== 3) {
                throw new RuntimeException('Invalid PDS cell reference.');
            }
            $row = $this->find($xpath, '//s:sheetData/s:row[@r="'.$parts[2].'"]');
            if (! $row instanceof DOMElement) {
                $row = $document->createElementNS(self::MAIN, 'row');
                $row->setAttribute('r', $parts[2]);
                $data = $this->element($xpath, '//s:sheetData');
                $before = $this->find($xpath, '//s:sheetData/s:row[@r > '.$parts[2].']');
                $data->insertBefore($row, $before);
            }
            $cell = $document->createElementNS(self::MAIN, 'c');
            $cell->setAttribute('r', $reference);
            $before = null;
            foreach ($row->childNodes as $candidate) {
                if ($candidate instanceof DOMElement && $candidate->localName === 'c'
                    && self::column($candidate->getAttribute('r')) > self::column($reference)) {
                    $before = $candidate;
                    break;
                }
            }
            $row->insertBefore($cell, $before);
        }

        while ($cell->firstChild !== null) {
            $cell->removeChild($cell->firstChild);
        }
        if (is_string($value)) {
            $format = '@';
        }
        if ($format !== null) {
            $cell->setAttribute('s', (string) $this->style((int) $cell->getAttribute('s'), $format));
        }
        if (is_string($value)) {
            if ($sheet <= 4 && self::column($reference) <= 14) {
                $this->rememberLongText($sheet, $reference, $value, $cell, $xpath);
            }
            $cell->setAttribute('t', 'inlineStr');
            $inline = $document->createElementNS(self::MAIN, 'is');
            $text = $document->createElementNS(self::MAIN, 't');
            $text->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
            // XML 1.0 cannot carry control characters accepted by text inputs.
            $value = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value) ?? '';
            $text->appendChild($document->createTextNode($value));
            $inline->appendChild($text);
            $cell->appendChild($inline);
        } else {
            $cell->removeAttribute('t');
            $number = $document->createElementNS(self::MAIN, 'v');
            $number->appendChild($document->createTextNode((string) $value));
            $cell->appendChild($number);
        }
    }

    /** Keep modern Excel control properties and the legacy VML rendering in agreement. */
    public function check(int $control, bool $checked): void
    {
        $properties = $this->document('xl/ctrlProps/ctrlProp'.$control.'.xml');
        $properties->documentElement?->setAttribute('checked', $checked ? 'Checked' : 'Unchecked');

        // Worksheet controls and VML shapes have different IDs, but matching
        // anchors. Their shared anchor is the stable identity in this template.
        foreach ([1 => 1, 4 => 2] as $sheet => $drawing) {
            $rels = $this->xpath($this->document('xl/worksheets/_rels/sheet'.$sheet.'.xml.rels', edit: false));
            $relationship = $this->find($rels, '/*/*[@Target="../ctrlProps/ctrlProp'.$control.'.xml"]');
            if (! $relationship instanceof DOMElement) {
                continue;
            }
            $xpath = $this->xpath($this->document('xl/worksheets/sheet'.$sheet.'.xml', edit: false));
            $source = $this->element($xpath, '//s:control[@r:id="'.$relationship->getAttribute('Id').'"]');
            $from = $this->element($xpath, './/s:from', $source);
            $col = $this->element($xpath, './xdr:col', $from)->textContent;
            $row = $this->element($xpath, './xdr:row', $from)->textContent;
            $colOff = (int) $this->element($xpath, './xdr:colOff', $from)->textContent;
            $rowOff = (int) $this->element($xpath, './xdr:rowOff', $from)->textContent;
            $vml = $this->document('xl/drawings/vmlDrawing'.$drawing.'.vml');
            $vx = $this->xpath($vml);
            foreach ($vx->query('//x:ClientData[@ObjectType="Checkbox"]') ?: [] as $data) {
                if (! $data instanceof DOMElement) {
                    continue;
                }
                $anchor = explode(',', $this->element($vx, './x:Anchor', $data)->textContent);
                if (count($anchor) !== 8 || trim($anchor[0]) !== $col || trim($anchor[2]) !== $row
                    || abs((int) $anchor[1] * 9525 - $colOff) > 9525
                    || abs((int) $anchor[3] * 9525 - $rowOff) > 9525) {
                    continue;
                }
                $state = $this->find($vx, './x:Checked', $data);
                if (! $state instanceof DOMElement) {
                    $state = $vml->createElementNS('urn:schemas-microsoft-com:office:excel', 'x:Checked');
                    $data->appendChild($state);
                }
                $state->textContent = $checked ? '1' : '0';

                return;
            }
            throw new RuntimeException('Unable to match a PDS checkbox.');
        }
        throw new RuntimeException('Unable to find a PDS checkbox.');
    }

    /** The dropdown selects the template's blank fourth list entry on C1. */
    public function country(?string $country): void
    {
        $this->cell(1, 'Q14', $country ?? '');
    }

    /**
     * Additional records remain separate from the fixed four-page form.
     *
     * @param  list<string>  $headers
     * @param  list<list<string|int|float>>  $rows
     */
    public function continuation(string $name, array $headers, array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $sheet = ++$this->sheetCount;
        $document = new DOMDocument('1.0', 'UTF-8');
        $root = $document->createElementNS(self::MAIN, 'worksheet');
        $document->appendChild($root);
        $properties = $document->createElementNS(self::MAIN, 'sheetPr');
        $setup = $document->createElementNS(self::MAIN, 'pageSetUpPr');
        $setup->setAttribute('fitToPage', '1');
        $properties->appendChild($setup);
        $root->appendChild($properties);
        $cols = $document->createElementNS(self::MAIN, 'cols');
        foreach ($headers as $i => $header) {
            $col = $document->createElementNS(self::MAIN, 'col');
            $col->setAttribute('min', (string) ($i + 1));
            $col->setAttribute('max', (string) ($i + 1));
            $col->setAttribute('width', count($headers) > 5 ? '24' : '40');
            $col->setAttribute('customWidth', '1');
            $cols->appendChild($col);
        }
        $root->appendChild($cols);
        $root->appendChild($document->createElementNS(self::MAIN, 'sheetData'));
        $this->documents['xl/worksheets/sheet'.$sheet.'.xml'] = $document;
        $this->dirty['xl/worksheets/sheet'.$sheet.'.xml'] = true;
        $this->cell($sheet, 'A1', $name.' — '.$this->employeeLabel);
        $style = $this->style(0, '@', wrap: true);
        $printRows = [];
        foreach (array_merge([$headers], $rows) as $values) {
            $chunks = array_map(static fn (string|int|float $value): array => is_string($value) ? self::textChunks($value) : [$value], $values);
            $count = max([1, ...array_map(count(...), $chunks)]);
            for ($i = 0; $i < $count; $i++) {
                $printRows[] = array_map(static fn (array $parts): string|int|float => $parts[$i] ?? '', $chunks);
            }
        }
        foreach ($printRows as $r => $values) {
            $lines = 1;
            foreach ($values as $c => $value) {
                $reference = self::letters($c + 1).($r + 3);
                $this->cell($sheet, $reference, $value);
                $this->element($this->xpath($document), '//s:c[@r="'.$reference.'"]')->setAttribute('s', (string) $style);
                $cellLines = 0;
                foreach (explode("\n", (string) $value) as $line) {
                    $cellLines += max(1, (int) ceil(mb_strwidth($line) / (count($headers) > 5 ? 19 : 32)));
                }
                $lines = max($lines, $cellLines);
            }
            $row = $this->element($this->xpath($document), '//s:row[@r="'.($r + 3).'"]');
            $row->setAttribute('ht', (string) max(30, $lines * 15));
            $row->setAttribute('customHeight', '1');
        }
        $merges = $document->createElementNS(self::MAIN, 'mergeCells');
        $merge = $document->createElementNS(self::MAIN, 'mergeCell');
        $merge->setAttribute('ref', 'A1:'.self::letters(count($headers)).'1');
        $merges->appendChild($merge);
        $root->appendChild($merges);
        $margins = $document->createElementNS(self::MAIN, 'pageMargins');
        foreach (['left', 'right', 'top', 'bottom', 'header', 'footer'] as $side) {
            $margins->setAttribute($side, '0.3');
        }
        $root->appendChild($margins);
        $page = $document->createElementNS(self::MAIN, 'pageSetup');
        foreach (['paperSize' => '5', 'orientation' => 'landscape', 'fitToWidth' => '1', 'fitToHeight' => '0'] as $key => $value) {
            $page->setAttribute($key, $value);
        }
        $root->appendChild($page);

        $workbook = $this->document('xl/workbook.xml');
        $entry = $workbook->createElementNS(self::MAIN, 'sheet');
        $entry->setAttribute('name', $name);
        $entry->setAttribute('sheetId', (string) $sheet);
        $entry->setAttributeNS(self::REL, 'r:id', 'pdsSheet'.$sheet);
        $this->element($this->xpath($workbook), '//s:sheets')->appendChild($entry);
        foreach (['_xlnm.Print_Area' => "'".$name."'!\$A\$1:\$".self::letters(count($headers)).'$'.(count($printRows) + 2), '_xlnm.Print_Titles' => "'".$name."'!\$1:\$3"] as $key => $value) {
            $defined = $workbook->createElementNS(self::MAIN, 'definedName');
            $defined->setAttribute('name', $key);
            $defined->setAttribute('localSheetId', (string) ($sheet - 1));
            $defined->appendChild($workbook->createTextNode($value));
            $this->element($this->xpath($workbook), '//s:definedNames')->appendChild($defined);
        }
        $rels = $this->document('xl/_rels/workbook.xml.rels');
        $relationship = $rels->createElementNS(self::PACKAGE, 'Relationship');
        foreach (['Id' => 'pdsSheet'.$sheet, 'Type' => self::REL.'/worksheet', 'Target' => 'worksheets/sheet'.$sheet.'.xml'] as $key => $value) {
            $relationship->setAttribute($key, $value);
        }
        $rels->documentElement?->appendChild($relationship);
        $types = $this->document('[Content_Types].xml');
        $type = $types->createElementNS('http://schemas.openxmlformats.org/package/2006/content-types', 'Override');
        $type->setAttribute('PartName', '/xl/worksheets/sheet'.$sheet.'.xml');
        $type->setAttribute('ContentType', 'application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml');
        $types->documentElement?->appendChild($type);
    }

    /**
     * Fixed merged form cells cannot auto-grow without moving Excel controls.
     * A conservative single-line capacity gives long values a readable appendix
     * while the original cell retains its complete, editable value.
     */
    private function rememberLongText(int $sheet, string $reference, string $value, DOMElement $cell, DOMXPath $xpath): void
    {
        $key = 'C'.$sheet.'!'.$reference;
        unset($this->fullText[$key]);
        $measured = str_starts_with($value, "NAME EXTENSION (JR., SR)\n")
            ? substr($value, strlen("NAME EXTENSION (JR., SR)\n")) : $value;
        if ($measured === '' || $measured === 'N/A' || str_contains($measured, 'See PDS Details')) {
            return;
        }
        $merge = $this->find($xpath, '//s:mergeCell[starts-with(@ref, "'.$reference.':")]');
        $last = $merge === null ? $reference : explode(':', $merge->getAttribute('ref'))[1];
        $width = 0.0;
        for ($column = self::column($reference); $column <= self::column($last); $column++) {
            $definition = $this->find($xpath, '//s:col[@min <= '.$column.' and @max >= '.$column.']');
            $width += $definition === null ? 8.43 : (float) $definition->getAttribute('width');
        }
        $styles = $this->xpath($this->document('xl/styles.xml', edit: false));
        $style = $this->element($styles, '//s:cellXfs/s:xf['.((int) $cell->getAttribute('s') + 1).']');
        $font = $this->find($styles, '//s:fonts/s:font['.((int) $style->getAttribute('fontId') + 1).']/s:sz');
        $size = $font === null ? 11.0 : max(1.0, (float) $font->getAttribute('val'));
        if (str_contains($measured, "\n") || mb_strwidth($measured) > max(12, (int) ($width * 0.75 * 11 / $size))) {
            $this->fullText[$key] = $value;
        }
    }

    /** @return non-empty-list<string> */
    private static function textChunks(string $value): array
    {
        if ($value === '') {
            return [''];
        }
        $chunks = [];
        $chunk = '';
        foreach (mb_str_split($value) as $character) {
            if (mb_strwidth($chunk.$character) > 240 || substr_count($chunk.$character, "\n") >= 12) {
                $chunks[] = $chunk;
                $chunk = '';
            }
            $chunk .= $character;
        }
        $chunks[] = $chunk;

        return $chunks;
    }

    private function document(string $part, bool $edit = true): DOMDocument
    {
        if ($edit) {
            $this->dirty[$part] = true;
        }
        if (isset($this->documents[$part])) {
            return $this->documents[$part];
        }
        $xml = $this->zip->getFromName($part);
        $document = new DOMDocument;
        if ($xml === false || ! $document->loadXML($xml, LIBXML_NONET)) {
            throw new RuntimeException('Invalid PDS workbook template part.');
        }

        return $this->documents[$part] = $document;
    }

    private function xpath(DOMDocument $document): DOMXPath
    {
        $xpath = new DOMXPath($document);
        foreach (['s' => self::MAIN, 'r' => self::REL, 'xdr' => 'http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing', 'x' => 'urn:schemas-microsoft-com:office:excel'] as $prefix => $namespace) {
            $xpath->registerNamespace($prefix, $namespace);
        }

        return $xpath;
    }

    private function element(DOMXPath $xpath, string $query, ?DOMElement $context = null): DOMElement
    {
        $node = $this->find($xpath, $query, $context);
        if (! $node instanceof DOMElement) {
            throw new RuntimeException('Invalid PDS workbook template structure.');
        }

        return $node;
    }

    private function find(DOMXPath $xpath, string $query, ?DOMElement $context = null): ?DOMElement
    {
        $nodes = $xpath->query($query, $context);
        if ($nodes === false) {
            throw new RuntimeException('Invalid PDS workbook lookup.');
        }
        $node = $nodes->item(0);

        return $node instanceof DOMElement ? $node : null;
    }

    private function style(int $base, string $format, bool $wrap = false): int
    {
        $key = $base.'|'.$format.'|'.(int) $wrap;
        if (isset($this->styles[$key])) {
            return $this->styles[$key];
        }
        $document = $this->document('xl/styles.xml');
        $xpath = $this->xpath($document);
        $formats = $this->element($xpath, '//s:numFmts');
        $max = 163;
        foreach ($formats->childNodes as $node) {
            if ($node instanceof DOMElement) {
                $max = max($max, (int) $node->getAttribute('numFmtId'));
            }
        }
        $number = $document->createElementNS(self::MAIN, 'numFmt');
        $number->setAttribute('numFmtId', (string) ($max + 1));
        $number->setAttribute('formatCode', $format);
        $formats->appendChild($number);
        $formats->setAttribute('count', (string) $formats->childElementCount);
        $styles = $this->element($xpath, '//s:cellXfs');
        $style = $this->element($xpath, '//s:cellXfs/s:xf['.($base + 1).']')->cloneNode(true);
        if (! $style instanceof DOMElement) {
            throw new RuntimeException('Invalid PDS cell style.');
        }
        $style->setAttribute('numFmtId', (string) ($max + 1));
        $style->setAttribute('applyNumberFormat', '1');
        if ($wrap) {
            $alignment = $document->createElementNS(self::MAIN, 'alignment');
            $alignment->setAttribute('wrapText', '1');
            $alignment->setAttribute('vertical', 'top');
            $style->appendChild($alignment);
            $style->setAttribute('applyAlignment', '1');
        }
        $index = $styles->childElementCount;
        $styles->appendChild($style);
        $styles->setAttribute('count', (string) $styles->childElementCount);

        return $this->styles[$key] = $index;
    }

    private static function column(string $reference): int
    {
        $number = 0;
        foreach (str_split((string) preg_replace('/[0-9]+/', '', $reference)) as $letter) {
            $number = $number * 26 + ord($letter) - 64;
        }

        return $number;
    }

    private static function letters(int $number): string
    {
        $letters = '';
        while ($number > 0) {
            $number--;
            $letters = chr(65 + $number % 26).$letters;
            $number = intdiv($number, 26);
        }

        return $letters;
    }
}
