<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application;

use App\Models\LeaveTravelRequest;
use App\Modules\Timekeeping\Application\Queries\LeavePayRecords;
use App\Modules\Timekeeping\Application\Queries\LeaveRuleRecords;
use App\Modules\Timekeeping\Application\Queries\LeaveSignatoryRecords;
use App\Modules\Timekeeping\Application\Queries\LeaveTypeCreditRecords;
use App\Modules\Timekeeping\Contracts\Views\LeaveTravelRequestView;
use App\Modules\Timekeeping\Domain\Absence\AbsencePolicy;
use App\Modules\Workforce\Contracts\EmployeeLeaveFacts;
use App\Platform\ReadModels\Snapshot;
use App\Support\Access;
use App\Support\HrisFormat;
use Illuminate\Support\Facades\Schema;

/**
 * AN APPROVED APPLICATION FOR LEAVE IS A RECORD: this stores what its form
 * states at that moment, on the request (`approved_form`), for good. The
 * applicant's lines, the head of office, the signatories, the leave types
 * listed and the certification of credits are read from live data until then;
 * after it, a change to any of them never reaches this leave.
 *
 * Called inside the approving transaction, after the status changes, so the
 * certification counts this approval and a failure here undoes it. The
 * salary is stored whoever approves: Queries\LeaveFormRecords' reader and the
 * screen decide who may read it. An existing form is never written over.
 */
final class LeaveFormFreeze
{
    public static function ready(): bool
    {
        return once(static fn (): bool => Schema::hasColumn('leave_travel_requests', 'approved_form'));
    }

    public function capture(Access $actor, string $requestId): void
    {
        if (! self::ready()) {
            return;
        }
        $row = LeaveTravelRequest::query()->whereKey($requestId)->first();
        if ($row === null || $row->approved_form !== null) {
            return;
        }
        $standing = app(LeavePayRecords::class)->filed($actor, Snapshot::of($row, LeaveTravelRequestView::class));
        $officers = [];
        foreach (app(LeaveSignatoryRecords::class)->all($actor) as $slot => $signatory) {
            $officers[$slot] = ['name' => $signatory->name, 'designation' => $signatory->designation];
        }

        $form = [
            ...$standing,
            'types' => $this->types($actor, $row),
            'applicant' => $this->applicant($row->employee_id),
            'officers' => $officers,
        ];
        // Without touching `updated_at`, so freezing an older leave does not read as an edit.
        LeaveTravelRequest::query()->whereKey($requestId)->whereNull('approved_form')->toBase()
            ->update(['approved_form' => json_encode($form, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)]);
    }

    /**
     * The leave types 6.A lists for this employee, always including the one
     * filed, which the employee may no longer be allowed.
     *
     * @return list<array{value: string, label: string}>
     */
    private function types(Access $actor, LeaveTravelRequest $row): array
    {
        $names = array_map(static fn (array $entry): string => $entry['name'], LeaveTypeCreditRecords::rules());
        $ineligible = app(LeaveRuleRecords::class)->ineligible($actor, [$row->employee_id])[$row->employee_id] ?? [];
        $types = [];
        $listed = false;
        foreach ($names as $token => $name) {
            if (preg_match('/business|travel/i', $name) === 1) {
                continue;
            }
            $matches = HrisFormat::requestTypeMatches((string) $token, $row->request_type);
            if ($matches || ! isset($ineligible[$token])) {
                $types[] = ['value' => (string) $token, 'label' => $name];
                $listed = $listed || $matches;
            }
        }
        if (! $listed && $row->request_type !== AbsencePolicy::OFFICIAL_BUSINESS) {
            $types[] = ['value' => $row->request_type, 'label' => HrisFormat::requestDescription($row->request_type, array_values($names))];
        }

        return $types;
    }

    /** @return array{office: string, last: string, first: string, middle: string, position: string, monthly_rate: float, daily_rate: float, head: array{name: string, designation: string}|null} */
    private function applicant(string $employeeId): array
    {
        $person = app(EmployeeLeaveFacts::class)->applicant($employeeId);

        return [
            'office' => implode(' · ', array_filter([(string) ($person['department_name'] ?? ''), (string) ($person['unit_name'] ?? '')])),
            'last' => trim(($person['last_name'] ?? '').' '.($person['suffix'] ?? '')),
            'first' => $person['first_name'] ?? '',
            'middle' => (string) ($person['middle_name'] ?? ''),
            'position' => (string) ($person['position'] ?? ''),
            'monthly_rate' => $person['monthly_rate'] ?? 0.0,
            'daily_rate' => $person['daily_rate'] ?? 0.0,
            'head' => $person['head'] ?? null,
        ];
    }
}
