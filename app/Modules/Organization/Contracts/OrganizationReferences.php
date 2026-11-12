<?php

declare(strict_types=1);

namespace App\Modules\Organization\Contracts;

use App\Modules\Organization\Contracts\Views\PositionPlacementView;

/**
 * Internal reference facts and validation for other owners' writes — sites,
 * and the position an employee record links to with where it sits — plus
 * the structure mutex those writes share. Never exposes organization
 * persistence objects.
 */
interface OrganizationReferences
{
    /**
     * The named mutex every write of the structure, of a position, and of an
     * employee's `position_id` takes FIRST in its transaction
     * (`TransactionMutex::acquire`), before any row lock. One sentinel makes
     * the name checks a composite unique cannot make (NULL parents are
     * distinct) and a rename's copy onto its holders race-free.
     */
    public const string STRUCTURE_LOCK = 'copems:org-structure';

    public function siteExists(string $id): bool;

    /** Current name for a snapshot in an already-authorized business write. */
    public function siteName(string $id): ?string;

    /**
     * Whether a site may be chosen for a NEW assignment: it exists and is
     * active. `$current` is the site the record already names; keeping it is
     * not a new assignment, so an inactive site stays valid where it is.
     */
    public function siteAssignable(string $id, ?string $current = null): bool;

    /**
     * The position an employee record may be linked to, with where it sits,
     * or null when no such position exists. The caller holds STRUCTURE_LOCK,
     * so the answer stands until its transaction ends.
     */
    public function positionForAssignment(string $id): ?PositionPlacementView;

    /**
     * Where each of these positions sits, keyed by position id. Ids that name
     * no position are left out. Batched: one read of the positions, one of
     * the structure, however many ids.
     *
     * @param  list<string>  $positionIds
     * @return array<string, PositionPlacementView>
     */
    public function placements(array $positionIds): array;

    /**
     * Who heads an office: the unit's head where it has one, else its
     * department's. Null when neither is set.
     *
     * @return array{name: string, designation: string}|null
     */
    public function officeHead(?string $unitId, ?string $departmentId): ?array;
}
