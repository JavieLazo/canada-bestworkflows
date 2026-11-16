<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Contracts\Views;

use App\Platform\ReadModels\Record;
use Carbon\CarbonImmutable;

/**
 * @property-read string $id
 * @property-read string $project_id
 * @property-read string $report_id
 * @property-read int $version
 * @property-read int $template_version
 * @property-read bool $work
 * @property-read bool $financial
 * @property-read list<string> $field_ids
 * @property-read string $payload
 * @property-read string $sha256
 * @property-read string $status
 * @property-read int $submitted_by
 * @property-read int $reviewer_id
 * @property-read list<array<string, mixed>>|null $reviewer_history
 * @property-read int|null $reviewed_by
 * @property-read CarbonImmutable|null $reviewed_at
 * @property-read string|null $review_notes
 * @property-read CarbonImmutable $created_at
 * @property-read CarbonImmutable|null $updated_at
 * @property-read array<string, mixed>|null $snapshot
 */
final readonly class ReportSubmissionView extends Record {}
