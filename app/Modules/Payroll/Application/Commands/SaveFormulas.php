<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Domain\Payroll\FormulaPolicy;
use App\Models\PayrollFormula;
use App\Modules\Payroll\Application\FormulaDrafts;
use App\Platform\Audit\RequiredAudit;
use App\Platform\ReadModels\SqlText;
use App\Support\Access;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Saves the Formulas rows — the reference's rate parameters, which Pay Basis
 * & Rates reads as the fallback while no version exists — one row per
 * transaction with its own required audit, so an earlier row's save stands
 * when a later row is refused.
 */
final class SaveFormulas
{
    /** @param array<string, array<string, string>> $drafts
     * @return array{drafts: array<string, array<string, string>>, error: ?string} */
    public function handle(Access $access, array $drafts): array
    {
        // requireAllDataModule("RECORDS") — re-checked in the action itself.
        if (! $access->mayReadAllData(ModuleCode::RECORDS)) {
            throw new RuntimeException('Your role cannot manage organization-wide data.');
        }
        // Same numeric order as FormulaRecords::list — the caller's rows and
        // this loop are one list to the operator saving them.
        $formulas = PayrollFormula::query();
        SqlText::orderNumeric($formulas, 'code', 'asc');

        foreach ($formulas->get() as $row) {
            if (! isset($drafts[$row->key]) || ! FormulaDrafts::changed($row, $drafts)) {
                continue;
            }
            $params = FormulaDrafts::params($row, $drafts);
            // An error stops at the first failed row: the per-row save behavior
            // of formulas-page.tsx.
            $paramsError = FormulaPolicy::paramsError($row->key, array_map(static fn (array $p): array => ['key' => $p['key'], 'value' => $p['value']], $params));
            if ($paramsError !== null) {
                return ['drafts' => $drafts, 'error' => $paramsError];
            }
            try {
                DB::transaction(static function () use ($access, $row, $params): void {
                    $current = PayrollFormula::query()->whereKey($row->id)->lockForUpdate()->first();
                    if ($current === null || $current->params !== $row->params) {
                        throw new RuntimeException('That formula changed. Refresh it before saving.');
                    }
                    $current->params = $params;
                    $current->save();
                    RequiredAudit::record($access, AuditEvent::FORMULA_UPDATED, 'payroll_formulas', $current->id,
                        "{$current->name} set to ".implode(', ', array_map(static fn (array $p): string => "{$p['label']} {$p['value']}", $params)));
                });
            } catch (RuntimeException $error) {
                return ['drafts' => $drafts, 'error' => $error->getMessage().' Any earlier saved formulas remain saved.'];
            }
            // The saved values become the new baseline for the dirty check.
            $drafts[$row->key] = array_combine(array_column($params, 'key'), array_map(static fn (array $p): string => (string) $p['value'], $params));
        }

        return ['drafts' => $drafts, 'error' => null];
    }
}
