<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\IsReferenceList;
use Carbon\CarbonImmutable;

/**
 * The employment statuses the employee form offers.
 *
 * `Regular` and `Probationary` carry behaviour beyond their label — see
 * `isRegular` and `isProbationary` in `src/lib/hris.ts` — so renaming those two
 * turns that behaviour off (the regularization reminder stops firing). Any
 * other status is a label the system simply carries.
 *
 * The class is named for the concept and the table for the source
 * (`employment_statuses`), so `$table` is explicit here.
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 */
class EmploymentStatusOption extends BaseModel
{
    use IsReferenceList;

    /** @var string */
    protected $table = 'employment_statuses';
}
