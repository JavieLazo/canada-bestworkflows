<?php

declare(strict_types=1);

namespace App\Livewire\Records;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Domain\Payroll\FormulaPolicy;
use App\Livewire\Records\Support\Hris;
use App\Models\PayrollFormula;
use App\Support\Access;
use App\Support\AuditService;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Payroll Formula Settings — the port of
 * src/app/(protected)/records/payroll-formulas/formulas-page.tsx.
 *
 * The OT / SIL / NSD / REST_DAY parameter editor feeding PayrollRates: stored
 * values are the starting point, edits live in drafts until saved, and a
 * sample monthly rate walks the same two steps a payroll run takes so each
 * formula's produce is visible while it is tuned.
 *
 * HOW EACH FORMULA IS COMPUTED lives in code, exactly as the source insists
 * (formulas-page.tsx:20-34): "the numbers can stay data — payroll should
 * never evaluate a string typed by a user". The `expression` column is
 * display text only and nothing here parses it. The preview below mirrors the
 * page's own CALCULATIONS map; the payroll run itself computes through the
 * domain layer (PayrollRates), never through this screen.
 *
 * Permissions, exactly as the reference:
 *  - the PAGE needs RECORDS — route middleware;
 *  - READS are open to any session (GET /api/records/payroll-formulas);
 *  - the WRITE needs all_data + RECORDS and passes FormulaPolicy
 *    (api/records/payroll-formulas/[id]/route.ts), re-checked in the action.
 */
#[Layout('layouts.app')]
#[Title('Formulas')]
class FormulasPage extends Component
{
    /** The sample monthly rate the derivation runs on. Plain form state. */
    public string $monthlyRate = '17000';

    public string $daysWorked = '22';

    public string $hoursInADay = '8';

    /**
     * Edits, keyed `formula key => param key => value`, until saved
     * (formulas-page.tsx:44-58). Keyed by the formula's `key` rather than its
     * id so nothing id-bearing is client-writable; `save()` re-resolves every
     * row from the database and takes only the VALUES from here — key set,
     * labels and which calculation a row drives all stay server-side.
     *
     * @var array<string, array<string, string>>
     */
    public array $drafts = [];

    public function mount(): void
    {
        $this->syncDrafts($this->formulas());
    }

    /**
     * Stored values are the starting point; edits live in drafts until saved
     * (formulas-page.tsx:53-58). Existing draft entries are left alone.
     *
     * @param  EloquentCollection<int, PayrollFormula>  $rows
     */
    private function syncDrafts(EloquentCollection $rows): void
    {
        foreach ($rows as $row) {
            if (! isset($this->drafts[$row->key])) {
                $draft = [];

                foreach ($row->params as $param) {
                    $draft[(string) $param['key']] = (string) $param['value'];
                }

                $this->drafts[$row->key] = $draft;
            }
        }
    }

    /** @return EloquentCollection<int, PayrollFormula> */
    private function formulas(): EloquentCollection
    {
        // listPayrollFormulas orders by code (lib/data/records.ts:131-136).
        return PayrollFormula::query()->orderBy('code')->get();
    }

    /**
     * The shape of each calculation — the CALCULATIONS map
     * (formulas-page.tsx:26-34).
     *
     * @param  array<string, float>  $p
     */
    private function compute(string $key, float $daily, float $hourly, array $p): float
    {
        return match ($key) {
            'OT' => $hourly * ($p['percentage'] ?? 0.0) * ($p['max_ot'] ?? 0.0),
            'SIL' => ($p['days_per_month'] ?? 0.0) != 0.0 && ($p['months'] ?? 0.0) != 0.0
                ? ($daily / $p['days_per_month']) * (($p['max_leave'] ?? 0.0) / $p['months'])
                : 0.0,
            'NSD' => $hourly * ($p['percentage'] ?? 0.0) * ($p['max_nsd'] ?? 0.0),
            'REST_DAY' => $hourly * ($p['percentage'] ?? 0.0) * ($p['max_hours'] ?? 0.0),
            default => 0.0,
        };
    }

    /**
     * A row's params with the draft values folded in — key and label from the
     * database, value from the edit.
     *
     * @return list<array{key: string, label: string, value: float}>
     */
    private function draftParams(PayrollFormula $row): array
    {
        $draft = $this->drafts[$row->key] ?? [];
        $params = [];

        foreach ($row->params as $param) {
            $key = (string) $param['key'];
            $value = $draft[$key] ?? $param['value'];

            $params[] = [
                'key' => $key,
                'label' => (string) $param['label'],
                'value' => is_numeric($value) ? (float) $value : 0.0,
            ];
        }

        return $params;
    }

    private function isChanged(PayrollFormula $row): bool
    {
        foreach ($this->draftParams($row) as $i => $param) {
            $stored = $row->params[$i]['value'] ?? null;

            if (! is_numeric($stored) || (float) $stored !== $param['value']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Save every changed formula — the loop of formulas-page.tsx `save()`
     * with the API's own gate and policy per row
     * (api/records/payroll-formulas/[id]/route.ts).
     */
    public function save(): void
    {
        $access = app(Access::class);

        // requireAllDataModule("RECORDS") — re-checked in the action itself.
        if (! $access->mayReadAllData(ModuleCode::RECORDS)) {
            Flux::toast(variant: 'danger', text: 'Your role cannot manage organization-wide data.');

            return;
        }

        foreach ($this->formulas() as $row) {
            if (! isset($this->drafts[$row->key]) || ! $this->isChanged($row)) {
                continue;
            }

            $params = $this->draftParams($row);

            // The fixed calculation contract (payroll-formula-policy.ts). An
            // error stops the save, exactly as the source returns on the first
            // failed row.
            $paramsError = FormulaPolicy::paramsError(
                $row->key,
                array_map(static fn (array $p): array => ['key' => $p['key'], 'value' => $p['value']], $params),
            );

            if ($paramsError !== null) {
                Flux::toast(variant: 'danger', text: $paramsError);

                return;
            }

            $row->params = $params;
            $row->save();

            AuditService::record(
                $access,
                AuditEvent::FORMULA_UPDATED,
                'payroll_formulas',
                (string) $row->getKey(),
                "{$row->name} set to ".implode(', ', array_map(
                    static fn (array $p): string => "{$p['label']} {$p['value']}",
                    $params,
                )),
            );

            // The saved values become the new baseline for the dirty check.
            $this->drafts[$row->key] = array_combine(
                array_column($params, 'key'),
                array_map(static fn (array $p): string => (string) $p['value'], $params),
            );
        }

        Flux::toast(variant: 'success', text: 'Formula settings saved.');
    }

    public function render(): View
    {
        $rows = $this->formulas();
        $this->syncDrafts($rows);

        // The same two steps a payroll run takes (formulas-page.tsx:66-79):
        // the monthly rate over the days that month is scheduled, then that
        // over a working day's hours.
        $monthly = is_numeric($this->monthlyRate) ? (float) $this->monthlyRate : 0.0;
        $days = is_numeric($this->daysWorked) ? (float) $this->daysWorked : 0.0;
        $hoursPerDay = is_numeric($this->hoursInADay) ? (float) $this->hoursInADay : 0.0;
        $pay = $days > 0 ? $monthly / $days : 0.0;
        $hourly = $hoursPerDay > 0 ? $pay / $hoursPerDay : 0.0;

        $results = [];
        $dirty = false;

        foreach ($rows as $row) {
            $values = [];

            foreach ($this->draftParams($row) as $param) {
                $values[$param['key']] = $param['value'];
            }

            $results[$row->key] = Hris::round2($this->compute($row->key, $pay, $hourly, $values));
            $dirty = $dirty || $this->isChanged($row);
        }

        return view('livewire.records.formulas-page', [
            'rows' => $rows,
            'results' => $results,
            'dirty' => $dirty,
            'monthly' => $monthly,
            'days' => $days,
            'hoursPerDay' => $hoursPerDay,
            'pay' => $pay,
            'hourly' => $hourly,
        ]);
    }
}
