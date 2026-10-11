<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A department, or a unit inside one: the two fixed levels positions are
 * placed in (`positions.org_node_id`). One table with a `kind`, because both
 * levels carry the same columns and one bare-number `code` series numbers
 * them both (2026_10_07_0011).
 *
 * A department has no parent; a unit always names its department
 * (`orgn_parent_chk`). That the parent is a DEPARTMENT, that department names
 * are unique among departments, and HR's `sort_order` among siblings are kept
 * by the Organization commands under the `copems:org-structure` mutex — a
 * CHECK cannot read another row, and the composite unique treats the
 * departments' NULL parents as distinct.
 *
 * `code` is minted once and never edited. `parent_id` is resolved by the
 * command from a locked read, never taken as trusted input.
 *
 * @property string $id
 * @property string $code
 * @property string $kind
 * @property string $name
 * @property string|null $description
 * @property string|null $parent_id
 * @property int $sort_order
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read OrgNode|null $parent
 * @property-read Collection<int, OrgNode> $children
 */
class OrgNode extends BaseModel
{
    /** A top-level row: no parent. */
    public const string KIND_DEPARTMENT = 'DEPARTMENT';

    /** A group inside a department: its parent is always a DEPARTMENT. */
    public const string KIND_UNIT = 'UNIT';

    /** @var list<string> */
    public const array KINDS = [self::KIND_DEPARTMENT, self::KIND_UNIT];

    /** @var string */
    protected $table = 'org_nodes';

    /** @var array<string, mixed> */
    protected $attributes = ['sort_order' => 0];

    /** @var list<string> */
    protected $fillable = [
        'code',
        'kind',
        'name',
        'description',
        'parent_id',
        'sort_order',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<OrgNode, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(OrgNode::class, 'parent_id');
    }

    /** @return HasMany<OrgNode, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(OrgNode::class, 'parent_id');
    }
}
