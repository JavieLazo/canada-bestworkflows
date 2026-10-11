<?php

declare(strict_types=1);

namespace App\Livewire\Kit\Support;

use App\Modules\Organization\Contracts\OrganizationDirectory;
use App\Modules\Workforce\Application\EmployeePlacements;
use App\Modules\Workforce\Contracts\OperationalDirectory;
use App\Modules\Workforce\Contracts\Views\EmployeeProfileView;
use App\Support\Access;

/**
 * The department filter that Employee Records ("Filter by department") and
 * the eDTR Daily Time Record ("Department or unit") share: the choices it
 * offers and what each one matches. Presentation only — it narrows rows the
 * owner's query has ALREADY scoped to the actor, and never widens them.
 *
 * The choices: "All departments", "Not placed", then every department in HR
 * order with its units indented beneath it. A department matches the people
 * whose position sits in it OR in one of its units; a unit matches only its
 * own people; Not placed matches people linked to a position placed nowhere.
 * A record with no linked position (a title typed before the Position list,
 * or none) sits in no department, so only "All departments" lists it. An
 * unknown value matches nobody rather than everyone.
 */
final class DepartmentFilter
{
    /** The same values the employee picker's `place` filter takes (OperationalDirectory). */
    public const string ALL = OperationalDirectory::PLACE_ALL;

    /** Positions placed in no department — the Position page's "Not placed". */
    public const string NOT_PLACED = OperationalDirectory::PLACE_NOT_PLACED;

    /** Three no-break spaces: a native select keeps them, so a unit reads indented under its department. */
    private const string INDENT = "\u{00A0}\u{00A0}\u{00A0}";

    /**
     * value => label, in the order the select lists them. Only "All
     * departments" before the structure's migration has run: nobody is
     * linked to a position yet, so "Not placed" would list nobody.
     *
     * @return array<string, string>
     */
    public static function options(Access $access): array
    {
        if (! EmployeePlacements::ready()) {
            return [self::ALL => 'All departments'];
        }

        $options = [self::ALL => 'All departments', self::NOT_PLACED => 'Not placed'];
        foreach (app(OrganizationDirectory::class)->structureOptions($access) as $option) {
            $options[$option->id] = ($option->unit ? self::INDENT : '').$option->name;
        }

        return $options;
    }

    /**
     * A client-sent value the select offers, or "All departments" for one it
     * does not — a filter the page cannot draw must not silently hide rows.
     *
     * @param  array<string, string>  $options  options() for the same actor
     */
    public static function choice(string $value, array $options): string
    {
        return array_key_exists($value, $options) ? $value : self::ALL;
    }

    /** Whether one Employee Records row belongs under the chosen department, unit or Not placed. */
    public static function matches(EmployeeProfileView $employee, string $choice): bool
    {
        if ($choice === self::ALL) {
            return true;
        }

        $linked = is_string($employee->position_id) && $employee->position_id !== '';

        if (! $linked) {
            return false;
        }

        if ($choice === self::NOT_PLACED) {
            return $employee->department_id === null;
        }

        // A department id is never a unit id, so one comparison each covers
        // "the department and its units" and "this unit only".
        return $employee->department_id === $choice || $employee->unit_id === $choice;
    }
}
