<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string|null $portfolio_id
 * @property string $name
 * @property string|null $description
 * @property int $owner_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 */
class ProjectClient extends BaseModel
{
    /** @var list<string> */
    protected $fillable = ['name'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['owner_id' => 'integer', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }

    /** @return HasMany<ProjectClientMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(ProjectClientMember::class, 'client_id');
    }
}
