<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;

/**
 * One signature slot printed on the payroll documents (the payslip and the
 * payroll register): "Prepared by", "Checked by" or "Certified correct by"
 * (`slot`, `App\Modules\Payroll\Domain\Signatories\SignatorySlot`), with the
 * name and designation to print. The account that last set it is kept by id
 * and by name. Nothing here approves a payroll — the signature is collected
 * on paper.
 *
 * `updated_by` and `updated_by_name` are set by the command, never filled.
 *
 * @property string $id
 * @property string $slot
 * @property string|null $name
 * @property string|null $designation
 * @property int|null $updated_by
 * @property string|null $updated_by_name
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 */
class PayrollSignatory extends BaseModel
{
    /** @var string */
    protected $table = 'payroll_signatories';

    /** @var list<string> */
    protected $fillable = ['slot', 'name', 'designation'];
}
