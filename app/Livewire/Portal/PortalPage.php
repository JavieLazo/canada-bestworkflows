<?php

declare(strict_types=1);

namespace App\Livewire\Portal;

use App\Livewire\Payroll\Support\PayslipDocument;
use App\Livewire\Portal\Support\PortalLabels;
use App\Modules\Payroll\Application\Queries\PayslipRecords;
use App\Modules\Payroll\Contracts\Views\DeductionColumnView;
use App\Modules\Payroll\Contracts\Views\PayslipDeductionView;
use App\Modules\Payroll\Contracts\Views\PayslipIncentiveView;
use App\Modules\Payroll\Contracts\Views\PayslipView as Payslip;
use App\Modules\Payroll\Domain\DeductionCalculation;
use App\Modules\Timekeeping\Application\Commands\FileAbsence;
use App\Modules\Timekeeping\Application\Commands\RequestLeaveCashOut;
use App\Modules\Timekeeping\Application\Commands\WithdrawAbsence;
use App\Modules\Timekeeping\Application\Commands\WithdrawLeaveCashOut;
use App\Modules\Timekeeping\Application\LeaveBalances;
use App\Modules\Timekeeping\Application\LeaveHalves;
use App\Modules\Timekeeping\Application\Queries\AbsenceRecords;
use App\Modules\Timekeeping\Application\Queries\LeaveCashOutRecords;
use App\Modules\Timekeeping\Application\Queries\LeaveCreditRecords;
use App\Modules\Timekeeping\Application\Queries\LeaveTypeCreditRecords;
use App\Modules\Timekeeping\Application\Queries\ReferenceData;
use App\Modules\Timekeeping\Contracts\Views\LeaveBalanceView;
use App\Modules\Timekeeping\Domain\Absence\LeaveCreditPolicy;
use App\Modules\Workforce\Application\Queries\EmployeeRecords;
use App\Support\Access;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use RuntimeException;

/**
 * My Portal — the port of copems/src/app/(protected)/portal/portal-page.tsx:
 * the employee self-service landing. Its tabs hold the punch station and
 * "My punches" feed, read-only own attendance, leave/OB filing and requests,
 * posted payslips with their printable detail, and the caller's issued items.
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
 * Filing and withdrawal delegate to Timekeeping operations, which own their
 * authorization, validation, transactions, audit, and notifications.
 *
 * EVERY read on this page is scoped: the request table, the payslip list and
 * the punch feed all pass through ScopesToActor AND branch on
 * `hasEmployeeLink()` first — this page is where the employee-less-account
 * leak would show, and the scope must yield empty states, not errors (and not
 * the whole organisation for an all_data holder, whose "mine" is still mine).
 *
 * The Leave & travel tab also shows MY leave credits for the current year
 * (LeaveCreditRecords::own, pinned the same way) beside the filing form on
 * wider screens, and a hint saying what the draft would cost against them.
 * Both are read only while that tab shows — the punch station never touches
 * the credits table. Filing requires enough credits for the chosen type and
 * start year; the command re-checks when Submit is clicked.
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

    /** Which half is off on a half day: AM, PM or '' for either (divergence 21). Plain form state. */
    public string $half = '';

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

    /* ── the leave credit cash-out dialog ───────────────────────────────── */

    /** Whether the cash-out dialog shows. Plain UI state. */
    public bool $cashOutOpen = false;

    /** The leave type token picked ('' until picked); RequestLeaveCashOut validates it against Leave Credits settings. */
    public string $cashOutType = '';

    /** The days typed, as text; the command validates them. */
    public string $cashOutDays = '';

    public string $cashOutNote = '';

    public function mount(): void
    {
        // Supported ?tab= deep links open their self-service view; anything
        // else stays on the punch station (portal-page.tsx:105-108).
        $requested = (string) request()->query('tab', '');

        if (in_array($requested, ['attendance', 'payslips', 'requests', 'inventory'], true)) {
            $this->tab = $requested;
        }

        $today = PortalLabels::todayKey();
        $this->start = $today;
        $this->end = $today;
    }

    /* ── guards on client-writable state ─────────────────────────────────── */

    public function updatedTab(string $value): void
    {
        $this->tab = in_array($value, ['punch', 'attendance', 'requests', 'payslips', 'inventory'], true) ? $value : 'punch';
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
        try {
            app(FileAbsence::class)->handle($actor, $this->type, $this->dayType, $this->start, $this->end, $this->reason, $this->half === '' ? null : $this->half);
        } catch (QueryException) {
            Flux::toast(text: 'The request could not be saved. Please try again.', variant: 'danger');

            return;
        } catch (RuntimeException $e) {
            Flux::toast(text: $e->getMessage(), variant: 'danger');

            return;
        }
        $this->reason = '';
        $this->type = '';
        $this->dayType = 'WHOLE_DAY';
        $this->half = '';
        $this->start = PortalLabels::todayKey();
        $this->end = $this->start;
        Flux::toast(text: 'Request submitted for approval.', variant: 'success');
    }

    /**
     * Withdraw the caller's own pending request — the port of
     * POST /api/leave-requests/[id]/withdraw.
     */
    public function withdraw(string $id): void
    {
        $actor = app(Access::class);
        try {
            app(WithdrawAbsence::class)->handle($actor, $id);
        } catch (QueryException) {
            Flux::toast(text: 'The request could not be withdrawn. Please try again.', variant: 'danger');

            return;
        } catch (RuntimeException $e) {
            Flux::toast(text: $e->getMessage(), variant: 'danger');

            return;
        }
        Flux::toast(text: 'Request withdrawn.', variant: 'success');
    }

    /**
     * Ask to turn part of MY leave credits into pay (RequestLeaveCashOut):
     * the command pins the request to the session's own employee and
     * re-checks the type, the days and the balance for itself.
     */
    public function requestCashOut(): void
    {
        $actor = app(Access::class);
        try {
            $message = app(RequestLeaveCashOut::class)->handle($actor, $this->cashOutType, $this->cashOutDays, $this->cashOutNote);
        } catch (QueryException) {
            Flux::toast(text: 'The request could not be saved. Please try again.', variant: 'danger');

            return;
        } catch (RuntimeException $e) {
            Flux::toast(text: $e->getMessage(), variant: 'danger');

            return;
        }
        $this->cashOutOpen = false;
        $this->cashOutType = '';
        $this->cashOutDays = '';
        $this->cashOutNote = '';
        Flux::toast(text: $message, variant: 'success');
    }

    /** Take back MY pending cash-out (WithdrawLeaveCashOut). */
    public function withdrawCashOut(string $id): void
    {
        $actor = app(Access::class);
        try {
            $message = app(WithdrawLeaveCashOut::class)->handle($actor, $id);
        } catch (QueryException) {
            Flux::toast(text: 'The request could not be withdrawn. Please try again.', variant: 'danger');

            return;
        } catch (RuntimeException $e) {
            Flux::toast(text: $e->getMessage(), variant: 'danger');

            return;
        }
        Flux::toast(text: $message, variant: 'success');
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

        $visible = app(PayslipRecords::class)->ownVisible($actor, $id);

        if (! $visible) {
            return;
        }

        $this->viewingId = $id;
        $this->payslipOpen = true;
    }

    public function render(): View
    {
        $actor = app(Access::class);

        $employee = app(EmployeeRecords::class)->own($actor);

        /** What can be filed is whatever Records Management → Leave Settings holds. */
        $leaveTypeNames = app(ReferenceData::class)->leaveTypes($actor)->pluck('name')->all();

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
        $mine = app(AbsenceRecords::class)->list($actor, own: true);
        $payslips = app(PayslipRecords::class)->own($actor);

        $dateError = PortalLabels::leaveRequestDateError($this->start, $this->end);

        // MY credits for the current Manila year, read only while the Leave &
        // travel tab shows (own-pinned; empty when unlinked), and what the
        // draft would cost against the one its type resolves to.
        $creditYear = LeaveCreditPolicy::yearOf(PortalLabels::todayKey());
        $balances = $this->tab === 'requests'
            ? app(LeaveCreditRecords::class)->own($actor, $creditYear)
            : collect();
        $filingBalances = $balances;
        $filingYear = $creditYear;
        if ($this->tab === 'requests' && $dateError === null && trim($this->type) !== '' && LeaveCreditPolicy::isLeave($this->type) && $actor->hasEmployeeLink()) {
            // The year the draft would draw on: its start year moved past
            // every year the earning job has closed (LeaveBalances).
            $filingYear = LeaveBalances::liveYear($actor->ownEmployeeId(), $this->start);
            if ($filingYear !== $creditYear) {
                $filingBalances = app(LeaveCreditRecords::class)->own($actor, $filingYear);
            }
        }
        $creditHint = $this->tab === 'requests' && $dateError === null ? $this->creditHint($actor, $filingBalances, $filingYear) : null;

        // The open payslip comes from the OWN list, never a fresh unscoped read.
        $viewing = $this->viewingId !== null ? $payslips->firstWhere('id', $this->viewingId) : null;
        $incentives = $viewing !== null ? (app(PayslipRecords::class)->incentives($actor, [$viewing->id])[$viewing->id] ?? []) : [];
        $customDeductions = $viewing !== null ? (app(PayslipRecords::class)->customDeductions($actor, [$viewing->id])[$viewing->id] ?? []) : [];
        $deductionColumns = $viewing !== null ? app(PayslipRecords::class)->deductionColumns($actor, [$viewing->id]) : collect();
        $settlements = $viewing !== null ? (app(PayslipRecords::class)->settlements($actor, [$viewing->id])[$viewing->id] ?? []) : [];
        $viewingYearly = $viewing !== null && $viewing->thirteenth_month > 0;

        // MY leave credit cash-outs, and the types I may cash out (Leave
        // Credits settings) among the balances shown — read only while the
        // Leave & travel tab shows; nothing for an unlinked account.
        $cashOuts = $this->tab === 'requests' ? app(LeaveCashOutRecords::class)->own($actor) : collect();
        $cashOutTypes = [];
        if ($this->tab === 'requests' && $actor->hasEmployeeLink() && LeaveCashOutRecords::ready()) {
            $cashable = LeaveTypeCreditRecords::cashable();
            foreach ($balances as $b) {
                if (in_array($b->request_type, $cashable, true)) {
                    $cashOutTypes[] = ['value' => $b->request_type, 'label' => $b->label.' · '.PortalLabels::credits((float) ($b->remaining ?? 0.0)).' left'];
                }
            }
        }

        return view('livewire.portal.portal-page', [
            'halvesReady' => LeaveHalves::ready(),
            'cashOuts' => $cashOuts,
            'cashOutTypes' => $cashOutTypes,
            'actor' => $actor,
            'employee' => $employee,
            'hasEmployee' => $actor->hasEmployeeLink(),
            'ownEmployeeId' => $actor->hasEmployeeLink() ? $actor->ownEmployeeId() : null,
            'leaveTypeNames' => $leaveTypeNames,
            'requestOptions' => $requestOptions,
            'mine' => $mine,
            'payslips' => $payslips,
            'dateError' => $dateError,
            'creditYear' => $creditYear,
            'balances' => $balances,
            'creditHint' => $creditHint,
            'submitDisabled' => ! $actor->hasEmployeeLink() || trim($this->type) === '' || $dateError !== null || ($creditHint['error'] ?? null) !== null,
            'viewing' => $viewing,
            'earnings' => $viewing !== null ? $this->earningLines($viewing, $incentives) : [],
            'deductions' => $viewing !== null ? $this->deductionLines($viewing, $customDeductions, $deductionColumns) : [],
            'payslipDocument' => $viewing !== null ? PayslipDocument::issued($viewing, $employee, $viewingYearly, $incentives, $customDeductions, $deductionColumns, own: true, settlements: $settlements) : null,
            // A 13th month payslip carries no attendance and no contributions,
            // so its document leaves those blocks out instead of printing
            // zeros. The note explaining it sits in the additional details,
            // which My Portal does not show.
            'viewingYearly' => $viewingYearly,
        ]);
    }

    /**
     * What the draft request would cost against MY credits: the balance row
     * the chosen type resolves to (the `_LEAVE` tolerance, against my own
     * rows only, so a client-shaped `$type` can name nothing of anyone
     * else's), the scheduled working days the draft covers (LeaveBalances —
     * a rest day costs nothing), and the year it draws on — which the view
     * compares with the panel's year before quoting a number. The figure
     * judged is what is AVAILABLE: the balance less the days unpaid cash-outs
     * have claimed. Missing or insufficient credits disable Submit; the
     * command repeats the rule against fresh locked data when filing.
     *
     * @param  Collection<int, LeaveBalanceView>  $balances
     * @return array{balance: LeaveBalanceView|null, needs: float, year: int, error: string|null}|null
     */
    private function creditHint(Access $actor, Collection $balances, int $year): ?array
    {
        if (trim($this->type) === '' || ! LeaveCreditPolicy::isLeave($this->type)) {
            return null;
        }
        $key = LeaveCreditPolicy::resolveKey(
            $this->type,
            array_values($balances->map(static fn (LeaveBalanceView $row): string => $row->request_type)->all()),
        );
        $balance = $key === null ? null : $balances->first(static fn (LeaveBalanceView $row): bool => $row->request_type === $key);
        $needs = $actor->hasEmployeeLink()
            ? app(LeaveBalances::class)->cost($actor->ownEmployeeId(), $this->dayType, $this->start, $this->end)['days']
            : LeaveCreditPolicy::coveredDays($this->dayType, $this->start, $this->end);

        return [
            'balance' => $balance,
            'needs' => $needs,
            'year' => $year,
            'error' => LeaveCreditPolicy::filingError($balance?->available, $needs, $year),
        ];
    }

    /**
     * The payslip's earnings column (PayslipDetail.tsx:26-45). Overtime that
     * was not approved (per day on the Daily Time Record; by the run-level
     * acknowledgement on older payslips) is left off entirely rather than
     * listed as money paid, and zero lines are filtered.
     *
     * Incentives and bonuses are itemised from the lines the payslip posted,
     * each hinted taxable or tax-exempt (and how much of an exempt one went
     * over the PHP 90,000 ceiling), in place of the single "Bonus" line; a
     * payslip with a bonus but no lines keeps that line.
     *
     * @param  list<PayslipIncentiveView>  $incentives
     * @return list<array{label: string, value: float, hint: ?string, note?: string}>
     */
    private function earningLines(Payslip $p, array $incentives = []): array
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
        $lines[] = ['label' => 'Other earnings', 'value' => $p->other_earnings, 'hint' => null];
        // The settlement of days paid in advance on an earlier payslip; 0 on
        // every other payslip, so filtered out like the 13th month line.
        $lines[] = ['label' => 'Attendance settlement', 'value' => (float) ($p->settlement ?? 0.0), 'hint' => null];
        // A 13th month payslip; 0 on every other payslip, so filtered out.
        $lines[] = [
            'label' => '13th month pay',
            'value' => $p->thirteenth_month,
            'hint' => null,
            'note' => $p->taxable_benefits_excess > 0
                ? PortalLabels::peso($p->taxable_benefits_excess).' taxed over the yearly ceiling'
                : 'Tax-exempt',
        ];
        foreach ($incentives as $line) {
            // A worded note, not a figure hint: it renders in the text face.
            $lines[] = [
                'label' => $line->name,
                'value' => $line->amount,
                'hint' => null,
                'note' => $line->is_taxable
                    ? 'Taxable'
                    : 'Tax-exempt'.($line->over_ceiling > 0 ? ' · '.PortalLabels::peso($line->over_ceiling).' taxed over the yearly ceiling' : ''),
            ];
        }
        if ($incentives === []) {
            $lines[] = ['label' => 'Bonus', 'value' => $p->bonus, 'hint' => null];
        }

        return array_values(array_filter($lines, static fn (array $l): bool => $l['value'] !== 0.0));
    }

    /**
     * The payslip's deductions column (PayslipDetail.tsx:47-63).
     *
     * The lines follow the Payroll Deductions list: each automatic row as the
     * list names it, each typed row as this payslip posted it (its snapshot's
     * name, so a later catalog change cannot re-explain issued pay), then the
     * one free-form Other line and the two DTR-priced lines, as on the grid.
     * Every amount is already inside total_deductions; only the names are
     * read here.
     *
     * @param  list<PayslipDeductionView>  $customDeductions
     * @param  Collection<int, DeductionColumnView>  $columns
     * @return list<array{label: string, value: float, hint: ?string}>
     */
    private function deductionLines(Payslip $p, array $customDeductions, Collection $columns): array
    {
        $posted = [];
        foreach ($customDeductions as $line) {
            $posted['row:'.$line->deduction_code] = $line;
        }

        $lines = [];
        foreach ($columns as $column) {
            $field = $column->calculation !== null ? DeductionCalculation::statutoryKey($column->calculation) : null;
            if (! $column->headsColumn()) {
                continue; // the Late / Undertime row: its two lines follow Other deductions below
            }
            if ($field !== null) {
                $lines[] = ['label' => $column->name, 'value' => (float) $p->{$field}, 'hint' => null];
            } elseif (isset($posted[$column->key()])) {
                $lines[] = ['label' => $posted[$column->key()]->name, 'value' => (float) $posted[$column->key()]->amount, 'hint' => null];
            }
        }
        $lines[] = ['label' => 'Other deductions', 'value' => (float) $p->other_deductions, 'hint' => null];
        if ($columns->contains(static fn (DeductionColumnView $c): bool => $c->calculation === DeductionCalculation::LATE_UNDERTIME)) {
            $lines[] = ['label' => 'Undertime', 'value' => (float) $p->undertime_deduction, 'hint' => $p->undertime_minutes.' min'];
            $lines[] = ['label' => 'Late', 'value' => (float) $p->late_deduction, 'hint' => $p->late_minutes.' min'];
        }

        return array_values(array_filter($lines, static fn (array $l): bool => $l['value'] !== 0.0));
    }
}
