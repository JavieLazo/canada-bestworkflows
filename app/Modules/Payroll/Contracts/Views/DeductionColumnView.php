<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Contracts\Views;

use App\Modules\Payroll\Domain\DeductionCalculation;
use App\Platform\ReadModels\Record;

/**
 * One column of the Basic Payroll grid's Deductions group: a Payroll
 * Deductions row as the screen may know it. `calculation` names the
 * computed money it shows (`DeductionCalculation`), or is null for a column
 * an officer types per employee. `id` is the catalog row on a draft and null
 * for a column read back from a finalized run's evidence; `code` is '' only
 * for a finalized run whose evidence does not record its columns.
 *
 * @property-read string|null $id
 * @property-read string $code
 * @property-read string $name
 * @property-read string|null $calculation
 * @property-read array<string, mixed>|null $percent  the percentage rule's figures (PercentDeduction), null for a typed or computed column
 */
final readonly class DeductionColumnView extends Record
{
    /** What a per-employee amount is filed under: the calculation, or the row's code. */
    public function key(): string
    {
        return $this->calculation ?? 'row:'.$this->code;
    }

    public function isAutomatic(): bool
    {
        return $this->calculation !== null;
    }

    /** A column priced as a percentage of salary, which a typed amount overrides. */
    public function isPercent(): bool
    {
        return is_array($this->percent);
    }

    /**
     * Whether the row heads a column of its own. The Late / Undertime row
     * does not: its money is the two fixed attendance columns, shown while
     * the row exists.
     */
    public function headsColumn(): bool
    {
        return $this->calculation !== DeductionCalculation::LATE_UNDERTIME;
    }
}
