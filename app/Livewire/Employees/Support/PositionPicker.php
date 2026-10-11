<?php

declare(strict_types=1);

namespace App\Livewire\Employees\Support;

use App\Modules\Organization\Contracts\OrganizationDirectory;
use App\Modules\Organization\Contracts\Views\PositionPlacementView;
use App\Modules\Organization\Contracts\Views\StructureOptionView;
use App\Modules\Workforce\Application\EmployeePlacements;
use App\Modules\Workforce\Application\EmployeePosition;
use App\Modules\Workforce\Contracts\Views\EmployeeProfileView;
use App\Support\Access;

/**
 * The Department, Unit and Position selects of Employee Details and of the
 * hire wizard's Employee Details step. Presentation only: Department
 * (`form.department_id`) and Unit (`form.unit_id`) are draft state that
 * narrow the Position list and are never sent anywhere — the record stores
 * the chosen position (`form.position_id`) alone, and EmployeePosition
 * re-reads where it sits on save. A department or unit typed in by hand
 * therefore only changes which titles the page lists.
 */
final class PositionPicker
{
    /** The Department select's "Not placed" choice: positions placed nowhere. */
    public const string NOT_PLACED = 'NONE';

    /**
     * The drafts for a record: the department and unit of its linked
     * position ("Not placed" for one placed nowhere), blank when it has none.
     *
     * @return array{department_id: string, unit_id: string}
     */
    public static function drafts(EmployeeProfileView $employee): array
    {
        if (! is_string($employee->position_id) || $employee->position_id === '') {
            return ['department_id' => '', 'unit_id' => ''];
        }

        return [
            'department_id' => $employee->department_id ?? self::NOT_PLACED,
            'unit_id' => $employee->unit_id ?? '',
        ];
    }

    /** @return array{department_id: string, unit_id: string} */
    public static function blankDrafts(): array
    {
        return ['department_id' => '', 'unit_id' => ''];
    }

    /**
     * The three selects' choices for the form as it stands: the departments,
     * the chosen department's units, and the positions placed exactly where
     * the drafts point (the department itself, one of its units, or nowhere
     * for Not placed). Empty before the structure's migration has run.
     *
     * @param  array<string, mixed>  $form
     * @return array{departments: list<array{id: string, name: string}>, units: list<array{id: string, name: string}>, positions: list<array{id: string, name: string}>, department: string, unit: string}
     */
    public static function options(Access $access, array $form): array
    {
        [$department, $unit] = self::place($form);

        if (! EmployeePlacements::ready()) {
            return ['departments' => [], 'units' => [], 'positions' => [], 'department' => $department, 'unit' => $unit];
        }

        $directory = app(OrganizationDirectory::class);
        $departments = [];
        $units = [];

        foreach ($directory->structureOptions($access) as $option) {
            /** @var StructureOptionView $option */
            if (! $option->unit) {
                $departments[] = ['id' => $option->id, 'name' => $option->name];
            } elseif ($option->department_id === $department) {
                $units[] = ['id' => $option->id, 'name' => $option->name];
            }
        }

        if ($department !== '' && $department !== self::NOT_PLACED && ! in_array($department, array_column($departments, 'id'), true)) {
            $department = '';
        }

        if (! in_array($unit, array_column($units, 'id'), true)) {
            $unit = '';
        }

        $node = match (true) {
            $department === '' => false,
            $department === self::NOT_PLACED => null,
            $unit !== '' => $unit,
            default => $department,
        };

        $positions = [];

        if ($node !== false) {
            foreach ($directory->positionChoices($access) as $position) {
                /** @var PositionPlacementView $position */
                if ($position->org_node_id === $node) {
                    $positions[] = ['id' => $position->id, 'name' => $position->name];
                }
            }
        }

        return ['departments' => $departments, 'units' => $department === '' || $department === self::NOT_PLACED ? [] : $units, 'positions' => $positions, 'department' => $department, 'unit' => $unit];
    }

    /**
     * After Department or Unit changes: a unit outside the chosen department
     * is dropped, and so is a position the new choice no longer lists. KEEP
     * stays chosen until a listed position replaces it, so choosing a
     * department alone never clears a title that is not on the list.
     *
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     */
    public static function narrow(Access $access, array $form): array
    {
        $options = self::options($access, $form);
        $form['department_id'] = $options['department'];
        $form['unit_id'] = $options['unit'];
        $chosen = is_string($form[EmployeePosition::FORM_KEY] ?? null) ? $form[EmployeePosition::FORM_KEY] : '';

        if ($chosen !== EmployeePosition::KEEP && ! in_array($chosen, array_column($options['positions'], 'id'), true)) {
            $form[EmployeePosition::FORM_KEY] = '';
        }

        return $form;
    }

    /**
     * @param  array<string, mixed>  $form
     * @return array{0: string, 1: string}
     */
    private static function place(array $form): array
    {
        $department = $form['department_id'] ?? '';
        $unit = $form['unit_id'] ?? '';

        return [is_string($department) ? $department : '', is_string($unit) ? $unit : ''];
    }
}
