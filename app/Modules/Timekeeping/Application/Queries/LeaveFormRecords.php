<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application\Queries;

use App\Domain\Enums\ModuleCode;
use App\Models\LeaveTravelRequest;
use App\Modules\Timekeeping\Application\LeaveFormFreeze;
use App\Modules\Timekeeping\Contracts\Views\LeaveSignatoryView;
use App\Modules\Timekeeping\Contracts\Views\LeaveTravelRequestView;
use App\Modules\Timekeeping\Domain\Absence\LeaveSignatorySlot;
use App\Support\Access;

/**
 * What an approved Application for Leave stated when it was approved
 * (LeaveFormFreeze), for the employee's own request or any request to an
 * all-data LEAVES holder, the reading LeavePayRecords::filed allows. Null for
 * anyone else and for a request with nothing frozen, which then reads as the
 * records stand.
 *
 * The rates are in the answer but are not the actor's to see by default: the
 * screen shows them only under the employee record's own rule
 * (ApplicationFormData).
 */
final class LeaveFormRecords
{
    /**
     * @return array{needs: float|null, with_pay: float|null, without_pay: float|null, certification: list<array{name: string, unit: string, earned: float, less: float, balance: float}>, types: list<array{value: string, label: string}>, applicant: array{office: string, last: string, first: string, middle: string, position: string, monthly_rate: float, daily_rate: float, head: array{name: string, designation: string}|null}, officers: array<string, LeaveSignatoryView>}|null
     */
    public function approved(Access $actor, LeaveTravelRequestView $request): ?array
    {
        $own = $actor->hasEmployeeLink() && $request->employee_id === $actor->ownEmployeeId();
        if ((! $own && ! $actor->mayReadAllData(ModuleCode::LEAVES)) || ! LeaveFormFreeze::ready()) {
            return null;
        }
        $form = LeaveTravelRequest::query()->whereKey($request->id)->first(['id', 'approved_form'])?->approved_form;
        if ($form === null) {
            return null;
        }
        $applicant = self::map($form['applicant'] ?? null);
        $head = is_array($applicant['head'] ?? null) ? self::map($applicant['head']) : null;

        return [
            'needs' => self::number($form['needs'] ?? null),
            'with_pay' => self::number($form['with_pay'] ?? null),
            'without_pay' => self::number($form['without_pay'] ?? null),
            'certification' => array_map(static fn (array $column): array => [
                'name' => self::text($column['name'] ?? null),
                'unit' => self::text($column['unit'] ?? null),
                'earned' => self::number($column['earned'] ?? null) ?? 0.0,
                'less' => self::number($column['less'] ?? null) ?? 0.0,
                'balance' => self::number($column['balance'] ?? null) ?? 0.0,
            ], self::rows($form['certification'] ?? null)),
            'types' => array_map(static fn (array $type): array => ['value' => self::text($type['value'] ?? null), 'label' => self::text($type['label'] ?? null)], self::rows($form['types'] ?? null)),
            'applicant' => [
                'office' => self::text($applicant['office'] ?? null),
                'last' => self::text($applicant['last'] ?? null),
                'first' => self::text($applicant['first'] ?? null),
                'middle' => self::text($applicant['middle'] ?? null),
                'position' => self::text($applicant['position'] ?? null),
                'monthly_rate' => self::number($applicant['monthly_rate'] ?? null) ?? 0.0,
                'daily_rate' => self::number($applicant['daily_rate'] ?? null) ?? 0.0,
                'head' => $head === null ? null : ['name' => self::text($head['name'] ?? null), 'designation' => self::text($head['designation'] ?? null)],
            ],
            'officers' => self::officers(self::map($form['officers'] ?? null)),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $stored
     * @return array<string, LeaveSignatoryView>
     */
    private static function officers(array $stored): array
    {
        $views = [];
        foreach (LeaveSignatorySlot::all() as $slot) {
            $officer = self::map($stored[$slot] ?? null);
            $views[$slot] = new LeaveSignatoryView($slot, LeaveSignatorySlot::label($slot), self::text($officer['name'] ?? null), self::text($officer['designation'] ?? null));
        }

        return $views;
    }

    /** @return array<array-key, mixed> */
    private static function map(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /** @return list<array<array-key, mixed>> */
    private static function rows(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private static function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
