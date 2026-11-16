<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application;

/** Untrusted cost assumptions, validated together before a draft line changes. */
final readonly class BudgetLineDraft
{
    /** @param array{periodId?: string|null, sourceKind?: string, sourceId?: string|null}|null $reporting */
    public function __construct(
        public string $label,
        public string $categoryId,
        public string $amount,
        public string $basis = 'FLAT',
        public string $quantity = '1',
        public int $frequency = 1,
        public string $unitAmount = '0',
        public ?string $groupId = null,
        public string $notes = '',
        public ?array $reporting = null,
    ) {}
}
