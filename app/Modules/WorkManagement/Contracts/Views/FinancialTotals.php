<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Contracts\Views;

/** Budget coverage and recorded cash remain separate, including when a budget is missing. */
final readonly class FinancialTotals
{
    public function __construct(
        public string $currency,
        public int $projects,
        public int $budgetedProjects,
        public int $approvedMinor,
        public int $paidMinor,
        public int $budgetedPaidMinor,
        public int $receivedMinor,
    ) {}

    public function budget(): ?int
    {
        return $this->budgetedProjects === 0 ? null : $this->approvedMinor;
    }

    public function remaining(): ?int
    {
        return $this->budgetedProjects === 0 ? null : $this->approvedMinor - $this->budgetedPaidMinor;
    }

    public function netCash(): int
    {
        return $this->receivedMinor - $this->paidMinor;
    }

    public function utilized(): ?int
    {
        return $this->budgetedProjects === 0 || $this->approvedMinor === 0 ? null : (int) round($this->budgetedPaidMinor / $this->approvedMinor * 100);
    }
}
