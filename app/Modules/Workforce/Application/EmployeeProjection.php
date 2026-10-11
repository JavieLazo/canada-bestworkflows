<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application;

use App\Models\EmployeeEducation;
use App\Models\EmployeeOtherItem;
use App\Models\EmployeeProfile;
use App\Modules\Workforce\Contracts\Views\EmployeeProfileView;
use App\Platform\ReadModels\Snapshot;
use Illuminate\Support\Collection;

/**
 * The one detached shape of an employee record: the profile's columns, its
 * children (always) and, when asked for, its details row and address rows
 * flattened under their form keys (`umid_no`, `res_street`, `spouse_surname`,
 * `decl_35b` …), so EmployeeFields reads a view and a model alike.
 *
 * A view projected without the details carries `pds_loaded` false and none
 * of those keys; EmployeeFields refuses to read an address or a citizenship
 * from it rather than show the legacy value as if nothing were typed. With
 * the details, a relation that was not loaded — which happens only while the
 * tables do not exist yet — flattens as blank, so the legacy address,
 * citizenship and spouse show as stored. The legacy `address`,
 * `citizenship`, spouse, college and graduate columns stay on the view as
 * stored. A declaration stays as stored (NULL, true or false).
 *
 * With the details the view also carries `education` (the stored schools as
 * plain rows, years as integers) and the three Other info lists `skills`,
 * `distinctions` and `memberships` (lines in order), each empty unless its
 * relation was loaded.
 *
 * Every view also carries where the employee's position sits — department
 * and unit ids and names, and whether the stored title is off the Position
 * list (EmployeePlacements) — beside the stored `position` title.
 */
final class EmployeeProjection
{
    /**
     * `$placement` is the row's entry from EmployeePlacements::of(); the
     * caller reads the whole set in one batch. Without it the view carries
     * the same keys as NULL / false.
     *
     * @param  array<string, mixed>  $placement
     */
    public static function view(EmployeeProfile $row, bool $details, array $placement = []): EmployeeProfileView
    {
        $children = [];
        foreach ($row->children as $child) {
            $children[] = ['name' => $child->name, 'birth_date' => $child->birth_date?->format('Y-m-d')];
        }

        $extras = ['children' => $children, 'pds_loaded' => $details];

        foreach (EmployeePlacements::PLACEMENT_KEYS as $key) {
            $extras[$key] = $placement[$key] ?? ($key === 'position_unlisted' ? false : null);
        }

        if ($details) {
            $pds = $row->relationLoaded('pdsDetail') ? $row->pdsDetail : null;

            foreach (EmployeeFields::allFields() as $field) {
                if (($field['store'] ?? null) === 'pds') {
                    $extras[(string) $field['key']] = $pds?->getAttribute((string) $field['key']);
                }
            }

            $addresses = $row->relationLoaded('addresses') ? $row->addresses->keyBy('type') : collect();

            foreach (EmployeeFields::ADDRESS_PREFIX as $type => $prefix) {
                $address = $addresses->get($type);

                foreach (array_keys(EmployeeFields::ADDRESS_PARTS) as $part) {
                    $extras[$prefix.$part] = $address?->getAttribute($part);
                }
            }

            $extras['education'] = $row->relationLoaded('educations') ? self::educationRows($row->educations) : [];
            $items = $row->relationLoaded('otherItems') ? $row->otherItems->sortBy('position') : collect();

            foreach (EmployeeFields::ITEM_KINDS as $key => $kind) {
                $extras[$key] = array_values($items
                    ->filter(static fn (EmployeeOtherItem $item): bool => $item->kind === $kind)
                    ->map(static fn (EmployeeOtherItem $item): string => $item->text)
                    ->all());
            }
        }

        return Snapshot::of($row, EmployeeProfileView::class, $extras);
    }

    /**
     * Stored schools as plain rows, in the order they were stored, so a
     * reader that wants "the later stored" on a tie can rely on it.
     *
     * @param  Collection<int, EmployeeEducation>  $rows
     * @return list<array<string, mixed>>
     */
    public static function educationRows(Collection $rows): array
    {
        return array_values($rows->sortBy('id')->map(static fn (EmployeeEducation $row): array => [
            'id' => $row->id,
            'level' => $row->level,
            'school' => $row->school,
            'course' => $row->course,
            'attended_from' => $row->attended_from,
            'attended_to' => $row->attended_to,
            'units_earned' => $row->units_earned,
            'year_graduated' => $row->year_graduated,
            'honors' => $row->honors,
        ])->all());
    }
}
