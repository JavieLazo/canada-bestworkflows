<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application;

/** Cash entries are corrected through a linked replacement, never by rewriting their amount. */
final readonly class CashEntryDraft
{
    public function __construct(
        public string $kind,
        public string $occurredOn,
        public string $description,
        public string $amount,
        public string $categoryId,
        public ?string $groupId = null,
        public string $party = '',
        public string $reference = '',
        public string $notes = '',
        public ?string $replacesId = null,
        public string $correctionReason = '',
        public ?string $requestId = null,
    ) {}
}
