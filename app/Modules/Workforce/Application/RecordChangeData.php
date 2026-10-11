<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application;

use App\Models\EmployeeProfile;
use App\Modules\Organization\Contracts\OrganizationReferences;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/** Canonical request comparisons and frozen human-readable evidence share the record's field registry. */
final class RecordChangeData
{
    public const STALE = 'The employee record changed after this request was submitted. Withdraw it and submit a new request using the current record.';

    public static function load(EmployeeProfile $employee): void
    {
        if (EmployeeDetailRows::tablesReady()) {
            $employee->load(['pdsDetail', 'addresses', 'children']);
        }
        if (PdsLists::ready('other')) {
            $employee->load('otherItems');
        }
    }

    /** @return array<string, mixed> */
    public static function sectionValues(EmployeeProfile $employee, string $section): array
    {
        $spec = EmployeeFields::section($section);
        if ($spec === null) {
            throw new RuntimeException('Choose an employee record section.');
        }
        $values = [];
        foreach ($spec['fields'] as $field) {
            if (isset($field['derived']) || ! empty($field['auto'])) {
                continue;
            }
            $key = (string) ($field['choice'] ?? $field['key']);
            $value = match (true) {
                isset($field['choice']) => EmployeePosition::formValue($employee->position_id, $employee->position),
                EmployeeFields::isChildren($field) => EmployeeFields::childRows($employee),
                EmployeeFields::isLines($field) => EmployeeFields::itemLines($employee, (string) $field['key']),
                default => EmployeeFields::storedValue($employee, $field),
            };
            $values[$key] = self::canonical($value);
        }

        return $values;
    }

    /** @return array<string, mixed> */
    public static function rowValues(Model $row, string $list): array
    {
        $values = [];
        foreach (PdsLists::spec($list)['fields'] as $field) {
            $values[$field['key']] = self::canonical($row->getAttribute($field['key']));
        }

        return $values;
    }

    public static function canonical(mixed $value): mixed
    {
        if ($value instanceof CarbonImmutable) {
            return $value->format('Y-m-d');
        }
        if (is_array($value)) {
            return array_map(self::canonical(...), $value);
        }
        if (is_float($value)) {
            return number_format($value, 2, '.', '');
        }
        if (is_string($value)) {
            return trim($value) === '' ? null : trim($value);
        }

        return $value;
    }

    /** @param array<string, mixed> $before
     * @param  array<string, mixed>  $after
     * @return array{array<string, mixed>, array<string, mixed>}
     */
    public static function delta(array $before, array $after): array
    {
        $old = $new = [];
        foreach ($after as $key => $value) {
            $value = self::canonical($value);
            $prior = self::canonical($before[$key] ?? null);
            if ($value !== $prior) {
                $old[$key] = $prior;
                $new[$key] = $value;
            }
        }

        return [$old, $new];
    }

    /** @param array<string, mixed> $baseline
     * @param  array<string, mixed>  $current
     */
    public static function assertCurrent(array $baseline, array $current): void
    {
        foreach ($baseline as $key => $value) {
            if (self::canonical($value) !== self::canonical($current[$key] ?? null)) {
                throw new RuntimeException(self::STALE);
            }
        }
    }

    /** @param array<string, mixed> $before
     * @param  array<string, mixed>  $after
     * @return list<array{field:string,label:string,before:?string,after:?string,before_known:bool,after_known:bool}>
     */
    public static function changes(EmployeeProfile $employee, string $section, array $before, array $after, bool $pds = false): array
    {
        $fields = $pds ? PdsLists::spec($section)['fields'] : (EmployeeFields::section($section)['fields'] ?? []);
        $specs = [];
        foreach ($fields as $field) {
            $specs[(string) ($field['choice'] ?? $field['key'])] = $field;
        }
        $changes = [];
        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $key) {
            $field = $specs[$key] ?? ['key' => $key, 'label' => ucfirst(str_replace('_', ' ', $key))];
            $changes[] = [
                'field' => ($pds ? $section.'.' : '').$key,
                'label' => ($pds ? PdsLists::spec($section)['title'].' — ' : '').$field['label'],
                'before' => self::display($employee, $key, $before[$key] ?? null, $field),
                'after' => self::display($employee, $key, $after[$key] ?? null, $field),
                'before_known' => true, 'after_known' => true,
            ];
        }

        return $changes;
    }

    /** @param array<string, mixed> $field */
    private static function display(EmployeeProfile $employee, string $key, mixed $value, array $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($key === EmployeePosition::FORM_KEY) {
            return $value === EmployeePosition::KEEP ? $employee->position : app(OrganizationReferences::class)->positionForAssignment((string) $value)?->name;
        }
        if ($key === 'work_site_id') {
            return app(OrganizationReferences::class)->siteName((string) $value);
        }
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }
        if (is_array($value)) {
            $lines = [];
            foreach ($value as $item) {
                $lines[] = is_array($item) ? implode(' · ', array_map(static fn (mixed $part): string => (string) ($part ?? ''), $item)) : (string) $item;
            }

            return $lines === [] ? null : implode("\n", $lines);
        }
        foreach ((array) ($field['options'] ?? []) as $option) {
            if ((string) $option['value'] === (string) $value) {
                return (string) $option['label'];
            }
        }
        if (! empty($field['metres']) && is_numeric($value)) {
            return EmployeeFields::metresText((float) $value).' m';
        }

        return (string) $value;
    }
}
