<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $name
 * @property string|null $description
 * @property int $owner_id
 * @property CarbonImmutable|null $archived_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 */
class ProjectPortfolio extends BaseModel
{
    /** @var list<string> */
    protected $fillable = ['name', 'description'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['owner_id' => 'integer', 'archived_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }

    /** @return HasMany<ProjectPortfolioMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(ProjectPortfolioMember::class, 'portfolio_id');
    }
}
