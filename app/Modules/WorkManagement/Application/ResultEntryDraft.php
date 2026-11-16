<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application;

final readonly class ResultEntryDraft
{
    public function __construct(
        public string $occurredOn,
        public string $value = '',
        public string $valueText = '',
        public ?string $state = null,
        public string $narrative = '',
        public string $issues = '',
        public string $correctiveAction = '',
        public string $reference = '',
        public ?string $replacesId = null,
        public string $correctionReason = '',
        public ?string $requestId = null,
    ) {}
}
