<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Models\EmployeeAddress;
use App\Models\EmployeeChild;
use App\Models\EmployeeEducation;
use App\Models\EmployeeEligibility;
use App\Models\EmployeeOtherItem;
use App\Models\EmployeePdsDetail;
use App\Models\EmployeeProfile;
use App\Models\EmployeeTraining;
use App\Models\EmployeeVoluntaryWork;
use App\Models\EmployeeWorkExperience;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Workforce\Application\Queries\EmployeePdsWorkbook;
use App\Support\Access;
use Illuminate\Support\Str;

/**
 * Employee data fills the supplied CSC form's actual cells and controls.
 * The workbook keeps literal identifiers, Excel dates and the template's
 * layout/evidence boxes, and exports every overflow row without a foreign
 * employee's private data or a silent reinterpretation of legacy fields.
 */

/** @param array<string, mixed> $values */
function pdsworkbookEmployee(array $values = []): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill($values + [
        'employee_code' => 'COP-'.Str::upper(Str::random(6)), 'first_name' => 'Rosa', 'last_name' => 'Villanueva',
        'position' => 'Accountant II', 'position_level' => 'Senior', 'employment_status' => 'Regular',
        'date_hired' => '2024-01-03', 'monthly_rate' => 25000,
    ])->save();

    return $employee->refresh();
}

function pdsworkbookActor(?EmployeeProfile $own = null): Access
{
    $role = new Role;
    $role->forceFill(['name' => 'Workbook '.Str::random(8), 'all_data' => $own === null, 'is_system' => false])->save();
    RoleModuleGrant::query()->create(['role_id' => $role->id, 'module' => ModuleCode::EMPLOYEES]);
    $user = User::factory()->create();
    $user->forceFill(['role_id' => $role->id, 'employee_id' => $own?->id])->save();

    return Access::forActor($user->refresh());
}

/** @return array<string, string> */
function pdsworkbookParts(string $content): array
{
    $path = tempnam(sys_get_temp_dir(), 'ems-pds-test-');
    if ($path === false) {
        throw new RuntimeException('Unable to create workbook test file.');
    }
    $zip = new ZipArchive;
    try {
        file_put_contents($path, $content);
        expect($zip->open($path))->toBeTrue();
        $parts = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            $bytes = $zip->getFromIndex($i);
            if ($name === false || $bytes === false) {
                throw new RuntimeException('Unreadable workbook ZIP entry.');
            }
            $parts[$name] = $bytes;
        }
        $zip->close();

        return $parts;
    } finally {
        unlink($path);
    }
}

/** @return array<string, string> */
function pdsworkbookExport(EmployeeProfile $employee, ?Access $actor = null): array
{
    return pdsworkbookParts(app(EmployeePdsWorkbook::class)->download($actor ?? pdsworkbookActor(), $employee->id)['content']);
}

function pdsworkbookXml(string $xml): DOMXPath
{
    $document = new DOMDocument;
    expect($document->loadXML($xml, LIBXML_NONET))->toBeTrue();
    $xpath = new DOMXPath($document);
    foreach ([
        's' => 'http://schemas.openxmlformats.org/spreadsheetml/2006/main',
        'v' => 'urn:schemas-microsoft-com:vml',
        'x' => 'urn:schemas-microsoft-com:office:excel',
    ] as $prefix => $namespace) {
        $xpath->registerNamespace($prefix, $namespace);
    }

    return $xpath;
}

/** @return list<DOMElement> */
function pdsworkbookElements(DOMXPath $xpath, string $query): array
{
    $nodes = $xpath->query($query);
    if ($nodes === false) {
        throw new RuntimeException('Invalid workbook assertion query: '.$query);
    }
    $elements = [];
    foreach ($nodes as $node) {
        if (! $node instanceof DOMElement) {
            throw new RuntimeException('Expected a workbook XML element: '.$query);
        }
        $elements[] = $node;
    }

    return $elements;
}

function pdsworkbookElement(DOMXPath $xpath, string $query): ?DOMElement
{
    return pdsworkbookElements($xpath, $query)[0] ?? null;
}

/** @param array<string, string> $parts */
function pdsworkbookCell(array $parts, int $sheet, string $cell): DOMElement
{
    $node = pdsworkbookElement(pdsworkbookXml($parts['xl/worksheets/sheet'.$sheet.'.xml']), '//s:c[@r="'.$cell.'"]');
    if (! $node instanceof DOMElement) {
        throw new RuntimeException('Missing workbook cell '.$sheet.'!'.$cell);
    }

    return $node;
}

/** @param array<string, string> $parts */
function pdsworkbookValue(array $parts, int $sheet, string $cell): string
{
    $node = pdsworkbookCell($parts, $sheet, $cell);
    if ($node->getAttribute('t') === 's') {
        return (string) pdsworkbookXml($parts['xl/sharedStrings.xml'])->evaluate('string(//s:si['.((int) $node->textContent + 1).'])');
    }

    return $node->textContent;
}

/** @param array<string, string> $parts */
function pdsworkbookFormat(array $parts, int $sheet, string $cell): string
{
    $node = pdsworkbookCell($parts, $sheet, $cell);
    $styles = pdsworkbookXml($parts['xl/styles.xml']);
    $format = (string) $styles->evaluate('string(//s:cellXfs/s:xf['.((int) $node->getAttribute('s') + 1).']/@numFmtId)');

    return (string) $styles->evaluate('string(//s:numFmt[@numFmtId="'.$format.'"]/@formatCode)');
}

/** @param array<string, string> $parts */
function pdsworkbookDate(array $parts, int $sheet, string $cell, int $serial): void
{
    $node = pdsworkbookCell($parts, $sheet, $cell);
    expect($node->getAttribute('t'))->not->toBe('inlineStr')
        ->and($node->textContent)->toBe((string) $serial)
        ->and(pdsworkbookFormat($parts, $sheet, $cell))->toBe('dd/mm/yyyy');
}

/** @param array<string, string> $parts */
function pdsworkbookControl(array $parts, int $control, string $vmlId, bool $checked): void
{
    $properties = pdsworkbookXml($parts['xl/ctrlProps/ctrlProp'.$control.'.xml']);
    $drawing = $control < 13 ? 1 : 2;
    $vml = pdsworkbookXml($parts['xl/drawings/vmlDrawing'.$drawing.'.vml']);
    expect($properties->evaluate('string(/*/@checked)'))->toBe($checked ? 'Checked' : 'Unchecked')
        ->and($vml->evaluate('string(//v:shape[@id="'.$vmlId.'"]/x:ClientData/x:Checked)'))->toBe($checked ? '1' : '0');
}

/** @param array<string, mixed> $values */
function pdsworkbookDetails(EmployeeProfile $employee, array $values): void
{
    $row = EmployeePdsDetail::query()->firstOrNew(['employee_id' => $employee->id]);
    $row->forceFill(['employee_id' => $employee->id] + $values)->save();
}

/** @return list<array<string, string>> */
function pdsworkbookContinuedRows(string $xml): array
{
    $rows = [];
    $current = [];
    foreach (pdsworkbookElements(pdsworkbookXml($xml), '//s:sheetData/s:row[@r > 3]') as $row) {
        $cells = [];
        foreach ($row->childNodes as $node) {
            if ($node instanceof DOMElement && $node->localName === 'c') {
                $column = (string) preg_replace('/[0-9]+/', '', $node->getAttribute('r'));
                $cells[$column] = $node->textContent;
            }
        }
        if (($cells['A'] ?? '') !== '' && $current !== []) {
            $rows[] = $current;
            $current = [];
        }
        foreach ($cells as $column => $value) {
            $current[$column] = ($current[$column] ?? '').$value;
        }
    }
    if ($current !== []) {
        $rows[] = $current;
    }

    return $rows;
}

it('fills the four form pages with employee values, literal IDs and numeric calendar dates', function () {
    config(['copems.company_name' => 'Fixture Company']);
    $employee = pdsworkbookEmployee([
        'middle_name' => 'Santos', 'suffix' => 'Jr.', 'birth_date' => '1990-05-04', 'place_of_birth' => 'Tayabas City',
        'sex' => 'Female', 'civil_status' => 'Other', 'height_cm' => 162.5, 'weight_kg' => 55.5, 'blood_type' => 'O+',
        'pagibig_no' => '000012345678', 'philhealth_no' => '000098765432', 'tin' => '001234567', 'employee_id_no' => '000042',
        'telephone' => '042-123-4567', 'phone' => '09171234567', 'email' => 'rosa@example.test',
    ]);
    pdsworkbookDetails($employee, [
        'civil_status_other' => 'Annulled', 'citizenship_kind' => 'DUAL', 'dual_citizenship_by' => 'BIRTH',
        'dual_citizenship_country' => 'Japan', 'umid_no' => '000000000001', 'permanent_address_same' => true,
        'spouse_surname' => 'Dela Cruz', 'spouse_first_name' => 'Juan', 'spouse_middle_name' => 'Reyes', 'spouse_name_extension' => 'Sr.',
        'spouse_occupation' => 'Teacher', 'spouse_employer' => 'Example School', 'spouse_business_address' => 'Lucena City', 'spouse_telephone' => '042-111-2222',
        'father_surname' => 'Villanueva', 'father_first_name' => 'Pedro', 'father_middle_name' => 'Ramos', 'father_name_extension' => 'Sr.',
        'mother_surname' => 'Santos', 'mother_first_name' => 'Maria', 'mother_middle_name' => 'Garcia',
        'decl_34a' => true, 'decl_34a_details' => 'Cousin of the head', 'decl_34b' => false,
        'decl_35b' => true, 'decl_35b_details' => 'Case details', 'decl_35b_date_filed' => '2020-03-12', 'decl_35b_case_status' => 'Dismissed',
        'decl_39' => true, 'decl_39_country' => 'Canada', 'decl_40a' => true, 'decl_40a_specify' => 'Aeta',
        'decl_40b' => true, 'decl_40b_id_no' => 'PWD-0001', 'decl_40c' => true, 'decl_40c_id_no' => 'SP-0001',
    ]);
    EmployeeAddress::query()->create([
        'employee_id' => $employee->id, 'type' => 'RESIDENTIAL', 'house_no' => 'Blk 5 Lot 2', 'street' => 'Rizal Street',
        'subdivision' => 'Green Village', 'barangay' => 'Ilasan', 'city' => 'Tayabas', 'province' => 'Quezon', 'zip_code' => '04327',
    ]);
    EmployeeChild::query()->create(['employee_id' => $employee->id, 'position' => 0, 'name' => 'Ana Dela Cruz', 'birth_date' => '2010-01-02']);
    EmployeeEducation::query()->create([
        'employee_id' => $employee->id, 'level' => 'COLLEGE', 'school' => '=College Name', 'course' => 'BS Accountancy',
        'attended_from' => 2008, 'attended_to' => 2012, 'units_earned' => 'Complete', 'year_graduated' => 2012, 'honors' => 'Cum laude',
    ]);
    EmployeeEligibility::query()->create([
        'employee_id' => $employee->id, 'name' => 'Career Service Professional', 'rating' => '85.40', 'exam_date' => '2013-08-04',
        'exam_place' => 'Lucena City', 'license_no' => '0000123', 'license_valid_until' => '2027-12-31',
    ]);
    EmployeeWorkExperience::query()->create([
        'employee_id' => $employee->id, 'position_title' => 'Junior Accountant', 'company' => 'ABC Trading',
        'date_from' => '2013-01-07', 'date_to' => '2016-05-31', 'monthly_salary' => 18000.50,
        'pay_grade' => '11-2', 'appointment_status' => 'Permanent', 'government_service' => true,
    ]);
    EmployeeVoluntaryWork::query()->create([
        'employee_id' => $employee->id, 'organization' => 'Red Cross', 'organization_address' => 'Tayabas City',
        'date_from' => '2015-06-01', 'date_to' => '2017-08-09', 'hours' => 120, 'position' => 'Volunteer',
    ]);
    EmployeeTraining::query()->create([
        'employee_id' => $employee->id, 'title' => '+Payroll Training', 'date_from' => '2023-03-06', 'date_to' => '2023-03-08',
        'hours' => 24, 'ld_type' => 'Technical', 'sponsor' => 'Bureau of Internal Revenue',
    ]);
    foreach (['SKILL' => '@Bookkeeping', 'DISTINCTION' => '-Service Award', 'MEMBERSHIP' => '=Association'] as $kind => $text) {
        EmployeeOtherItem::query()->create(['employee_id' => $employee->id, 'kind' => $kind, 'position' => 0, 'text' => $text]);
    }

    $parts = pdsworkbookExport($employee);
    $expected = [
        1 => [
            'D10' => 'Villanueva', 'D11' => 'Rosa', 'D12' => 'Santos', 'D15' => 'Tayabas City', 'E20' => 'Annulled',
            'D22' => '1.625', 'D24' => '55.5', 'D25' => 'O+', 'D27' => '000000000001', 'D29' => '000012345678',
            'D31' => '000098765432', 'D33' => '001234567', 'D34' => '000042', 'I32' => '042-123-4567', 'I33' => '09171234567', 'I34' => 'rosa@example.test',
            'I17' => 'Blk 5 Lot 2', 'L17' => 'Rizal Street', 'I19' => 'Green Village', 'L19' => 'Ilasan', 'I22' => 'Tayabas', 'L22' => 'Quezon', 'I24' => '04327',
            'I25' => 'Blk 5 Lot 2', 'L25' => 'Rizal Street', 'I27' => 'Green Village', 'L27' => 'Ilasan', 'I29' => 'Tayabas', 'L29' => 'Quezon', 'I31' => '04327',
            'D36' => 'Dela Cruz', 'D37' => 'Juan', 'D38' => 'Reyes', 'D39' => 'Teacher', 'D40' => 'Example School', 'D41' => 'Lucena City', 'D42' => '042-111-2222',
            'D43' => 'Villanueva', 'D44' => 'Pedro', 'D45' => 'Ramos', 'D47' => 'Santos', 'D48' => 'Maria', 'D49' => 'Garcia', 'I37' => 'Ana Dela Cruz',
            'D57' => '=College Name', 'G57' => 'BS Accountancy', 'J57' => '2008', 'K57' => '2012', 'L57' => 'Complete', 'M57' => '2012', 'N57' => 'Cum laude',
            'Q14' => 'Japan',
        ],
        2 => [
            'A5' => 'Career Service Professional', 'F5' => '85.40', 'I5' => 'Lucena City', 'L5' => '0000123',
            'C18' => 'Present', 'D18' => 'Accountant II', 'G18' => 'Fixture Company', 'J18' => '25000', 'K18' => 'Senior', 'L18' => 'Regular', 'M18' => 'N',
            'D19' => 'Junior Accountant', 'G19' => 'ABC Trading', 'J19' => '18000.5', 'K19' => '11-2', 'L19' => 'Permanent', 'M19' => 'Y',
        ],
        3 => [
            'G6' => '120', 'H6' => 'Volunteer', 'A18' => '+Payroll Training', 'G18' => '24', 'H18' => 'Technical', 'I18' => 'Bureau of Internal Revenue',
            'A42' => '@Bookkeeping', 'C42' => '-Service Award', 'I42' => '=Association',
        ],
        4 => ['J21' => 'Dismissed', 'I39' => 'Canada', 'K44' => 'Aeta', 'K46' => 'PWD-0001', 'K48' => 'SP-0001'],
    ];
    foreach ($expected as $sheet => $cells) {
        foreach ($cells as $cell => $value) {
            expect(pdsworkbookValue($parts, $sheet, $cell))->toBe($value, $sheet.'!'.$cell);
        }
    }
    foreach (['L11' => 'Jr.', 'G37' => 'Sr.', 'G44' => 'Sr.'] as $cell => $suffix) {
        expect(pdsworkbookValue($parts, 1, $cell))->toContain('NAME EXTENSION')->toContain($suffix);
    }
    expect(pdsworkbookValue($parts, 3, 'A6'))->toContain('Red Cross')->toContain('Tayabas City')
        ->and(pdsworkbookValue($parts, 4, 'I11'))->toBe('See PDS Details')
        ->and(pdsworkbookValue($parts, 4, 'G19'))->toContain('If YES, give details:')->toContain('See PDS Details')
        ->and(implode("\n", $parts))->toContain('Cousin of the head')->toContain('Case details');
    foreach ([[1, 'D13', 32997], [1, 'M37', 40180], [2, 'G5', 41490], [2, 'M5', 46752], [2, 'A18', 45294], [2, 'A19', 41281], [2, 'C19', 42521], [3, 'E6', 42156], [3, 'F6', 42956], [3, 'E18', 44991], [3, 'F18', 44993], [4, 'J20', 43902]] as [$sheet, $cell, $serial]) {
        pdsworkbookDate($parts, $sheet, $cell, $serial);
    }
    foreach ([[1, 'D27'], [1, 'D29'], [1, 'D57'], [2, 'L5'], [3, 'A18'], [3, 'A42'], [3, 'C42'], [3, 'I42']] as [$sheet, $cell]) {
        $node = pdsworkbookCell($parts, $sheet, $cell);
        expect($node->getAttribute('t'))->toBe('inlineStr')
            ->and($node->getElementsByTagName('f')->length)->toBe(0)
            ->and(pdsworkbookFormat($parts, $sheet, $cell))->toBe('@');
    }
    $template = file_get_contents(resource_path('templates/CS-Form-No.-212-Revised-2026-PDS.xlsx'));
    assert(is_string($template));
    $original = pdsworkbookParts($template);
    foreach ([44, 46, 48] as $row) {
        foreach (['G', 'H', 'I', 'J'] as $column) {
            expect(pdsworkbookValue($parts, 4, $column.$row))->toBe(pdsworkbookValue($original, 4, $column.$row), 'Question 40 label '.$column.$row);
        }
    }
    pdsworkbookControl($parts, 2, '_x0000_s1045', false);
    pdsworkbookControl($parts, 3, '_x0000_s1046', true);
    pdsworkbookControl($parts, 4, '_x0000_s1049', false);
    pdsworkbookControl($parts, 5, '_x0000_s1050', true);
    pdsworkbookControl($parts, 9, '_x0000_s1061', true);
    pdsworkbookControl($parts, 11, '_x0000_s1063', true);
    pdsworkbookControl($parts, 12, '_x0000_s1064', false);
    foreach ([13 => true, 14 => false, 15 => false, 16 => true, 17 => false, 18 => false, 19 => true, 20 => false] as $control => $checked) {
        pdsworkbookControl($parts, $control, 'Check_x0020_Box_x0020_'.($control - 12), $checked);
    }
    pdsworkbookControl($parts, 29, 'Check_x0020_Box_x0020_17', true);
    pdsworkbookControl($parts, 32, 'Check_x0020_Box_x0020_20', false);
});

it('preserves the fixed template layout, drawings and untouched ZIP parts and leaves execution evidence empty', function () {
    $template = file_get_contents(resource_path('templates/CS-Form-No.-212-Revised-2026-PDS.xlsx'));
    assert(is_string($template));
    $original = pdsworkbookParts($template);
    $parts = pdsworkbookExport(pdsworkbookEmployee());
    foreach ($original as $name => $bytes) {
        if (preg_match('~^xl/(worksheets/sheet[1-4]\.xml|styles\.xml|drawings/vmlDrawing[12]\.vml|ctrlProps/ctrlProp(?:[2-9]|[12][0-9]|3[0-2]|3[4-7])\.xml)$~', $name)) {
            continue;
        }
        expect($parts[$name] ?? null)->toBe($bytes, $name);
    }
    $inputMerges = [
        1 => ['D11:K11', 'D12:N12', 'I29:K29', 'L29:N29'],
        2 => [],
        3 => [
            'A29:D29', 'A30:D30', 'A31:D31', 'A32:D32', 'A33:D33', 'A34:D34',
            'I19:K19', 'I20:K20', 'I21:K21', 'I22:K22', 'I23:K23', 'I24:K24', 'I25:K25',
            'I29:K29', 'I30:K30', 'I31:K31', 'I32:K32', 'I33:K33', 'I34:K34',
        ],
        4 => ['J20:L20', 'J21:L21', 'K44:L44', 'K46:L46', 'K48:L48'],
    ];
    foreach (range(1, 4) as $sheet) {
        $before = pdsworkbookXml($original['xl/worksheets/sheet'.$sheet.'.xml']);
        $after = pdsworkbookXml($parts['xl/worksheets/sheet'.$sheet.'.xml']);
        $expectedMerges = $inputMerges[$sheet];
        foreach (pdsworkbookElements($before, '//s:mergeCell') as $merge) {
            $expectedMerges[] = $merge->getAttribute('ref');
        }
        $expectedMerges = array_values(array_unique($expectedMerges));
        sort($expectedMerges);
        $actualMerges = [];
        foreach (pdsworkbookElements($after, '//s:mergeCell') as $merge) {
            $actualMerges[] = $merge->getAttribute('ref');
        }
        sort($actualMerges);
        expect($actualMerges)->toBe($expectedMerges, 'Only the missing input merges may supplement C'.$sheet)
            ->and($after->evaluate('string(//s:mergeCells/@count)'))->toBe((string) count($actualMerges));
        foreach (['pageMargins', 'pageSetup', 'drawing', 'legacyDrawing', 'rowBreaks', 'colBreaks'] as $tag) {
            $first = pdsworkbookElement($before, '//s:'.$tag);
            $second = pdsworkbookElement($after, '//s:'.$tag);
            expect($second?->C14N())->toBe($first?->C14N(), $sheet.' '.$tag);
        }
        expect($after->evaluate('count(//s:row)'))->toBe($before->evaluate('count(//s:row)'));
        foreach (pdsworkbookElements($before, '//s:row') as $row) {
            $reference = $row->getAttribute('r');
            expect($after->evaluate('string(//s:row[@r="'.$reference.'"]/@ht)'))->toBe($row->getAttribute('ht'));
        }
    }
    foreach (['A52', 'F52', 'G52', 'A53', 'F53', 'G53', 'A54', 'F54', 'G54', 'D61', 'D62', 'D64', 'F60', 'F64', 'K58'] as $cell) {
        expect(pdsworkbookValue($parts, 4, $cell))->toBe(pdsworkbookValue($original, 4, $cell), 'Unstored evidence '.$cell);
    }
    expect(pdsworkbookXml($parts['xl/workbook.xml'])->evaluate('string(//s:sheet[@name="Lookup"]/@state)'))->toBe('hidden');
});

it('exports all overflow lists on continuation sheets without including another employee', function () {
    $employee = pdsworkbookEmployee();
    $other = pdsworkbookEmployee();
    $expected = [];
    foreach (range(1, 30) as $number) {
        $name = sprintf('Child entry %02d', $number);
        EmployeeChild::query()->create(['employee_id' => $employee->id, 'position' => $number, 'name' => $name, 'birth_date' => '2010-01-02']);
        $expected[] = $name;
    }
    foreach (range(1, 3) as $number) {
        $name = sprintf('College entry %02d', $number);
        EmployeeEducation::query()->create(['employee_id' => $employee->id, 'level' => 'COLLEGE', 'school' => $name, 'attended_from' => 2000 + $number]);
        $expected[] = $name;
    }
    foreach (range(1, 9) as $number) {
        $name = sprintf('Eligibility entry %02d', $number);
        EmployeeEligibility::query()->create(['employee_id' => $employee->id, 'name' => $name, 'exam_date' => '2013-08-04']);
        $expected[] = $name;
        $name = sprintf('Voluntary entry %02d', $number);
        EmployeeVoluntaryWork::query()->create(['employee_id' => $employee->id, 'organization' => $name, 'date_from' => '2015-06-01']);
        $expected[] = $name;
    }
    foreach (range(1, 30) as $number) {
        $name = sprintf('Previous job entry %02d', $number);
        EmployeeWorkExperience::query()->create(['employee_id' => $employee->id, 'position_title' => $name, 'company' => 'Former Company', 'date_from' => '2013-01-07', 'date_to' => '2016-05-31']);
        $expected[] = $name;
    }
    foreach (range(1, 24) as $number) {
        $name = sprintf('Training entry %02d', $number);
        EmployeeTraining::query()->create(['employee_id' => $employee->id, 'title' => $name, 'date_from' => '2023-03-06', 'date_to' => '2023-03-08']);
        $expected[] = $name;
    }
    foreach (['SKILL', 'DISTINCTION', 'MEMBERSHIP'] as $kind) {
        foreach (range(1, 10) as $number) {
            $name = sprintf('%s entry %02d', $kind, $number);
            EmployeeOtherItem::query()->create(['employee_id' => $employee->id, 'kind' => $kind, 'position' => $number, 'text' => $name]);
            $expected[] = $name;
        }
    }
    EmployeeTraining::query()->create(['employee_id' => $other->id, 'title' => 'FOREIGN PRIVATE TRAINING', 'date_from' => '2023-03-06', 'date_to' => '2023-03-08']);
    EmployeeChild::query()->create(['employee_id' => $other->id, 'position' => 0, 'name' => 'FOREIGN PRIVATE CHILD']);
    $parts = pdsworkbookExport($employee, pdsworkbookActor($employee));
    $values = [];
    foreach ($parts as $name => $xml) {
        if (! preg_match('~^xl/worksheets/sheet[0-9]+\.xml$~', $name)) {
            continue;
        }
        $sheet = pdsworkbookXml($xml);
        if (str_starts_with((string) $sheet->evaluate('string(//s:c[@r="A1"]/s:is)'), 'Full text — ')) {
            continue;
        }
        foreach (pdsworkbookElements($sheet, '//s:c[@t="inlineStr"]/s:is') as $node) {
            $values[] = $node->textContent;
        }
    }
    foreach ($expected as $value) {
        expect(count(array_filter($values, static fn (string $actual): bool => $actual === $value)))->toBe(1, $value);
    }
    expect($values)->not->toContain('FOREIGN PRIVATE TRAINING')
        ->not->toContain('FOREIGN PRIVATE CHILD');
    $workbook = pdsworkbookXml($parts['xl/workbook.xml']);
    $names = [];
    foreach (pdsworkbookElements($workbook, '//s:sheet') as $node) {
        $names[] = $node->getAttribute('name');
    }
    expect(array_slice($names, 0, 5))->toBe(['C1', 'C2', 'C3', 'C4', 'Lookup'])
        ->and($names)->toContain('Children - continued', 'Education - continued', 'Eligibility - continued', 'Work experience - continued', 'Voluntary work - continued', 'Training - continued', 'Other information - continued');
    foreach (array_slice($names, 5) as $name) {
        expect($workbook->evaluate('count(//s:definedName[@name="_xlnm.Print_Titles" and contains(text(), "'.$name.'")])'))->toBe(1.0);
    }
});

it('preserves unsplit legacy values and PCN separately then prefers structured replacements', function () {
    $legacy = [
        'address' => 'Legacy full residential address', 'citizenship' => 'Legacy full citizenship',
        'spouse_name' => 'Legacy complete spouse name', 'spouse_employment' => 'Legacy complete spouse employment',
        'college' => 'Legacy college school', 'college_course' => 'Legacy college degree', 'college_year_graduated' => 'Units only',
        'graduate_school' => 'Legacy graduate school', 'graduate_course' => 'Legacy graduate degree', 'graduate_year_graduated' => '2009',
    ];
    $employee = pdsworkbookEmployee($legacy);
    pdsworkbookDetails($employee, ['philsys_pcn' => '0000-1111-2222-3333']);
    $parts = pdsworkbookExport($employee);
    $xml = implode("\n", $parts);
    foreach ($legacy as $value) {
        expect($xml)->toContain($value);
    }
    expect(pdsworkbookValue($parts, 1, 'I17'))->toBe('See PDS Details')
        ->and(pdsworkbookValue($parts, 1, 'D36'))->toBe('See PDS Details')
        ->and(pdsworkbookValue($parts, 1, 'D57'))->toBe('Legacy college school')
        ->and(pdsworkbookValue($parts, 1, 'M57'))->toBe('Units only')
        ->and(pdsworkbookValue($parts, 1, 'D32'))->toBe('N/A')
        ->and($xml)->toContain('PCN; not PSN')->toContain('0000-1111-2222-3333');
    pdsworkbookControl($parts, 2, '_x0000_s1045', false);
    pdsworkbookControl($parts, 3, '_x0000_s1046', false);

    pdsworkbookDetails($employee, ['citizenship_kind' => 'FILIPINO', 'spouse_surname' => 'Structured surname', 'spouse_occupation' => 'Structured occupation']);
    EmployeeAddress::query()->create(['employee_id' => $employee->id, 'type' => 'RESIDENTIAL', 'house_no' => 'Structured house', 'city' => 'Structured city']);
    EmployeeEducation::query()->create(['employee_id' => $employee->id, 'level' => 'COLLEGE', 'school' => 'Structured college']);
    $parts = pdsworkbookExport($employee);
    $xml = implode("\n", $parts);
    foreach (array_slice($legacy, 0, 7) as $value) {
        expect($xml)->not->toContain($value);
    }
    expect(pdsworkbookValue($parts, 1, 'I17'))->toBe('Structured house')
        ->and(pdsworkbookValue($parts, 1, 'I22'))->toBe('Structured city')
        ->and(pdsworkbookValue($parts, 1, 'D36'))->toBe('Structured surname')
        ->and(pdsworkbookValue($parts, 1, 'D39'))->toBe('Structured occupation')
        ->and(pdsworkbookValue($parts, 1, 'D57'))->toBe('Structured college')
        ->and(pdsworkbookValue($parts, 1, 'D58'))->toBe('Legacy graduate school')
        ->and($employee->refresh()->only(array_keys($legacy)))->toBe($legacy);
    pdsworkbookControl($parts, 2, '_x0000_s1045', true);
    pdsworkbookControl($parts, 3, '_x0000_s1046', false);
});

it('keeps all unanswered declaration pairs unticked and writes every answered pair independently', function () {
    $employee = pdsworkbookEmployee();
    $questions = [
        'decl_34a' => [13, 14, 1, 2], 'decl_34b' => [15, 16, 3, 4], 'decl_35a' => [17, 18, 5, 6],
        'decl_35b' => [19, 20, 7, 8], 'decl_36' => [21, 22, 9, 10], 'decl_37' => [23, 24, 11, 12],
        'decl_38a' => [34, 35, 26, 27], 'decl_38b' => [36, 37, 28, 29], 'decl_39' => [25, 26, 13, 14],
        'decl_40a' => [27, 30, 15, 18], 'decl_40b' => [28, 31, 16, 19], 'decl_40c' => [29, 32, 17, 20],
    ];
    $parts = pdsworkbookExport($employee);
    foreach ($questions as [$yes, $no, $yesVml, $noVml]) {
        pdsworkbookControl($parts, $yes, 'Check_x0020_Box_x0020_'.$yesVml, false);
        pdsworkbookControl($parts, $no, 'Check_x0020_Box_x0020_'.$noVml, false);
    }
    $answers = [];
    foreach (array_keys($questions) as $index => $key) {
        $answers[$key] = $index % 2 === 0;
    }
    pdsworkbookDetails($employee, $answers);
    $parts = pdsworkbookExport($employee);
    foreach ($questions as $key => [$yes, $no, $yesVml, $noVml]) {
        pdsworkbookControl($parts, $yes, 'Check_x0020_Box_x0020_'.$yesVml, $answers[$key]);
        pdsworkbookControl($parts, $no, 'Check_x0020_Box_x0020_'.$noVml, ! $answers[$key]);
    }
    expect(pdsworkbookValue($parts, 4, 'I15'))->toBe('')
        ->and(pdsworkbookValue($parts, 4, 'J21'))->toBe('N/A');
});

it('retains long Unicode values in the form and printable full-text continuations', function () {
    $title = mb_substr(str_repeat('Développement et formation professionnelle 日本語 ', 8), 0, 255);
    $address = mb_substr(str_repeat('Barangay Peña — Tayabas City, Quezon; ', 10), 0, 255);
    $declaration = rtrim(mb_substr(str_repeat('Déclaration complète avec détails 日本語. ', 30), 0, 255));
    $employee = pdsworkbookEmployee(['first_name' => 'José', 'last_name' => 'Dela Peña']);
    pdsworkbookDetails($employee, ['decl_35a' => true, 'decl_35a_details' => $declaration]);
    EmployeeTraining::query()->create(['employee_id' => $employee->id, 'title' => $title, 'date_from' => '2023-03-06', 'date_to' => '2023-03-08']);
    EmployeeVoluntaryWork::query()->create(['employee_id' => $employee->id, 'organization' => 'Humanitarian Association', 'organization_address' => $address]);

    $parts = pdsworkbookExport($employee);
    expect(pdsworkbookValue($parts, 1, 'D10'))->toBe('Dela Peña')
        ->and(pdsworkbookValue($parts, 1, 'D11'))->toBe('José')
        ->and(pdsworkbookValue($parts, 3, 'A18'))->toBe($title)
        ->and(pdsworkbookValue($parts, 3, 'A6'))->toContain($address)
        ->and(pdsworkbookValue($parts, 4, 'I15'))->toBe('See PDS Details');
    $entries = [];
    foreach ($parts as $name => $xml) {
        if (! preg_match('~^xl/worksheets/sheet(?:[6-9]|[1-9][0-9]+)\.xml$~', $name)) {
            continue;
        }
        $entries = array_merge($entries, pdsworkbookContinuedRows($xml));
    }
    $fullText = collect($entries)->keyBy('A');
    expect($fullText->get('C3!A18')['B'] ?? null)->toBe($title)
        ->and($fullText->get('C3!A6')['B'] ?? '')->toContain($address)
        ->and(collect($entries)->first(static fn (array $row): bool => ($row['A'] ?? '') === 'Declarations' && ($row['B'] ?? '') === '35a')['C'] ?? null)->toBe($declaration);
});

it('displays every education year as an integer and keeps legacy year text literal', function () {
    $employee = pdsworkbookEmployee();
    foreach (['ELEMENTARY', 'SECONDARY', 'VOCATIONAL', 'COLLEGE', 'GRADUATE'] as $index => $level) {
        EmployeeEducation::query()->create([
            'employee_id' => $employee->id, 'level' => $level, 'school' => 'School '.$level,
            'attended_from' => 2000 + $index, 'attended_to' => 2004 + $index, 'year_graduated' => 2004 + $index,
        ]);
    }
    $parts = pdsworkbookExport($employee);
    foreach (range(54, 58) as $row) {
        foreach (['J' => 2000, 'K' => 2004, 'M' => 2004] as $column => $year) {
            $cell = $column.$row;
            expect(pdsworkbookCell($parts, 1, $cell)->getAttribute('t'))->not->toBe('inlineStr')
                ->and(pdsworkbookValue($parts, 1, $cell))->toBe((string) ($year + $row - 54))
                ->and(pdsworkbookFormat($parts, 1, $cell))->toBe('0');
        }
    }
    $legacy = pdsworkbookEmployee(['college' => 'Earlier school', 'college_year_graduated' => 'Units only']);
    $parts = pdsworkbookExport($legacy);
    expect(pdsworkbookValue($parts, 1, 'M57'))->toBe('Units only')
        ->and(pdsworkbookCell($parts, 1, 'M57')->getAttribute('t'))->toBe('inlineStr')
        ->and(pdsworkbookFormat($parts, 1, 'M57'))->toBe('@');
});
