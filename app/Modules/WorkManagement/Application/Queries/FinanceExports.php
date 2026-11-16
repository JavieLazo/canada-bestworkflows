<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Queries;

use App\Modules\WorkManagement\Domain\Projects\FinanceAmount;
use App\Platform\Transfer\CsvDownload;
use App\Platform\Transfer\CsvExport;
use App\Support\Access;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class FinanceExports
{
    public function budget(Access $actor, string $projectId, string $budgetId, string $search = '', string $category = ''): StreamedResponse
    {
        $queries = app(ProjectFinancials::class);
        abort_unless($queries->canView($actor, $projectId), 403);
        abort_if($queries->settings($actor, $projectId) === null || $queries->budget($actor, $projectId, $budgetId) === null, 404);
        $rows = static function () use ($actor, $projectId, $budgetId, $search, $category): \Generator {
            $queries = app(ProjectFinancials::class);
            $settings = $queries->settings($actor, $projectId);
            $budget = $queries->budget($actor, $projectId, $budgetId);
            abort_unless($settings !== null && $budget !== null, 404);
            $categories = $queries->categories($actor, $projectId)->keyBy('id');
            $page = 1;
            do {
                $batch = $queries->lines($actor, $projectId, $budgetId, $page++, 100, $search, $category);
                foreach ($batch as $line) {
                    yield [(string) $budget->version, $budget->status, $line->label,
                        $budget->status === 'APPROVED' ? $line->category_label : ($categories->get($line->category_id)->name ?? $line->category_label),
                        $line->group_label ?? '', $line->basis, $line->quantity_scaled === null ? '' : FinanceAmount::quantityInput($line->quantity_scaled),
                        $line->frequency === null ? '' : (string) $line->frequency, $line->unit_minor === null ? '' : FinanceAmount::input($line->unit_minor),
                        FinanceAmount::input($line->total_minor), $settings->currency, $line->notes ?? ''];
                }
            } while ($batch->hasMorePages());
        };

        return CsvDownload::response(new CsvExport($search === '' ? 'project-budget.csv' : 'project-budget-filtered.csv', ['Version', 'Status', 'Cost item', 'Category', 'Activity', 'Basis', 'Quantity', 'Frequency', 'Unit cost', 'Amount', 'Currency', 'Notes'], $rows));
    }

    /** @param array{from?: string, to?: string, category?: string} $filters */
    public function cash(Access $actor, string $projectId, string $search, string $kind, bool $showVoided, array $filters = []): StreamedResponse
    {
        $queries = app(ProjectFinancials::class);
        abort_unless($queries->canView($actor, $projectId), 403);
        abort_if($queries->settings($actor, $projectId) === null, 404);
        $rows = static function () use ($actor, $projectId, $search, $kind, $showVoided, $filters): \Generator {
            $queries = app(ProjectFinancials::class);
            $settings = $queries->settings($actor, $projectId);
            abort_if($settings === null, 404);
            $page = 1;
            do {
                $batch = $queries->entries($actor, $projectId, $search, $kind, $showVoided, $page++, 100, $filters);
                foreach ($batch as $entry) {
                    yield [$entry->occurred_on->format('Y-m-d'), $entry->kind, $entry->description, $entry->category_label, $entry->group_label ?? '',
                        FinanceAmount::input($entry->amount_minor), $settings->currency, $entry->party ?? '', $entry->reference ?? '',
                        $entry->voided_at === null ? 'POSTED' : 'VOIDED', $entry->replaces_id ?? '', $entry->correction_reason ?? '', $entry->void_reason ?? '', $entry->notes ?? ''];
                }
            } while ($batch->hasMorePages());
        };

        return CsvDownload::response(new CsvExport('project-cash-ledger.csv', ['Date', 'Type', 'Description', 'Category', 'Activity', 'Amount', 'Currency', 'Payer / payee', 'Reference', 'State', 'Replaces', 'Correction reason', 'Void reason', 'Notes'], $rows));
    }
}
