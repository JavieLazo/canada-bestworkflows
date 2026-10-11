<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application;

use App\Models\EmployeeProfile;
use App\Modules\Organization\Contracts\OrganizationReferences;
use App\Platform\Database\TransactionMutex;
use RuntimeException;

/**
 * The one writer of an employee's link to the Position list. The record
 * stores `position_id` and, beside it, the position's name in `position`:
 * every reader that shows a job title (the record, the lists, the 201 sheet,
 * Employment History, payroll) keeps reading that text, and a rename on the
 * Position page rewrites it on every holder (PositionNameCopies).
 *
 * The form sends one choice under FORM_KEY:
 *   - a position id: the record is linked to it and takes its CURRENT name,
 *     re-read here under the structure mutex — never a name or a placement
 *     sent by the page, so a department or unit picked on the form decides
 *     nothing;
 *   - '' (blank): no position, linked or typed;
 *   - KEEP: whatever the record holds stays as it is — chiefly a title typed
 *     before the Position list existed ("Messenger"), which is not on the
 *     list and so cannot be chosen again, but which HR must be able to keep
 *     while saving the rest of Employee Details. A hire has nothing to keep.
 *
 * Before the column's migration has run (PositionLink) a blank or KEEP still
 * saves, and any other choice is refused with NOT_READY.
 */
final class EmployeePosition
{
    /** The form key the Position select binds to. */
    public const string FORM_KEY = 'position_id';

    /** The choice that leaves the stored position untouched. */
    public const string KEEP = '__keep__';

    public const string UNKNOWN = 'Choose a position from Records Management.';

    /**
     * Takes the structure mutex when the link exists, after any account
     * eligibility locks and BEFORE any profile row lock, the order the
     * Position page's writes (rename cascade, delete refusal) take it too, so
     * a link cannot land on a position being deleted or miss a rename.
     */
    public static function lock(): void
    {
        if (PositionLink::ready()) {
            TransactionMutex::acquire(OrganizationReferences::STRUCTURE_LOCK);
        }
    }

    /** The choice a form value means: null for blank, KEEP, or a position id. */
    public static function choice(mixed $value): ?string
    {
        $text = EmployeeInput::text($value);

        return $text === '' ? null : $text;
    }

    /**
     * The form value for what the record holds now: its linked position, KEEP
     * for a title that is not linked, blank when it has none.
     */
    public static function formValue(mixed $positionId, mixed $position): string
    {
        if (is_string($positionId) && $positionId !== '') {
            return $positionId;
        }

        return is_string($position) && trim($position) !== '' ? self::KEEP : '';
    }

    /**
     * Why `$choice` cannot be saved on `$employee` (null for a hire), or null.
     * Keep-current: KEEP and the record's own linked id always pass — the
     * record already holds them — so only a NEW link is checked against the
     * list. Read-only; apply() re-resolves under the mutex.
     */
    public static function error(?EmployeeProfile $employee, ?string $choice): ?string
    {
        if ($choice === null) {
            return null;
        }

        if ($choice === self::KEEP) {
            return $employee === null ? self::UNKNOWN : null;
        }

        if (! PositionLink::ready()) {
            return EmployeeDetailRows::NOT_READY;
        }

        if ($employee !== null && $choice === $employee->getAttribute('position_id')) {
            return null;
        }

        return app(OrganizationReferences::class)->positionForAssignment($choice) === null ? self::UNKNOWN : null;
    }

    /**
     * Sets the link and the copied name on `$employee` (not yet saved). The
     * caller holds the mutex (lock()) and has run error(); the position is
     * read again here, so one deleted in between refuses instead of linking.
     */
    public static function apply(EmployeeProfile $employee, ?string $choice): void
    {
        if ($choice === self::KEEP) {
            return;
        }

        if ($choice === null) {
            $employee->setAttribute('position', null);

            if (PositionLink::ready()) {
                $employee->setAttribute('position_id', null);
            }

            return;
        }

        if (! PositionLink::ready()) {
            throw new RuntimeException(EmployeeDetailRows::NOT_READY);
        }

        $placement = app(OrganizationReferences::class)->positionForAssignment($choice);

        if ($placement === null) {
            throw new RuntimeException(self::UNKNOWN);
        }

        $employee->setAttribute('position_id', $placement->id);
        $employee->setAttribute('position', $placement->name);
    }
}
