<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application;

use App\Platform\Transfer\XlsxWorkbook;

final class ReportWorkbook
{
    /** @param array<string, mixed> $data */
    public function bytes(array $data, string $version, ?string $sha256 = null): string
    {
        $context = $version.' · '.$data['period']['name'].' · '.$data['period']['starts_on'].' to '.$data['period']['ends_on'].($data['currency'] === null ? '' : ' · '.$data['currency']);
        $summary = [['label' => 'Report', 'value' => $data['title']], ['label' => 'Version', 'value' => $version], ['label' => 'Template', 'value' => $data['template']['name']],
            ['label' => 'Actuals through', 'value' => $data['actual_through']], ['label' => 'Summary', 'value' => $data['summary']], ['label' => 'Issues', 'value' => $data['issues']],
            ['label' => 'Corrective action', 'value' => $data['corrective_action']], ['label' => 'Submitted SHA-256', 'value' => $sha256],
            ['label' => 'Editing', 'value' => 'Add rows inside each table. Add custom columns in BestWorkflows before mapping them back.']];
        foreach (array_merge($data['coverage']['blocking'], $data['coverage']['warnings']) as $message) {
            $summary[] = ['label' => 'Coverage', 'value' => $message];
        }
        foreach (ReportColumns::financialSummary($data) as $row) {
            $summary[] = ['label' => $row['label'], 'value' => $row['value'], '_types' => ['value' => $row['type']]];
        }
        $summary[] = ['label' => 'Template version', 'value' => $data['template']['revision'], '_types' => ['value' => 'INTEGER']];
        $sheets = [['name' => 'Summary', 'title' => $data['title'], 'context' => $context, 'columns' => ['label' => ['label' => 'Item', 'type' => 'TEXT', 'total' => false], 'value' => ['label' => 'Value', 'type' => 'TEXT', 'total' => false]], 'rows' => $summary]];
        $sections = $data['sections'];
        $sections['report_answers'] = [['id' => $data['report_id']]];
        foreach ($sections as $section => $rows) {
            $source = $data;
            $source['sections'][$section] = $rows;
            $table = ReportColumns::section($source, $section);
            if ($table['columns'] === [] || ($section === 'report_answers' && count($table['columns']) === 1)) {
                continue;
            }
            if ($section === 'budget' && isset($table['columns']['total_minor'])) {
                $table['columns']['entered_minor'] = ['label' => 'Entered amount', 'type' => 'MONEY', 'total' => false];
                $table['columns']['total_minor']['formula'] = 'IF({label}="","",IF({basis}="CALCULATED",IF(COUNT({quantity_scaled},{frequency},{unit_minor})=3,ROUND({quantity_scaled}*{frequency}*{unit_minor},2),""),IF(ISNUMBER({entered_minor}),{entered_minor},"")))';
                foreach ($table['rows'] as &$row) {
                    $row['entered_minor'] = ($row['basis'] ?? '') === 'FLAT' ? $row['total_minor'] : null;
                }unset($row);
            }
            $name = $section === 'report_answers' ? 'Report answers' : ucfirst($section);
            $sheets[] = ['name' => $name, 'title' => $name, 'context' => $context, 'columns' => $table['columns'], 'rows' => $table['rows'], 'blank_rows' => 5];
        }
        if ($data['files'] !== []) {
            $sheets[] = ['name' => 'Evidence', 'title' => 'Included evidence', 'context' => $context,
                'columns' => ['id' => ['label' => 'File ID', 'type' => 'TEXT', 'total' => false], 'file_name' => ['label' => 'File', 'type' => 'TEXT', 'total' => false], 'sha256' => ['label' => 'SHA-256', 'type' => 'TEXT', 'total' => false]], 'rows' => $data['files']];
        }
        $metadata = [['key' => 'schema_version', 'value' => '1'], ['key' => 'project_id', 'value' => $data['project_id']], ['key' => 'report_id', 'value' => $data['report_id']], ['key' => 'template_id', 'value' => $data['template']['id']], ['key' => 'period_id', 'value' => $data['period']['id']], ['key' => 'version', 'value' => $version]];
        $sheets[] = ['name' => '_ems', 'title' => 'BestWorkflows references', 'context' => '', 'hidden' => true, 'columns' => ['key' => ['label' => 'Reference', 'type' => 'TEXT', 'total' => false], 'value' => ['label' => 'Value', 'type' => 'TEXT', 'total' => false]], 'rows' => $metadata];

        return app(XlsxWorkbook::class)->bytes($sheets);
    }
}
