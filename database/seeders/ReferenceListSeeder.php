<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\DocumentType;
use App\Models\EmploymentStatusOption;
use App\Models\LeaveType;
use App\Models\PayrollDeduction;
use App\Models\Position;
use App\Modules\Payroll\Domain\DeductionCalculation;
use Illuminate\Database\Eloquent\Model;

/**
 * The five managed option lists, from seed.ts referenceList() calls — row keys
 * `pos-1` style, as the reference minted them.
 *
 * The visible id is the row's position as a bare number, not the reference's
 * `POS-001` shape; migration 2026_09_19_0002 converts existing ids to it.
 * ProductionSeeder re-runs this seeder on the live host, so the match below
 * has to be the shape the database actually holds: keyed on the other shape
 * it would find nothing and seed a second copy of all five catalogues.
 *
 * Keyed on `code` (the intended natural unique). Never written with Laravel's
 * upsert(): each table carries TWO unique indexes and MySQL ignores $uniqueBy.
 */
final class ReferenceListSeeder extends PortSeeder
{
    public function run(): void
    {
        // ADD-ONLY: HR renames, places and orders positions on the Position
        // page, and a rename rewrites every holder's record — a re-run that
        // put a seeded name back would undo that behind the cascade's back.
        $this->list(Position::class, 'pos', 'POS', addOnly: true, rows: [
            ['HR Manager', null],
            ['Payroll Specialist', null],
            ['Site Supervisor', null],
            ['Security Officer', null],
            ['Field Technician', null],
            ['Admin Assistant', null],
            ['Driver', null],
            ['Warehouse Clerk', null],
        ]);

        $this->list(LeaveType::class, 'lve', 'LVE', [
            ['Vacation Leave', 'Paid, filed in advance'],
            ['Sick Leave', 'Paid, may require a medical certificate'],
            ['Business Travel / OB', 'Official business outside a work site'],
            ['Emergency Leave', null],
            ['Maternity Leave', null],
            ['Paternity Leave', null],
            ['Leave Without Pay', null],
        ]);

        // Every row is a column of the Basic Payroll grid; the third value is
        // how payroll prices it (DeductionCalculation), and a row without one
        // is typed per employee. Migration 2026_09_30_0010 tags these five on
        // an installation seeded before the column existed; a fresh install
        // gets them tagged here.
        $this->list(PayrollDeduction::class, 'ded', 'DED', [
            ['SSS', 'Social Security System contribution', DeductionCalculation::SSS],
            ['PhilHealth', 'National health insurance premium', DeductionCalculation::PHILHEALTH],
            ['Pag-IBIG', 'Home Development Mutual Fund', DeductionCalculation::PAGIBIG],
            ['Withholding Tax', 'BIR income tax withheld', DeductionCalculation::WITHHOLDING_TAX],
            ['Late / Undertime', null, DeductionCalculation::LATE_UNDERTIME],
            ['Cash Advance', null],
            ['SSS Loan', null],
            ['Pag-IBIG Loan', null],
        ]);

        $this->list(DocumentType::class, 'doc', 'DOC', [
            ['Government ID', 'SSS, PhilHealth, Pag-IBIG, TIN, licences'],
            ['Contract', 'Employment contracts and addenda'],
            ['Certificate', 'Training and clearance certificates'],
            ['Medical', 'Medical certificates and fit-to-work results'],
            ['Résumé', null],
            ['Case', 'Incident reports and disciplinary records'],
            ['Other', null],
        ]);

        $this->list(EmploymentStatusOption::class, 'emp', 'EMP', [
            ['Regular', 'Permanent, past probation'],
            ['Probationary', 'Under evaluation for regularization'],
            ['Contractual', 'Fixed-term engagement'],
            ['Job Order', 'Paid per output or engagement'],
        ]);
    }

    /**
     * `$addOnly` skips a row whose id or name is already there instead of
     * rewriting it, so a re-run never undoes an edit made in the app.
     *
     * @param  class-string<Model>  $model
     * @param  list<array{0: string, 1: string|null, 2?: string}>  $rows
     */
    private function list(string $model, string $idPrefix, string $legacyPrefix, array $rows, bool $addOnly = false): void
    {
        foreach ($rows as $i => $row) {
            [$name, $description] = $row;
            $match = $this->codeMatch($model, 'code', $i + 1, $legacyPrefix);
            if ($addOnly && ! $model::query()->where($match)->exists() && $model::query()->where('name', $name)->exists()) {
                $match = ['name' => $name];
            }
            // The visible code is the row's position, as a bare number — the shape
            // migration 2026_09_19_0002 writes. Matching on the legacy
            // 'POS-001' here would insert a duplicate catalogue on every run.
            $attributes = [
                'id' => $idPrefix.'-'.($i + 1),
                'name' => $name,
                'description' => $description,
                'created_at' => $this->at(300, 9, 0),
            ];
            // Only the payroll deductions carry a calculation; the other four
            // tables have no such column, so the key is set only when given.
            if (($row[2] ?? null) !== null) {
                $attributes['calculation'] = $row[2];
            }
            $this->upsert($model, $match, $attributes, addOnly: $addOnly);
        }
    }
}
