<?php

declare(strict_types=1);

namespace App\Modules\Organization\Infrastructure;

use App\Models\Position;
use App\Models\WorkSite;
use App\Modules\Organization\Application\StructureReads;
use App\Modules\Organization\Contracts\OrganizationReferences;
use App\Modules\Organization\Contracts\Views\PositionPlacementView;

/** Resolves reference facts for already-authorized business operations. */
final class OrganizationReferenceQuery implements OrganizationReferences
{
    public function siteExists(string $id): bool
    {
        return WorkSite::query()->whereKey($id)->exists();
    }

    public function siteName(string $id): ?string
    {
        return WorkSite::query()->whereKey($id)->value('name');
    }

    public function siteAssignable(string $id, ?string $current = null): bool
    {
        return WorkSite::query()->whereKey($id)->when($id !== $current, static fn ($query) => $query->where('is_active', true))->exists();
    }

    public function positionForAssignment(string $id): ?PositionPlacementView
    {
        return $this->placements([$id])[$id] ?? null;
    }

    /**
     * @param  list<string>  $positionIds
     * @return array<string, PositionPlacementView>
     */
    public function placements(array $positionIds): array
    {
        $ids = array_values(array_unique(array_filter($positionIds, static fn (string $id): bool => $id !== '')));
        if ($ids === []) {
            return [];
        }
        $positions = Position::query()->whereKey($ids)->get(StructureReads::ready() ? ['id', 'code', 'name', 'org_node_id'] : ['id', 'code', 'name']);
        if ($positions->isEmpty()) {
            return [];
        }
        $structure = StructureReads::structure();
        $placements = [];
        foreach ($positions as $position) {
            $placements[$position->id] = StructureReads::placement($position, $structure);
        }

        return $placements;
    }
}
