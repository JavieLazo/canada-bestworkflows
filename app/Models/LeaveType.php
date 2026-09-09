<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\IsReferenceList;
use Carbon\CarbonImmutable;

/**
 * Leave types. A name normalised to a key becomes leave_travel_requests.request_type.
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 */
class LeaveType extends BaseModel
{
    use IsReferenceList;

    /** @var string */
    protected $table = 'leave_types';
}
