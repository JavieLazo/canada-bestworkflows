<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An adjustment category (2026_11_02_0005): what an adjustment line is —
 * an EARNING, TAXABLE or NOT_TAXED, or a DEDUCTION (taken after the tax)
 * — under a unique name the company picks. `created_by` and its name are
 * set by the command, never filled.
 *
 * @property string $id
 * @property string $name
 * @property string $kind
 * @property string|null $tax
 * @property int|null $created_by
 * @property string|null $created_by_name
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 */
class PayrollAdjustmentCategory extends BaseModel
{
    /** @var string */
    protected $table = 'payroll_adjustment_categories';

    /** @var list<string> */
    protected $fillable = ['name', 'kind', 'tax'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['created_by' => 'integer'];
    }

    /**
     * The lines made under this category.
     *
     * @return HasMany<PayrollAdjustment, $this>
     */
    public function adjustments(): HasMany
    {
        return $this->hasMany(PayrollAdjustment::class, 'category_id');
    }
}
