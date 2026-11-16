<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Contracts\Views;

use App\Platform\ReadModels\Record;
use Carbon\CarbonImmutable;

/**
 * @property-read string $id
 * @property-read string $project_id
 * @property-read string $template_id
 * @property-read string $period_id
 * @property-read string|null $budget_id
 * @property-read string $title
 * @property-read string $status
 * @property-read bool $work
 * @property-read bool $financial
 * @property-read string|null $summary
 * @property-read string|null $issues
 * @property-read string|null $corrective_action
 * @property-read string|null $amendment_reason
 * @property-read int $revision
 * @property-read string|null $current_submission_id
 * @property-read int $created_by
 * @property-read int $updated_by
 * @property-read CarbonImmutable $created_at
 * @property-read CarbonImmutable|null $updated_at
 * @property-read string|null $period_name
 * @property-read string|null $template_name
 * @property-read string|null $due_on
 */
final readonly class ReportView extends Record {}
