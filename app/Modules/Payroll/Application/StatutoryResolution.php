<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application;

use App\Domain\Payroll\StatutorySchedule;

/**
 * Which Contribution & Tax Table versions price a period: the schedule the
 * engine computes from, the four versions as the evidence records them
 * (`tables`: kind => version_id, effective_from, covered_through, figures;
 * a null version_id is the engine's built-in figures), and why the period
 * cannot be computed, or null.
 */
final readonly class StatutoryResolution
{
    /** @param array<string, array{version_id: ?string, effective_from: ?string, covered_through: ?string, figures: array<string, mixed>}> $tables */
    public function __construct(
        public StatutorySchedule $schedule,
        public array $tables,
        public ?string $error,
    ) {}
}
