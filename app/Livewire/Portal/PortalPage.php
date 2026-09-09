<?php

declare(strict_types=1);

namespace App\Livewire\Portal;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Livewire\Portal\Support\PortalLabels;
use App\Models\EmployeeProfile;
use App\Models\LeaveTravelRequest;
use App\Models\LeaveType;
use App\Models\Notification;
use App\Models\Payslip;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Support\Access;
use App\Support\AuditService;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use RuntimeException;
use Throwable;

/**
 * My Portal — the port of copems/src/app/(protected)/portal/portal-page.tsx:
 * the employee self-service landing. Three tabs: the punch station beside the
 * "My punches" feed, the leave/OB filing form over the caller's own request
 * table, and the caller's posted payslips with the printable detail dialog.
 *
 * Filing is the port of POST /api/leave-requests (route.ts) with the portal's
 * constraint folded in: THE EMPLOYEE IS ALWAYS THE SESSION'S OWN. The portal
 * form has no employee picker, and the route took `employee_id` from the
 * session for everyone below Leave Management anyway — so here the id never
 * comes from the client at all. The LR code is minted server-side inside the
 * same transaction as the overlap check, exactly as the handler did under its
 * advisory lock; MySQL has no transaction-scoped advisory lock, so the scan
 * takes `lockForUpdate()` instead (the PunchStation precedent).
 *
 * Withdrawing is the port of POST /api/leave-requests/[id]/withdraw.
 *
 * EVERY read on this page is scoped: the request table, the payslip list and
 * the punch feed all pass through ScopesToActor AND branch on
 * `hasEmployeeLink()` first — this page is where the employee-less-account
 * leak would show, and the scope must yield empty states, not errors (and not
 * the whole organisation for an all_data holder, whose "mine" is still mine).
 */
#[Layout('layouts.app')]
#[Title('My Portal')]
class PortalPage extends Component
{
    /** Which tab is showing. Plain UI state; anything unknown collapses back. */
    public string $tab = 'punch';

    /* ── the filing form (portal-page.tsx:95-100) ────────────────────────── */

    /** Request type key ('' until picked). Validated against Leave Settings at file(). */
    public string $type = '';

    /** WHOLE_DAY | HALF_DAY — defaults to the first entry, like DAY_TYPES[0]. */
    public string $dayType = 'WHOLE_DAY';

    /** 'Y-m-d' range, both defaulting to today. Plain form state. */
    public string $start = '';

    public string $end = '';

    /** Free text; trimmed to null on save. */
    public string $reason = '';

    /* ── the payslip dialog ──────────────────────────────────────────────── */

    /** The open payslip's id. Id-bearing → #[Locked]; set only by viewPayslip(). */
    #[Locked]
    public ?string $viewingId = null;

    /**
     * Whether the payslip modal is showing. Unlocked on purpose: flipping it
     * reveals nothing — the modal renders only the already-validated id.
     */
    public bool $payslipOpen = false;

    public function mount(): void
    {
        // The ?tab= deep link (portal-page.tsx:105-108): only these two are
        // honoured; anything else stays on the punch station.
        $requested = (string) request()->query('tab', '');

        if (in_array($requested, ['payslips', 'requests'], true)) {
            $this->tab = $requested;
        }

        $today = PortalLabels::todayKey();
        $this->start = $today;
        $this->end = $today;
    }

    /* ── guards on client-writable state ─────────────────────────────────── */

    public function updatedTab(string $value): void
    {
        $this->tab = in_array($value, ['punch', 'requests', 'payslips'], true) ? $value : 'punch';
    }

    public function updatedDayType(string $value): void
    {
        $this->dayType = array_key_exists($value, PortalLabels::DAY_TYPES) ? $value : 'WHOLE_DAY';
    }

    /** Closing the modal (esc, backdrop, close button) forgets the open payslip. */
    public function updatedPayslipOpen(mixed $value): void
    {
        if (! $value) {
            $this->viewingId = null;
        }
    }

    /* ── actions ─────────────────────────────────────────────────────────── */

    /**
     * File leave or official business for the session's own employee — the
     * port of POST /api/leave-requests, self-service branch.
     */
    public function file(): void
    {
        $actor = app(Access::class);

        // Grants are re-read every request; a PORTAL grant revoked mid-session
        // must stop this action, not just the next page load.
        if (! $actor->hasModule(ModuleCode::PORTAL)) {
            Flux::toast(text: 'You do not have access to that.', variant: 'danger');

            return;
        }

        // portal-page.tsx:121-124 and route.ts:83-85 share this wording.
        if (! $actor->hasEmployeeLink()) {
            Flux::toast(text: "Your account isn't linked to an employee record yet.", variant: 'danger');

            return;
        }
        $employeeId = $actor->ownEmployeeId();

        $type = trim($this->type);

        if ($type === '' || mb_strlen($type) > 60) {
            // The submit button is disabled without a type; a forged call gets
            // the schema's refusal rather than a crafted sentence.
            Flux::toast(text: 'Choose a request type from Leave Settings.', variant: 'danger');

            return;
        }

        $dateError = PortalLabels::leaveRequestDateError($this->start, $this->end);

        if ($dateError !== null) {
            Flux::toast(text: $dateError, variant: 'danger');

            return;
        }

        $reason = trim($this->reason);

        if (mb_strlen($reason) > 2000) {
            // parseBody's rendering of the Zod issue (body.ts:41-46).
            Flux::toast(text: 'reason: String must contain at most 2000 character(s)', variant: 'danger');

            return;
        }

        $employee = EmployeeProfile::query()->whereKey($employeeId)->first();

        if ($employee === null || ! $employee->is_active) {
            Flux::toast(text: 'Choose an active employee for the request.', variant: 'danger');

            return;
        }

        // What can be filed is whatever Leave Settings holds, plus official
        // business (route.ts:96-103).
        $supported = [PortalLabels::OFFICIAL_BUSINESS => true];

        foreach (LeaveType::query()->pluck('name') as $name) {
            $supported[PortalLabels::normalizeRequestType((string) $name)] = true;
        }

        if (! isset($supported[$type])) {
            Flux::toast(text: 'Choose a request type from Leave Settings.', variant: 'danger');

            return;
        }

        $dayType = array_key_exists($this->dayType, PortalLabels::DAY_TYPES) ? $this->dayType : 'WHOLE_DAY';
        $start = $this->start;
        $end = $this->end;

        try {
            $created = DB::transaction(function () use ($employeeId, $type, $dayType, $start, $end, $reason): LeaveTravelRequest {
                // The lock covers both overlap detection and code minting
                // (route.ts:105-110). Without it, two simultaneous filings can
                // both pass the read and create an overlapping range with
                // different codes. The reference serialised this with
                // `pg_advisory_xact_lock`; here the scan takes lockForUpdate.
                $rows = LeaveTravelRequest::query()
                    ->lockForUpdate()
                    ->get(['id', 'code', 'employee_id', 'status', 'start_date', 'end_date']);

                foreach ($rows as $row) {
                    if ((string) $row->employee_id !== $employeeId) {
                        continue;
                    }
                    if (! in_array($row->status, ['PENDING', 'APPROVED'], true)) {
                        continue;
                    }
                    if (PortalLabels::leaveRangesOverlap(
                        $row->start_date->format('Y-m-d'),
                        $row->end_date->format('Y-m-d'),
                        $start,
                        $end,
                    )) {
                        throw new RuntimeException(
                            "These dates overlap {$row->code}. Resolve that request before filing another.",
                        );
                    }
                }

                $code = PortalLabels::nextCode('LR', array_values($rows->map(static fn ($r): string => (string) $r->code)->all()));

                return LeaveTravelRequest::query()->create([
                    'code' => $code,
                    // The session's own employee, never the request's.
                    'employee_id' => $employeeId,
                    'request_type' => $type,
                    'day_type' => $dayType,
                    'start_date' => $start,
                    'end_date' => $end,
                    'reason' => $reason !== '' ? $reason : null,
                    'status' => 'PENDING',
                ]);
            });
        } catch (RuntimeException $e) {
            Flux::toast(text: $e->getMessage(), variant: 'danger');

            return;
        }

        AuditService::record(
            $actor,
            AuditEvent::REQUEST_FILED,
            'leave_travel_requests',
            (string) $created->id,
            "{$created->code} filed {$start} \u{2192} {$end}",
        );

        // Whoever holds a role granted Leave Management reviews this
        // (route.ts:135-140). The description resolves through the built-in
        // fallbacks, exactly as `requestDescription(input.request_type)` did.
        $this->notifyLeaveReviewers($actor, [
            'kind' => 'LEAVE_FILED',
            'title' => "{$created->code} filed by {$actor->fullName}",
            'body' => PortalLabels::requestDescription($type).' · '.$start,
            'href' => '/leaves',
        ]);

        // The form resets to its opening state (portal-page.tsx:185-189).
        $today = PortalLabels::todayKey();
        $this->reason = '';
        $this->type = '';
        $this->dayType = 'WHOLE_DAY';
        $this->start = $today;
        $this->end = $today;

        Flux::toast(text: 'Request submitted for approval.', variant: 'success');
    }

    /**
     * Withdraw the caller's own pending request — the port of
     * POST /api/leave-requests/[id]/withdraw.
     */
    public function withdraw(string $id): void
    {
        $actor = app(Access::class);

        if (! $actor->hasModule(ModuleCode::PORTAL)) {
            Flux::toast(text: 'You do not have access to that.', variant: 'danger');

            return;
        }

        $request = LeaveTravelRequest::query()->whereKey($id)->first();

        if ($request === null) {
            Flux::toast(text: 'That request no longer exists.', variant: 'danger');

            return;
        }

        // Withdrawing is for yourself, whoever you are (withdraw/route.ts:18).
        if (! $actor->hasEmployeeLink() || $request->employee_id !== $actor->ownEmployeeId()) {
            Flux::toast(text: 'You do not have access to that.', variant: 'danger');

            return;
        }

        if ($request->status !== 'PENDING') {
            Flux::toast(text: 'Only a pending request can be withdrawn.', variant: 'danger');

            return;
        }

        // `status = 'PENDING'` in the WHERE is the real guard
        // (withdraw/route.ts:23-27): a reviewer racing this withdrawal wins or
        // loses atomically, never both.
        $won = LeaveTravelRequest::query()
            ->whereKey($request->id)
            ->where('employee_id', '=', $actor->ownEmployeeId())
            ->where('status', 'PENDING')
            ->update([
                'status' => 'WITHDRAWN',
                'is_paid' => null,
                'reviewed_at' => null,
                'reviewed_by' => null,
            ]);

        if ($won === 0) {
            Flux::toast(text: 'That request changed before it was withdrawn.', variant: 'danger');

            return;
        }

        AuditService::record(
            $actor,
            AuditEvent::REQUEST_WITHDRAWN,
            'leave_travel_requests',
            (string) $request->id,
            "{$request->code} withdrawn by {$actor->fullName}",
        );

        $this->notifyLeaveReviewers($actor, [
            'kind' => 'LEAVE_WITHDRAWN',
            'title' => "{$request->code} withdrawn",
            'body' => "{$actor->fullName} withdrew the pending request.",
            'href' => '/leaves',
        ]);

        Flux::toast(text: 'Request withdrawn.', variant: 'success');
    }

    /**
     * Open one of MY payslips. The id is validated against the caller's own
     * scoped list — the read where getting the scope wrong exposes every
     * salary to everyone (api/payroll/payslips/route.ts).
     */
    public function viewPayslip(string $id): void
    {
        $actor = app(Access::class);

        if (! $actor->hasEmployeeLink()) {
            return;
        }

        $visible = Payslip::query()
            ->toEmployee($actor, 'employee_id', ModuleCode::PAYROLL)
            ->where('employee_id', '=', $actor->ownEmployeeId())
            ->whereKey($id)
            ->exists();

        if (! $visible) {
            return;
        }

        $this->viewingId = $id;
        $this->payslipOpen = true;
    }

    public function render(): View
    {
        $actor = app(Access::class);

        $employee = $actor->hasEmployeeLink()
            ? EmployeeProfile::query()->whereKey($actor->ownEmployeeId())->first()
            : null;

        /** What can be filed is whatever Records Management → Leave Settings holds. */
        $leaveTypeNames = LeaveType::query()->orderBy('name')->pluck('name')->all();

        // Business travel is filed as official business, not as a leave
        // (portal-page.tsx:70-75).
        $requestOptions = [];

        foreach ($leaveTypeNames as $name) {
            if (preg_match('/business|travel/i', $name) === 1) {
                continue;
            }
            $requestOptions[] = [
                'value' => PortalLabels::normalizeRequestType($name),
                'label' => $name,
            ];
        }
        $requestOptions[] = ['value' => PortalLabels::OFFICIAL_BUSINESS, 'label' => 'Official Business'];

        // MY requests and MY payslips: the scope narrows for the un-widened,
        // and the explicit own-row filter keeps an all_data holder's "mine"
        // mine (`requests.filter(r => r.employee_id === employeeId)`,
        // portal-page.tsx:117). The employee-less branch never touches
        // ownEmployeeId() — it renders the empty states.
        if ($actor->hasEmployeeLink()) {
            $mine = LeaveTravelRequest::query()
                ->toEmployee($actor, 'employee_id', ModuleCode::LEAVES)
                ->where('employee_id', '=', $actor->ownEmployeeId())
                ->orderByDesc('created_at')
                ->get();

            $payslips = Payslip::query()
                ->toEmployee($actor, 'employee_id', ModuleCode::PAYROLL)
                ->where('employee_id', '=', $actor->ownEmployeeId())
                ->orderByDesc('period_start')
                ->get();
        } else {
            $mine = new EloquentCollection;
            $payslips = new EloquentCollection;
        }

        $dateError = PortalLabels::leaveRequestDateError($this->start, $this->end);

        // The open payslip comes from the OWN list, never a fresh unscoped read.
        $viewing = $this->viewingId !== null ? $payslips->firstWhere('id', $this->viewingId) : null;

        return view('livewire.portal.portal-page', [
            'actor' => $actor,
            'employee' => $employee,
            'hasEmployee' => $actor->hasEmployeeLink(),
            'ownEmployeeId' => $actor->hasEmployeeLink() ? $actor->ownEmployeeId() : null,
            'leaveTypeNames' => $leaveTypeNames,
            'requestOptions' => $requestOptions,
            'mine' => $mine,
            'payslips' => $payslips,
            'dateError' => $dateError,
            'submitDisabled' => ! $actor->hasEmployeeLink() || trim($this->type) === '' || $dateError !== null,
            'viewing' => $viewing,
            'earnings' => $viewing !== null ? $this->earningLines($viewing) : [],
            'deductions' => $viewing !== null ? $this->deductionLines($viewing) : [],
        ]);
    }

    /**
     * The payslip's earnings column (PayslipDetail.tsx:26-45). Overtime nobody
     * acknowledged is left off entirely rather than listed as money paid, and
     * zero lines are filtered.
     *
     * @return list<array{label: string, value: float, hint: ?string}>
     */
    private function earningLines(Payslip $p): array
    {
        $lines = [
            ['label' => 'Work days pay', 'value' => $p->work_days_pay, 'hint' => null],
            ['label' => 'Holiday pay', 'value' => $p->holiday_pay, 'hint' => null],
            ['label' => 'Rest day pay', 'value' => $p->rest_day_pay, 'hint' => null],
            ['label' => 'Allowance', 'value' => $p->allowances, 'hint' => null],
        ];

        if ($p->overtime_acknowledged) {
            $lines[] = [
                'label' => 'Overtime',
                'value' => $p->overtime_pay,
                'hint' => number_format($p->overtime_hours, 2, '.', '').' h',
            ];
        }

        $lines[] = ['label' => 'Night differential', 'value' => $p->night_differential, 'hint' => null];
        $lines[] = ['label' => 'Leave', 'value' => $p->leave_pay, 'hint' => null];
        $lines[] = ['label' => 'Others', 'value' => $p->other_earnings, 'hint' => null];
        $lines[] = ['label' => 'Bonus', 'value' => $p->bonus, 'hint' => null];

        return array_values(array_filter($lines, static fn (array $l): bool => $l['value'] !== 0.0));
    }

    /**
     * The payslip's deductions column (PayslipDetail.tsx:47-63).
     *
     * @return list<array{label: string, value: float, hint: ?string}>
     */
    private function deductionLines(Payslip $p): array
    {
        $lines = [
            ['label' => 'Undertime', 'value' => $p->undertime_deduction, 'hint' => $p->undertime_minutes.' min'],
            ['label' => 'Late', 'value' => $p->late_deduction, 'hint' => $p->late_minutes.' min'],
            ['label' => 'SSS', 'value' => $p->sss, 'hint' => null],
            ['label' => 'PhilHealth', 'value' => $p->philhealth, 'hint' => null],
            ['label' => 'Pag-IBIG', 'value' => $p->pagibig, 'hint' => null],
            ['label' => 'Withholding tax', 'value' => $p->withholding_tax, 'hint' => null],
            ['label' => 'Others', 'value' => $p->other_deductions, 'hint' => null],
        ];

        return array_values(array_filter($lines, static fn (array $l): bool => $l['value'] !== 0.0));
    }

    /**
     * Port of `notifyModuleHolders("LEAVES", …)` (src/lib/api/notify.ts:63-83):
     * tells everyone whose ACTIVE account holds a role with `all_data` AND a
     * Leave Management grant — the people who can actually review — except the
     * actor, whose own filing is not news to them. Fire-and-forget: the filing
     * really happened, and failing it because the bell did not ring would be
     * the worse lie.
     *
     * @param  array{kind: string, title: string, body: ?string, href: string}  $entry
     */
    private function notifyLeaveReviewers(Access $actor, array $entry): void
    {
        try {
            $reviewerRoleIds = Role::query()
                ->where('all_data', true)
                ->whereIn(
                    'id',
                    RoleModuleGrant::query()->where('module', ModuleCode::LEAVES)->pluck('role_id'),
                )
                ->pluck('id');

            $accountIds = User::query()
                ->where('is_active', true)
                ->whereIn('role_id', $reviewerRoleIds)
                ->when(
                    $actor->userId !== null,
                    static fn ($q) => $q->where('id', '!=', (int) $actor->userId),
                )
                ->pluck('id');

            foreach ($accountIds as $accountId) {
                $notification = new Notification;
                $notification->fill([
                    'kind' => $entry['kind'],
                    'title' => $entry['title'],
                    'body' => $entry['body'],
                    'href' => $entry['href'],
                ]);
                // The addressee is server-assigned, never mass-assigned.
                $notification->user_id = (int) $accountId;
                $notification->save();
            }
        } catch (Throwable $e) {
            Log::error('[notify] fan-out failed', ['error' => $e->getMessage()]);
        }
    }
}
