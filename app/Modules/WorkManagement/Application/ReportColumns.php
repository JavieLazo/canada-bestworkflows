<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application;

use App\Modules\WorkManagement\Domain\Projects\FinanceAmount;
use App\Modules\WorkManagement\Domain\Projects\ResultValue;

final class ReportColumns
{
    /** @var array<string, array<string, string>> */
    public const SECTIONS = [
        'report_answers' => ['id' => 'Report ID'],
        'profile' => ['id' => 'Project ID', 'name' => 'Project', 'client' => 'Client / Internal', 'description' => 'Description', 'starts_on' => 'Start', 'ends_on' => 'Deadline'],
        'work' => ['id' => 'Task ID', 'code' => 'Code', 'title' => 'Task', 'status' => 'Status', 'priority' => 'Priority', 'starts_on' => 'Start', 'due_on' => 'Deadline', 'people' => 'People'],
        'outcomes' => ['id' => 'Outcome ID', 'kind' => 'Kind', 'name' => 'Objective / deliverable', 'description' => 'Description', 'status' => 'Status'],
        'results' => ['id' => 'Indicator ID', 'outcome' => 'Objective / deliverable', 'work' => 'Work', 'name' => 'Indicator', 'kind' => 'Kind', 'unit' => 'Unit', 'aggregation' => 'Aggregation', 'direction' => 'Target condition', 'target_scaled' => 'Target', 'actual_scaled' => 'Period actual / latest value', 'cumulative_scaled' => 'Cumulative actual', 'target_text' => 'Qualitative target', 'actual_text' => 'Qualitative actual', 'actual_state' => 'Actual state', 'last_recorded_on' => 'Last recorded', 'target_starts_on' => 'Target start', 'target_ends_on' => 'Target end'],
        'accomplishments' => ['id' => 'Entry ID', 'indicator_id' => 'Indicator ID', 'occurred_on' => 'Actual date', 'value_scaled' => 'Numeric actual', 'value_text' => 'Qualitative actual', 'state' => 'Actual state', 'narrative' => 'Accomplishment', 'issues' => 'Issues', 'corrective_action' => 'Corrective action', 'reference' => 'Reference'],
        'budget' => ['id' => 'Cost ID', 'label' => 'Cost item', 'category_label' => 'Category', 'group_label' => 'Activity', 'basis' => 'Cost basis', 'quantity_scaled' => 'Quantity', 'frequency' => 'Frequency', 'unit_minor' => 'Unit cost', 'total_minor' => 'Amount', 'period_label' => 'Reporting period', 'source_label' => 'Funding / contribution', 'notes' => 'Notes'],
        'cash' => ['id' => 'Cash ID', 'occurred_on' => 'Actual date', 'kind' => 'Type', 'description' => 'Description', 'category_label' => 'Category', 'amount_minor' => 'Amount', 'party' => 'Payer / payee', 'reference' => 'Reference', 'notes' => 'Notes'],
        'allocations' => ['id' => 'Allocation ID', 'entry_id' => 'Cash ID', 'source_kind' => 'Source kind', 'source_label' => 'Source', 'installment_label' => 'Installment', 'group_label' => 'Activity', 'amount_minor' => 'Allocated amount'],
        'funding' => ['id' => 'Funding ID', 'name' => 'Funding', 'provider' => 'Provider', 'committed_minor' => 'Committed', 'received_minor' => 'Period cash received', 'cumulative_received_minor' => 'Cumulative cash received', 'expected_on' => 'Expected date', 'status' => 'Status', 'notes' => 'Notes'],
        'installments' => ['id' => 'Installment ID', 'funding_id' => 'Funding ID', 'name' => 'Installment', 'planned_minor' => 'Planned amount', 'planned_on' => 'Planned date', 'conditions' => 'Conditions', 'status' => 'Status'],
        'contributions' => ['id' => 'Contribution ID', 'name' => 'Contribution', 'contributor' => 'Contributor', 'kind' => 'Cash / in-kind', 'unit' => 'Unit', 'quantity_scaled' => 'Committed quantity', 'committed_minor' => 'Committed value', 'received_quantity' => 'Period delivered quantity', 'received_minor' => 'Period received value', 'cumulative_received_minor' => 'Cumulative received value', 'cumulative_quantity_scaled' => 'Cumulative delivered quantity', 'valuation_basis' => 'Valuation basis', 'expected_on' => 'Expected date', 'status' => 'Status'],
        'deliveries' => ['id' => 'Delivery ID', 'contribution_id' => 'Contribution ID', 'occurred_on' => 'Actual date', 'quantity_scaled' => 'Delivered quantity', 'value_minor' => 'Recorded value', 'valuation_basis' => 'Valuation basis', 'reference' => 'Reference', 'notes' => 'Notes'],
    ];

    public const ENTITIES = ['report_answers' => 'REPORT', 'profile' => 'PROJECT', 'work' => 'TASK', 'outcomes' => 'OUTCOME', 'results' => 'INDICATOR', 'accomplishments' => 'RESULT_ENTRY', 'budget' => 'BUDGET_LINE', 'cash' => 'CASH_ENTRY', 'funding' => 'FUNDING', 'contributions' => 'CONTRIBUTION', 'deliveries' => 'CONTRIBUTION_DELIVERY'];

    public static function type(string $key): string
    {
        if (str_ends_with($key, '_on')) {
            return 'DATE';
        }
        if (str_ends_with($key, '_minor')) {
            return 'MONEY';
        }
        if (str_ends_with($key, '_scaled') || $key === 'received_quantity') {
            return 'NUMBER';
        }

        return $key === 'frequency' ? 'INTEGER' : 'TEXT';
    }

    public static function display(mixed $value, string $type): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        if (is_array($value)) {
            return count($value).' files';
        }

        return match ($type) {
            'MONEY' => FinanceAmount::format((int) $value), 'NUMBER' => ResultValue::format((int) $value), default => (string) $value,
        };
    }

    /** @param array<string, mixed> $data
     * @return list<array{label: string, value: mixed, type: string}>
     */
    public static function financialSummary(array $data): array
    {
        if (! ($data['scope']['financial'] ?? false)) {
            return [];
        }
        $baseline = $data['budget_baseline'];
        $variance = $data['period_variance'] ?? [];

        return [
            ['label' => (($baseline['status'] ?? '') === 'DRAFT' ? 'Budget draft' : 'Approved budget').($baseline === null ? '' : ' · v'.$baseline['version']), 'value' => $baseline['total_minor'] ?? null, 'type' => 'MONEY'],
            ['label' => 'Period opening cash', 'value' => $data['period_opening_minor'] ?? null, 'type' => 'MONEY'],
            ['label' => 'Period recorded receipts', 'value' => $variance['received_minor'] ?? null, 'type' => 'MONEY'],
            ['label' => 'Period paid expenses', 'value' => $variance['paid_minor'] ?? null, 'type' => 'MONEY'],
            ['label' => 'Recorded cash through '.$data['actual_through'], 'value' => $data['recorded_cash_position_minor'] ?? null, 'type' => 'MONEY'],
            ['label' => 'Period allocated plan', 'value' => $variance['planned_minor'] ?? null, 'type' => 'MONEY'],
            ['label' => 'Period paid minus plan', 'value' => $variance['variance_minor'] ?? null, 'type' => 'MONEY'],
        ];
    }

    /** @param array<string, mixed> $data
     * @return array{columns: array<string, array{label: string, type: string, total: bool}>, rows: list<array<string, mixed>>}
     */
    public static function section(array $data, string $section): array
    {
        $columns = [];
        foreach (self::SECTIONS[$section] ?? [] as $key => $label) {
            $columns[$key] = ['label' => $label, 'type' => self::type($key), 'total' => in_array($section, ['budget', 'allocations', 'funding', 'installments', 'contributions', 'deliveries'], true) && str_ends_with($key, '_minor') && $key !== 'unit_minor'];
        }
        $fields = array_filter($data['fields'] ?? [], static fn (array $field): bool => $field['entity_type'] === (self::ENTITIES[$section] ?? ''));
        foreach ($fields as $field) {
            $columns['field:'.$field['id']] = ['label' => 'Field: '.$field['name'].($field['unit'] ? ' ('.$field['unit'].')' : ''), 'type' => $field['type'], 'total' => $field['total']];
        }
        $rows = [];
        foreach ($data['sections'][$section] ?? [] as $row) {
            foreach ($fields as $field) {
                $row['field:'.$field['id']] = $data['answers'][$field['id']][$row['id']] ?? null;
            }
            $rows[] = array_intersect_key($row, $columns);
        }

        return compact('columns', 'rows');
    }
}
