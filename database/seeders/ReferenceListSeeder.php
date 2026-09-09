<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\DocumentType;
use App\Models\EmploymentStatusOption;
use App\Models\LeaveType;
use App\Models\PayrollDeduction;
use App\Models\Position;
use Illuminate\Database\Eloquent\Model;

/**
 * The five managed option lists, from seed.ts referenceList() calls — ids
 * `pos-1` style, codes `POS-001` style, exactly as the reference minted them.
 *
 * Keyed on `code` (the intended natural unique). Never written with Laravel's
 * upsert(): each table carries TWO unique indexes and MySQL ignores $uniqueBy.
 */
final class ReferenceListSeeder extends PortSeeder
{
    public function run(): void
    {
        $this->list(Position::class, 'pos', 'POS', [
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

        $this->list(PayrollDeduction::class, 'ded', 'DED', [
            ['SSS', 'Social Security System contribution'],
            ['PhilHealth', 'National health insurance premium'],
            ['Pag-IBIG', 'Home Development Mutual Fund'],
            ['Withholding Tax', 'BIR income tax withheld'],
            ['Late / Undertime', null],
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
     * @param  class-string<Model>  $model
     * @param  list<array{0: string, 1: string|null}>  $rows
     */
    private function list(string $model, string $idPrefix, string $codePrefix, array $rows): void
    {
        foreach ($rows as $i => [$name, $description]) {
            $this->upsert($model, ['code' => sprintf('%s-%03d', $codePrefix, $i + 1)], [
                'id' => $idPrefix.'-'.($i + 1),
                'name' => $name,
                'description' => $description,
                'created_at' => $this->at(300, 9, 0),
            ]);
        }
    }
}
