<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Contracts\Views;

use App\Platform\ReadModels\Record;
use Carbon\CarbonImmutable;

/**
 * Request display data excludes private file paths and persistence objects.
 *
 * @property string $id
 * @property string $employee_id
 * @property string $employee_name
 * @property string $kind
 * @property string $section
 * @property string $operation
 * @property string $status
 * @property string $reason
 * @property string|null $review_note
 * @property string $requested_by_name
 * @property string $submitted_by_name
 * @property string|null $reviewed_by_name
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $submitted_at
 * @property CarbonImmutable|null $reviewed_at
 * @property CarbonImmutable|null $withdrawn_at
 * @property list<array{field:string,label:string,before:?string,after:?string,before_known:bool,after_known:bool}> $changes
 * @property bool $can_review
 * @property bool $can_withdraw
 * @property bool $has_before_evidence
 * @property bool $has_after_evidence
 */
final readonly class RecordChangeRequestView extends Record {}
