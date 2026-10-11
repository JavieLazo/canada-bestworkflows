<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\IsReferenceList;
use Carbon\CarbonImmutable;

/**
 * The deduction labels Records Management maintains — and the columns of
 * the Basic Payroll grid's Deductions group: one per row, in code order.
 * `calculation` says how a row is priced (the statutory tables, the DTR's
 * late and undertime minutes) or, when NULL, that an officer types the
 * amount per employee in that column
 * (`App\Modules\Payroll\Domain\DeductionCalculation`).
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property string|null $calculation
 * @property array<string, mixed>|null $percent
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 */
class PayrollDeduction extends BaseModel
{
    use IsReferenceList;

    /** @var string */
    protected $table = 'payroll_deductions';

    /**
     * The trait's initializer, taken over so `calculation` is fillable too.
     * Eloquent calls the method by name for each trait, and a same-named
     * class method wins — the composition stays valid on PHP 8.4, unlike a
     * second `$fillable` declaration would.
     */
    public function initializeIsReferenceList(): void
    {
        $this->fillable = ['code', 'name', 'description', 'calculation', 'percent'];
        // The percentage rule's figures (PercentDeduction); null = typed.
        $this->casts = ['percent' => 'array'];
    }
}
