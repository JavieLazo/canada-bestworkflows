<?php

declare(strict_types=1);

namespace App\Models\Concerns;

/**
 * The five structurally identical reference lists — `positions`, `leave_types`,
 * `payroll_deductions`, `document_types`, `employment_statuses`.
 *
 * They are five tables rather than one with a `kind` column because the demo
 * store had no way to express a partial unique index (prisma/schema.prisma,
 * `Position`). Every one of them is `(id, code UNIQUE, name UNIQUE,
 * description?, created_at)`, except `positions`: a title is unique inside
 * one department or unit (`positions_org_node_name_unq`), not across them,
 * and `Position` takes over this trait's initializer for its placement
 * columns.
 *
 * Two uniques per table is load-bearing for how they are written: Laravel's
 * `upsert()` ignores its `$uniqueBy` argument on MySQL and compiles to
 * `INSERT ... ON DUPLICATE KEY UPDATE`, which fires on *any* unique collision —
 * so an upsert meant to key on `code` that hits an existing `name` rewrites
 * that row's code instead. Use `updateOrCreate` on these tables, never
 * `upsert()` (03-OPEN-ISSUES.md, Part C).
 */
trait IsReferenceList
{
    /**
     * Set through Eloquent's trait initializer rather than declared as a
     * property.
     *
     * `Model` already gets `protected $fillable = []` from the framework's
     * GuardsAttributes trait. A second trait on the same class declaring
     * `$fillable` with a DIFFERENT initial value is a property-composition
     * conflict, and PHP 8.4 rejects it outright:
     *
     *   Model and IsReferenceList define the same property ($fillable) in the
     *   composition of Position. However, the definition differs and is
     *   considered incompatible.
     *
     * PHP 8.5 happens to accept it, which is exactly why a dev machine on 8.5
     * never sees the fatal that production's 8.4 raises.
     *
     * `initialize{TraitName}()` is called by Model::initializeTraits() on every
     * instance, so the value is identical and there is no compile-time clash.
     *
     * `description` is capped at 2000 chars by `ReferenceInput`
     * (src/lib/api/schemas.ts).
     */
    public function initializeIsReferenceList(): void
    {
        $this->fillable = ['code', 'name', 'description'];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
