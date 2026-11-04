<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\ScopesToActor;
use Carbon\CarbonImmutable;

/**
 * What a tax year already carried for one employee before this system
 * posted it (payroll_tax_balances, 2026_11_02_0012): the go-live OPENING
 * balance or a new hire's PREVIOUS_EMPLOYER figures — the taxable income
 * and the tax withheld — with the reason and the paper behind it. A DRAFT
 * waits for approval; an APPROVED row joins the
 * year's posted figures the whole-year withholding estimate reads
 * (TaxYearToDate, divergence 20).
 *
 * `prepared_by` and `approved_by` are actor columns: set from Access,
 * never fillable. Row scoping widens on PAYROLL alone, like the payslips.
 *
 * Owner: Payroll.
 *
 * @property string $id
 * @property string $employee_id
 * @property int $tax_year
 * @property string $kind
 * @property float $taxable_income
 * @property float $tax_withheld
 * @property string $reason
 * @property string|null $reference
 * @property string $status
 * @property int|null $prepared_by
 * @property string|null $prepared_by_name
 * @property int|null $approved_by
 * @property string|null $approved_by_name
 * @property CarbonImmutable|null $approved_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 */
class PayrollTaxBalance extends BaseModel
{
    use ScopesToActor;

    public const string DRAFT = 'DRAFT';

    public const string APPROVED = 'APPROVED';

    public const string KIND_OPENING = 'OPENING';

    public const string KIND_PREVIOUS_EMPLOYER = 'PREVIOUS_EMPLOYER';

    /** The two kinds, with their names on screen. */
    public const array KINDS = [self::KIND_OPENING => 'Opening balance', self::KIND_PREVIOUS_EMPLOYER => 'Previous employer'];

    /** @var string */
    protected $table = 'payroll_tax_balances';

    /** @var list<string> */
    protected $fillable = ['employee_id', 'tax_year', 'kind', 'taxable_income', 'tax_withheld', 'reason', 'reference', 'status'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tax_year' => 'integer',
            'taxable_income' => 'float',
            'tax_withheld' => 'float',
            'prepared_by' => 'integer',
            'approved_by' => 'integer',
            'approved_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
