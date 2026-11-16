<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application;

final readonly class IndicatorDraft
{
    public function __construct(
        public string $name,
        public string $kind = 'NUMERIC',
        public string $unit = '',
        public string $aggregation = 'SUM',
        public string $direction = 'AT_LEAST',
        public string $target = '',
        public string $targetText = '',
        public string $description = '',
        public ?string $outcomeId = null,
        public ?string $groupId = null,
        public ?string $taskId = null,
        public ?string $startsOn = null,
        public ?string $endsOn = null,
        public bool $evidenceRequired = false,
        public string $status = 'ACTIVE',
        public string $reason = '',
    ) {}
}
