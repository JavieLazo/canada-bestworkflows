<?php

declare(strict_types=1);

namespace App\Livewire\Reports\Support;

use App\Platform\Reports\ReportDocument;

/**
 * What a report's printed sheet is made of, for the Print options dialog: the parts that can be
 * left off the paper and the columns of its table. The keys are the sheet's own `data-print-part`
 * and `data-print-table` names. Leaving something off only hides it on paper; the document, its
 * totals and the CSV are untouched. A payslip is a fixed sheet and offers nothing.
 */
final class PrintOptions
{
    /**
     * @return array{parts: list<array{key: string, label: string}>, tables: list<array{key: string, label: string, columns: list<string>}>}
     */
    public static function for(string $type, string $view, ReportDocument $document, bool $sheets): array
    {
        if ($type === 'payslips') {
            return ['parts' => [], 'tables' => []];
        }
        if ($sheets) {
            return [
                'parts' => [
                    ['key' => 'totals', 'label' => 'Totals for the period'],
                    ['key' => 'notes', 'label' => 'Notes under the table'],
                    ['key' => 'sign', 'label' => 'Signature lines'],
                ],
                'tables' => [['key' => 'main', 'label' => 'Columns of the daily table', 'columns' => $document->sections[0]['columns'] ?? []]],
            ];
        }
        $analysis = $view === 'analysis';
        $parts = $document->summary === [] ? [] : [['key' => 'totals', 'label' => 'Totals at the top']];
        if ($analysis && $type === 'attendance-reports') {
            foreach ($document->charts as $index => $chart) {
                $parts[] = ['key' => 'chart-'.$index, 'label' => $chart['title']];
            }
        } elseif ($analysis && $document->charts !== []) {
            $parts[] = ['key' => 'charts', 'label' => 'Graphs'];
        }
        if ($type === 'payroll-register' && $document->sections !== []) {
            foreach ($document->sections as $index => $section) {
                $parts[] = ['key' => 'section-'.$index, 'label' => $section['title']];
            }
            $parts[] = ['key' => 'sign', 'label' => 'Signatories'];

            return ['parts' => $parts, 'tables' => []];
        }
        if ($analysis) {
            $parts[] = ['key' => 'main', 'label' => 'Group summary'];
            foreach ($document->sections as $index => $section) {
                $parts[] = ['key' => 'section-'.$index, 'label' => $section['title']];
            }
        }

        return ['parts' => $parts, 'tables' => [['key' => 'main', 'label' => $analysis ? 'Columns of the group summary' : 'Columns', 'columns' => $document->columns]]];
    }
}
