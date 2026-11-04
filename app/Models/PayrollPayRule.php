<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;

/**
 * One dated version of the Pay Basis & Rates for one scope — `COMPANY`,
 * an Employment list entry's id whose employees it overrides, or one
 * employee's id — in force from `effective_from`, with the figures
 * `App\Domain\Payroll\PayRules` reads (`params`) and the reason the
 * officer typed. A version's figures never change once saved: a change is
 * a new version.
 *
 * `scope_type` names the kind of scope (2026_10_30_0001): an employee id
 * fills the 26 characters `scope` has, so the kind cannot ride in it. A row
 * created without one is the company's or a status's, by its scope. An
 * EMPLOYEE row may have NULL `params`: from that date the employee follows
 * their status's or the company's rules again (PayRulesStore).
 *
 * `status` (2026_11_02_0002) is DRAFT or APPROVED: an EMPLOYEE version is
 * prepared by one person (`created_by`) and approved by another
 * (`approved_by`, `approved_at`) before it prices them; the company's and
 * a status's versions are saved approved. Only an approved version is
 * ever in force (PayRulesStore::approved()).
 *
 * `scope_type`, `status`, `created_by`, `approved_by` and their names are
 * set by the commands, never filled.
 *
 * @property string $id
 * @property string $scope
 * @property string|null $scope_type
 * @property CarbonImmutable $effective_from
 * @property string|null $reason
 * @property array<string, mixed>|null $params
 * @property string $status
 * @property int|null $created_by
 * @property string|null $created_by_name
 * @property int|null $approved_by
 * @property string|null $approved_by_name
 * @property CarbonImmutable|null $approved_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 */
class PayrollPayRule extends BaseModel
{
    public const string DRAFT = 'DRAFT';

    public const string APPROVED = 'APPROVED';

    public const string COMPANY = 'COMPANY';

    public const string SCOPE_COMPANY = 'COMPANY';

    public const string SCOPE_STATUS = 'STATUS';

    public const string SCOPE_EMPLOYEE = 'EMPLOYEE';

    /** @var string */
    protected $table = 'payroll_pay_rules';

    /** @var list<string> */
    protected $fillable = ['scope', 'effective_from', 'reason', 'params'];

    protected static function booted(): void
    {
        parent::booted();

        static::creating(static function (self $row): void {
            if ($row->scope_type === null && Schema::hasColumn('payroll_pay_rules', 'scope_type')) {
                $row->scope_type = $row->scope === self::COMPANY ? self::SCOPE_COMPANY : self::SCOPE_STATUS;
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'effective_from' => 'immutable_date:Y-m-d',
            'params' => 'array',
            'created_by' => 'integer',
            'approved_by' => 'integer',
            'approved_at' => 'immutable_datetime',
        ];
    }
}
