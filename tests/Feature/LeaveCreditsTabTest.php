<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Livewire\Leaves\Index;
use App\Livewire\Leaves\LeaveCredits;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\LeaveCredit;
use App\Models\LeaveCreditEntry;
use App\Models\LeaveTravelRequest;
use App\Models\LeaveType;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Timekeeping\Application\Queries\LeaveCreditRecords;
use App\Support\Access;
use App\Support\LocalClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/**
 * The SCREENS of leave credits (leave_credits, 2026_09_26_0003): Leave
 * Management's tab strip and its Leave Credits child, the "Set credits"
 * dialog, and the review dialog's credits line. The module operations they
 * call are pinned in LeaveCreditsCommandTest; here the invariant is that
 * every screen shows exactly what the operations resolve and never writes
 * around them — the child's one write goes through SaveLeaveCredits behind a
 * fresh Access, its employee id is #[Locked], the draft is re-validated by
 * the command (a self-edit, a bad value and an invented token all keep the
 * dialog open and write nothing), the child paginates under its own page
 * name, and the balances shown are DERIVED from approved with-pay requests,
 * never stored.
 *
 * The clock is frozen at 2026-09-26 Manila so the child's default year is
 * the fixtures' year (2026) whatever day the suite runs on.
 */

/** @param list<string> $modules */
function lctabRole(array $modules, bool $allData): Role
{
    $role = new Role;
    $role->forceFill(['name' => 'LcTab '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();

    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }

    return $role;
}

/**
 * Signs in a fresh account; the container's scoped Access is forgotten so a
 * test that switches actors never reads the previous one.
 *
 * @param  list<string>  $modules
 */
function lctabActor(array $modules, bool $allData = false, ?string $employeeId = null): User
{
    $user = User::factory()->create([
        'role_id' => lctabRole($modules, $allData)->getKey(),
        'employee_id' => $employeeId,
    ]);

    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return $user;
}

function lctabEmployee(string $first, string $last): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => 'LCT-'.Str::random(10),
        'first_name' => $first,
        'last_name' => $last,
        'employment_status' => 'Regular',
        'date_hired' => '2024-01-01',
        'is_active' => true,
    ])->save();

    return $employee;
}

function lctabType(string $code, string $name): LeaveType
{
    $type = new LeaveType;
    $type->forceFill(['code' => $code, 'name' => $name])->save();

    return $type;
}

/** The two Leave Settings rows every table here has: Sick Leave, then Vacation Leave, in name order. */
function lctabTypes(): void
{
    lctabType('LT-001', 'Sick Leave');
    lctabType('LT-002', 'Vacation Leave');
}

/** One leave_credits row, the token as `leave_travel_requests.request_type` stores it. */
function lctabCredit(EmployeeProfile $employee, string $key, float $credits, int $year = 2026): LeaveCredit
{
    $row = new LeaveCredit;
    $row->forceFill([
        'employee_id' => (string) $employee->getKey(),
        'year' => $year,
        'request_type' => $key,
        'credits' => $credits,
    ])->save();

    return $row;
}

/**
 * The LeavesModuleTest fixture: a PENDING legacy-token `SICK` request, two
 * whole days, 2026-08-10 to 2026-08-11.
 *
 * @param  array<string, string|bool|null>  $overrides  column => value, merged over the defaults
 */
function lctabRequest(EmployeeProfile $employee, string $code, array $overrides = []): LeaveTravelRequest
{
    $request = new LeaveTravelRequest;
    $request->forceFill(array_merge([
        'code' => $code,
        'employee_id' => (string) $employee->getKey(),
        'request_type' => 'SICK',
        'day_type' => 'WHOLE_DAY',
        'start_date' => '2026-08-10',
        'end_date' => '2026-08-11',
        'reason' => 'Flu',
        'status' => 'PENDING',
    ], $overrides))->save();

    return $request;
}

/** The credits table's one <tr> for this employee, so the dialog further down cannot mask a wrong cell. */
function lctabRowHtml(string $html, EmployeeProfile $employee): string
{
    $needle = 'wire:key="credits-'.$employee->getKey().'"';
    expect($html)->toContain($needle);

    return Str::before(Str::after($html, $needle), '</tr>');
}

/** The review dialog's credits section alone, from its data attribute to the end of the section. */
function lctabCreditsLine(string $html): string
{
    expect($html)->toContain('data-credits-line');

    return Str::before(Str::after($html, 'data-credits-line'), '</section>');
}

/** The no-row cell: an em dash in muted text, distinct from the identity meta's own dash. */
const LCTAB_NO_ROW = '<span class="text-muted-foreground">—</span>';

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-26 12:00:00', LocalClock::zone()));
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/* ── the tab strip ───────────────────────────────────────────────────────── */

it('honours ?tab=credits on mount, collapses an unknown tab to the requests and mounts the child on the credits tab', function () {
    lctabTypes();
    $employee = lctabEmployee('Ana', 'Reyes');
    $request = lctabRequest($employee, 'LR-001');
    lctabActor([ModuleCode::LEAVES], allData: true);

    // The facade and the manager are one singleton, so the query params set
    // here are the ones the next test() call mounts with (the portal shape).
    Livewire::withQueryParams(['tab' => 'credits']);
    Livewire::test(Index::class)
        ->assertSet('tab', 'credits')
        ->assertSee('Leave credits as of this date.')
        ->assertSeeHtml('>Vacation Leave</span></th>')
        ->assertDontSee('LR-001');

    Livewire::withQueryParams(['tab' => 'nonsense']);
    Livewire::test(Index::class)
        ->assertSet('tab', 'requests')
        ->assertSee('LR-001')
        ->assertDontSee('Leave credits as of this date.');

    // A client-side $set with a made-up value collapses too; a real switch
    // forgets the open request so the dialog cannot outlive its tab.
    Livewire::withQueryParams([]);
    Livewire::test(Index::class)
        ->assertSet('tab', 'requests')
        ->set('tab', 'bogus')
        ->assertSet('tab', 'requests')
        ->call('show', $request->getKey())
        ->assertSet('detailOpen', true)
        ->set('tab', 'credits')
        ->assertSet('tab', 'credits')
        ->assertSet('detailOpen', false)
        ->assertSet('openId', null)
        ->assertSee('Leave credits as of this date.')
        ->assertDontSee('LR-001');
});

it('renders GET /leaves?tab=credits for a LEAVES holder with the child on the page', function () {
    lctabTypes();
    lctabEmployee('Ana', 'Reyes');
    lctabActor([ModuleCode::LEAVES], allData: true);

    // The child's wire:snapshot names it — what assertSeeLivewire() looks
    // for, spelt out because PHPStan cannot see that macro.
    $this->get('/leaves?tab=credits')
        ->assertOk()
        ->assertSee('Leave Credits')
        ->assertSee('&quot;name&quot;:&quot;leaves.leave-credits&quot;', false)
        ->assertSee('Leave credits as of this date.')
        ->assertSee('Ana Reyes')
        ->assertDontSee('No request matches those filters.');
});

/* ── the Leave Credits table ─────────────────────────────────────────────── */

it('shows an all_data LEAVES holder every employee with remaining days per type and the Set credits menu', function () {
    lctabTypes();
    $ana = lctabEmployee('Ana', 'Reyes');
    $ben = lctabEmployee('Ben', 'Torres');
    lctabCredit($ana, 'VACATION_LEAVE', 15.0);
    // Four approved days with pay in March: 11 of 15 left. The pending
    // October day costs nothing yet, and no Sick Leave credit was entered.
    lctabRequest($ana, 'LR-001', ['request_type' => 'VACATION_LEAVE', 'start_date' => '2026-03-02', 'end_date' => '2026-03-05', 'status' => 'APPROVED', 'is_paid' => true]);
    lctabRequest($ana, 'LR-002', ['request_type' => 'VACATION_LEAVE', 'start_date' => '2026-10-05', 'end_date' => '2026-10-05']);
    // Ben's two approved sick days used up his two credits: 0 days, flagged.
    lctabCredit($ben, 'SICK_LEAVE', 2.0);
    lctabRequest($ben, 'LR-003', ['status' => 'APPROVED', 'is_paid' => true]);
    lctabActor([ModuleCode::LEAVES], allData: true);

    $page = Livewire::test(LeaveCredits::class)
        ->assertSet('year', 2026, true)
        ->assertSeeHtmlInOrder(['>Sick Leave</span></th>', '>Vacation Leave</span></th>'])
        ->assertSee('Ana Reyes')
        ->assertSee('Ben Torres')
        ->assertSee('Set Credits…')
        ->assertSeeHtml("openCredits('{$ana->getKey()}')")
        ->assertSeeHtml("openCredits('{$ben->getKey()}')")
        ->assertSeeHtml('aria-label="Actions for Ana Reyes"');

    $html = $page->html();
    expect(lctabRowHtml($html, $ana))
        ->toContain(LCTAB_NO_ROW)
        ->toContain('>11.000</span>')
        ->not->toContain('pill-caution');
    expect(lctabRowHtml($html, $ben))
        ->toContain('pill pill-caution')
        ->toContain('>0.000</span>')
        ->toContain(LCTAB_NO_ROW)
        ->not->toContain('>15.000</span>');
});

it('shows an own-rows LEAVES holder only their own row without the menu, and an unlinked one nobody', function () {
    lctabTypes();
    $ana = lctabEmployee('Ana', 'Reyes');
    $ben = lctabEmployee('Ben', 'Torres');
    lctabCredit($ana, 'VACATION_LEAVE', 15.0);
    lctabCredit($ben, 'VACATION_LEAVE', 10.0);
    lctabActor([ModuleCode::LEAVES], allData: false, employeeId: (string) $ana->getKey());

    $page = Livewire::test(LeaveCredits::class)
        ->assertSee('Ana Reyes')
        ->assertDontSee('Ben Torres')
        ->assertDontSee('Set credits')
        ->assertDontSeeHtml('openCredits(')
        ->assertDontSeeHtml('aria-label="Actions for Ana Reyes"');

    $html = $page->html();
    expect(lctabRowHtml($html, $ana))
        ->toContain('>15.000</span>');
    expect($html)->not->toContain('>10.000</span>');

    // No employee link and no all_data: the directory answers nobody,
    // never everybody.
    lctabActor([ModuleCode::LEAVES], allData: false);

    Livewire::test(LeaveCredits::class)
        ->assertSee('No employee matches those filters.')
        ->assertDontSee('Ana Reyes')
        ->assertDontSee('Ben Torres');
});

it('drops openCredits on the floor and does nothing on saveCredits without all_data', function () {
    lctabTypes();
    $ana = lctabEmployee('Ana', 'Reyes');
    lctabActor([ModuleCode::LEAVES], allData: false, employeeId: (string) $ana->getKey());

    Livewire::test(LeaveCredits::class)
        ->call('openCredits', (string) $ana->getKey())
        ->assertSet('creditsEmployeeId', '')
        ->assertSet('creditDraft', [])
        ->assertNotDispatched('modal-show')
        ->set('creditDraft.VACATION_LEAVE', '15')
        ->call('saveCredits')
        ->assertNotDispatched('modal-close');

    expect(LeaveCredit::query()->count())->toBe(0);
    $this->assertDatabaseMissing('audit_logs', ['action' => 'LEAVE_CREDITS_SET']);
});

it('keeps creditsEmployeeId locked against a client write', function () {
    $ana = lctabEmployee('Ana', 'Reyes');
    lctabActor([ModuleCode::LEAVES], allData: true);

    expect(fn () => Livewire::test(LeaveCredits::class)->set('creditsEmployeeId', (string) $ana->getKey()))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

/* ── the Set credits dialog ──────────────────────────────────────────────── */

it('opens the Set credits dialog seeded from the year balances for a visible employee only', function () {
    lctabTypes();
    $ana = lctabEmployee('Ana', 'Reyes');
    lctabCredit($ana, 'VACATION_LEAVE', 12.5);
    // Last year's row must not seed this year's field; a stale token still
    // held this year gets a field of its own, after the Leave Settings types.
    lctabCredit($ana, 'VACATION_LEAVE', 10.0, 2025);
    lctabCredit($ana, 'EMERGENCY_LEAVE', 3.0);
    lctabActor([ModuleCode::LEAVES], allData: true);

    Livewire::test(LeaveCredits::class)
        ->call('openCredits', (string) $ana->getKey())
        ->assertSet('creditsEmployeeId', (string) $ana->getKey())
        ->assertSet('creditDraft', ['SICK_LEAVE' => '', 'VACATION_LEAVE' => '12.500', 'EMERGENCY_LEAVE' => '3.000'], true)
        ->assertDispatched('modal-show', name: 'leave-credits')
        ->assertSee('Leave Credits · Ana Reyes · 2026')
        ->assertSeeHtml('wire:key="leave-credits-'.$ana->getKey().'"')
        ->assertSee('Emergency Leave')
        ->assertSee('used 0');

    // Opening writes nothing.
    expect(LeaveCredit::query()->count())->toBe(3);
    $this->assertDatabaseMissing('audit_logs', ['action' => 'LEAVE_CREDITS_SET']);

    // An id outside the directory is dropped: nothing staged, no dialog.
    Livewire::test(LeaveCredits::class)
        ->call('openCredits', 'no-such-employee')
        ->assertSet('creditsEmployeeId', '')
        ->assertSet('creditDraft', [])
        ->assertNotDispatched('modal-show');
});

it('saves the dialog through the command, closes it, repaints the row and writes one audit row', function () {
    lctabTypes();
    $ana = lctabEmployee('Ana', 'Reyes');
    lctabCredit($ana, 'VACATION_LEAVE', 15.0);
    lctabActor([ModuleCode::LEAVES], allData: true);

    $page = Livewire::test(LeaveCredits::class)
        ->call('openCredits', (string) $ana->getKey())
        ->assertSet('creditDraft.VACATION_LEAVE', '15.000')
        ->set('creditDraft.VACATION_LEAVE', '12.5')
        ->set('creditDraft.SICK_LEAVE', '7')
        ->call('saveCredits')
        ->assertSet('creditsEmployeeId', '')
        ->assertSet('creditDraft', [])
        ->assertDispatched('modal-close', name: 'leave-credits')
        ->assertDispatched('toast-show', fn ($n, $p) => ($p['slots']['text'] ?? null) === "Ana Reyes's 2026 leave credits saved.");

    // The row repaints from the stored year in the same response.
    expect(lctabRowHtml($page->html(), $ana))
        ->toContain('>7.000</span>')
        ->toContain('>12.500</span>')
        ->not->toContain('>15.000</span>');

    $stored = LeaveCredit::query()
        ->where('employee_id', (string) $ana->getKey())
        ->where('year', 2026)
        ->orderBy('request_type')
        ->get()
        ->mapWithKeys(static fn (LeaveCredit $row): array => [$row->request_type => $row->credits])
        ->all();
    expect($stored)->toBe(['SICK_LEAVE' => 7.0, 'VACATION_LEAVE' => 12.5]);

    $audits = AuditLog::query()->where('action', 'LEAVE_CREDITS_SET')->get();
    expect($audits)->toHaveCount(1)
        ->and($audits->first()->entity)->toBe('leave_credits')
        ->and($audits->first()->entity_id)->toBe((string) $ana->getKey())
        ->and($audits->first()->details)->toBe('Ana Reyes · 2026 · Sick Leave 7 · Vacation Leave 12.5');
});

it('keeps the dialog open and writes nothing when the command refuses the save', function () {
    lctabTypes();
    $ana = lctabEmployee('Ana', 'Reyes');
    $ben = lctabEmployee('Ben', 'Torres');
    // An all_data LEAVES holder linked to Ana sets her own credits from the
    // dialog: one administrator runs the whole flow.
    lctabActor([ModuleCode::LEAVES], allData: true, employeeId: (string) $ana->getKey());

    Livewire::test(LeaveCredits::class)
        ->call('openCredits', (string) $ana->getKey())
        ->assertDispatched('modal-show', name: 'leave-credits')
        ->set('creditDraft.VACATION_LEAVE', '10')
        ->call('saveCredits')
        ->assertDispatched('modal-close');
    expect(LeaveCredit::query()->where('employee_id', (string) $ana->getKey())->where('request_type', 'VACATION_LEAVE')->value('credits'))->toBe(10.0);
    LeaveCredit::query()->where('employee_id', (string) $ana->getKey())->delete();
    AuditLog::query()->where('action', 'LEAVE_CREDITS_SET')->delete();

    // A value with too many decimals, then a token the client made up beside
    // a good value: each refused before anything is written.
    Livewire::test(LeaveCredits::class)
        ->call('openCredits', (string) $ben->getKey())
        ->set('creditDraft.VACATION_LEAVE', '12.34567')
        ->call('saveCredits')
        ->assertSet('creditsEmployeeId', (string) $ben->getKey())
        ->assertSet('creditDraft.VACATION_LEAVE', '12.34567')
        ->assertNotDispatched('modal-close')
        ->assertDispatched('toast-show', fn ($n, $p) => ($p['slots']['text'] ?? null) === 'Vacation Leave: Enter a number of days with up to four decimals.')
        ->set('creditDraft.VACATION_LEAVE', '12.5')
        ->set('creditDraft.BOGUS', '1')
        ->call('saveCredits')
        ->assertSet('creditsEmployeeId', (string) $ben->getKey())
        ->assertNotDispatched('modal-close')
        ->assertDispatched('toast-show', fn ($n, $p) => ($p['slots']['text'] ?? null) === 'That leave type no longer exists.');

    expect(LeaveCredit::query()->count())->toBe(0);
    $this->assertDatabaseMissing('audit_logs', ['action' => 'LEAVE_CREDITS_SET']);
});

/* ── the year select and the paginator ───────────────────────────────────── */

it('collapses a date outside the offered three years to today, keeps last year and reads that year', function () {
    lctabTypes();
    $ana = lctabEmployee('Ana', 'Reyes');
    lctabCredit($ana, 'VACATION_LEAVE', 15.0);
    lctabCredit($ana, 'VACATION_LEAVE', 10.0, 2025);
    lctabActor([ModuleCode::LEAVES], allData: true);

    // The date field posts 'Y-m-d'; the year follows the date and nothing sets it directly.
    $page = Livewire::test(LeaveCredits::class)
        ->assertSet('asOf', '2026-09-26')
        ->assertSet('year', 2026, true)
        ->set('asOf', '2099-06-01')
        ->assertSet('asOf', '2026-09-26')
        ->assertSet('year', 2026, true)
        ->set('asOf', '2025-12-31')
        ->assertSet('year', 2025, true);
    expect(lctabRowHtml($page->html(), $ana))
        ->toContain('>10.000</span>')
        ->not->toContain('>15.000</span>');

    $page->set('asOf', '2027-01-01')->assertSet('year', 2027, true);
    expect(lctabRowHtml($page->html(), $ana))
        ->toContain(LCTAB_NO_ROW)
        ->not->toContain('>10.000</span>')
        ->not->toContain('>15.000</span>');

    $page->set('asOf', 'not-a-date')->assertSet('asOf', '2026-09-26')->assertSet('year', 2026, true);
    expect(fn () => $page->set('year', 2025))->toThrow(CannotUpdateLockedPropertyException::class);
    expect(lctabRowHtml($page->html(), $ana))->toContain('>15.000</span>');
});

it('reads the books as they stood on the chosen day: later entries taken back, later approvals not yet used, a baseline kept', function () {
    lctabTypes();
    $ana = lctabEmployee('Ana', 'Reyes');
    $ben = lctabEmployee('Ben', 'Santos');
    $entry = static function (EmployeeProfile $employee, string $key, string $kind, float $days, string $manila): void {
        $row = new LeaveCreditEntry;
        $row->forceFill(['employee_id' => (string) $employee->getKey(), 'year' => 2026, 'request_type' => $key, 'kind' => $kind,
            'days' => $days, 'note' => 'Fixture', 'created_at' => LocalClock::instant($manila)->utc()])->save();
    };
    // Ana's Vacation Leave: 15 on the books before movements were kept, 1.25 earned on 1 Sep, 0.077 charged on 16 Sep.
    lctabCredit($ana, 'VACATION_LEAVE', 16.173);
    $entry($ana, 'VACATION_LEAVE', 'BASELINE', 15.0, '2026-09-01T18:00:00');
    $entry($ana, 'VACATION_LEAVE', 'ACCRUAL', 1.25, '2026-09-01T18:00:01');
    $entry($ana, 'VACATION_LEAVE', 'CHARGE', -0.077, '2026-09-16T10:00:00');
    // Her Sick Leave: 15 with no movement, and two days of sick leave approved with pay on 20 Aug.
    lctabCredit($ana, 'SICK_LEAVE', 15.0);
    lctabRequest($ana, 'LR-APPROVED', ['status' => 'APPROVED', 'is_paid' => true,
        'reviewed_at' => LocalClock::instant('2026-08-20T09:00:00')->utc()->format('Y-m-d H:i:s.v')]);
    // Ben's Vacation Leave was first entered on 5 Sep.
    lctabCredit($ben, 'VACATION_LEAVE', 5.0);
    $entry($ben, 'VACATION_LEAVE', 'OPENING', 5.0, '2026-09-05T09:00:00');
    $actor = lctabActor([ModuleCode::LEAVES], allData: true);
    $access = Access::forActor($actor);
    $ids = [(string) $ana->getKey(), (string) $ben->getKey()];
    $left = static fn (array $rows): array => array_map(static fn (array $list): array => array_column(array_map(static fn ($b): array => ['k' => $b->request_type, 'v' => $b->remaining], $list), 'v', 'k'), $rows);

    // Today is the ordinary balance, by the one rule.
    $records = app(LeaveCreditRecords::class);
    expect($left($records->balancesAsOf($access, '2026-09-26', $ids)))->toBe($left($records->balancesForEmployees($access, 2026, $ids)));

    $page = Livewire::test(LeaveCredits::class);
    expect(lctabRowHtml($page->html(), $ana))->toContain('>13.000</span>')->toContain('>16.173</span>')
        ->and(lctabRowHtml($page->html(), $ben))->toContain('>5.000</span>');

    // 10 Sep: the charge of the 16th is not on the books yet.
    $page->set('asOf', '2026-09-10');
    expect(lctabRowHtml($page->html(), $ana))->toContain('>13.000</span>')->toContain('>16.250</span>')
        ->and(lctabRowHtml($page->html(), $ben))->toContain('>5.000</span>');

    // 15 Aug: neither the earning nor the charge, the leave not yet approved, and Ben's credit not entered.
    $page->set('asOf', '2026-08-15');
    $row = lctabRowHtml($page->html(), $ana);
    expect(substr_count($row, '>15.000</span>'))->toBe(2)
        ->and($row)->not->toContain('>13.000</span>')
        ->and(lctabRowHtml($page->html(), $ben))->toContain(LCTAB_NO_ROW)->not->toContain('5.000');

    // Set credits still edits the allocation as it stands now, whatever day the table shows.
    $page->call('openCredits', (string) $ana->getKey())
        ->assertSet('creditDraft.VACATION_LEAVE', '16.173')
        ->assertSet('creditDraft.SICK_LEAVE', '15.000');
});

it('paginates under its own creditsPage name, clamps at the last page and starts over on a date change', function () {
    lctabTypes();
    for ($i = 1; $i <= 21; $i++) {
        lctabEmployee('Test', sprintf('Row%02d', $i));
    }
    lctabActor([ModuleCode::LEAVES], allData: true);

    Livewire::test(LeaveCredits::class)
        ->assertSee('Test Row01')
        ->assertSee('Test Row20')
        ->assertDontSee('Test Row21')
        ->call('nextPage', 'creditsPage')
        ->assertSet('paginators.creditsPage', 2)
        ->assertSet('paginators.page', null)
        ->assertSee('Test Row21')
        ->assertDontSee('Test Row01')
        ->call('nextPage', 'creditsPage')
        ->assertSet('paginators.creditsPage', 2)
        ->assertSee('Test Row21')
        ->set('asOf', '2025-12-31')
        ->assertSet('paginators.creditsPage', 1)
        ->assertSee('Test Row01')
        ->assertDontSee('Test Row21');
});

/* ── the review dialog's credits line ────────────────────────────────────── */

it('shows a pending leave request against its type credit: credited, used, left and what it needs', function () {
    lctabTypes();
    $ana = lctabEmployee('Ana', 'Reyes');
    lctabCredit($ana, 'SICK_LEAVE', 15.0);
    // The legacy `SICK` token is charged to the `SICK_LEAVE` credit.
    $request = lctabRequest($ana, 'LR-001');
    lctabActor([ModuleCode::LEAVES], allData: true);

    $page = Livewire::test(Index::class)
        ->call('show', $request->getKey())
        ->assertSet('detailOpen', true)
        ->assertSee('This request needs 2.000')
        ->assertSeeHtmlInOrder([
            'data-credits-line',
            'Sick Leave Credits · 2026',
            '<span class="num text-sm">This request needs 2.000</span>',
            'Total Credited</dt>', '>15.000</dd>',
            'Used</dt>', '>0.000</dd>',
            'Available Balance</dt>', 'text-success">15.000</dd>',
            'Credit usage', '0.00%',
        ]);

    expect(lctabCreditsLine($page->html()))
        ->not->toContain('pill-caution')
        ->not->toContain('counted');
});

it('flags a pending request that needs more than is left', function () {
    lctabTypes();
    $ana = lctabEmployee('Ana', 'Reyes');
    lctabCredit($ana, 'SICK_LEAVE', 1.0);
    $request = lctabRequest($ana, 'LR-001');
    lctabActor([ModuleCode::LEAVES], allData: true);

    $page = Livewire::test(Index::class)
        ->call('show', $request->getKey())
        ->assertSeeHtml('pill pill-caution')
        ->assertSeeHtmlInOrder([
            'class="num text-sm pill pill-caution">This request needs 2.000</span>',
            'Total Credited</dt>', '>1.000</dd>',
            'Used</dt>', '>0.000</dd>',
            'Available Balance</dt>', '>1.000</dd>',
        ]);

    expect(lctabCreditsLine($page->html()))->toContain('pill pill-caution');
});

it('says what an approved with-pay request counted, its days already inside used', function () {
    lctabTypes();
    $ana = lctabEmployee('Ana', 'Reyes');
    lctabCredit($ana, 'SICK_LEAVE', 15.0);
    $request = lctabRequest($ana, 'LR-001', ['status' => 'APPROVED', 'is_paid' => true]);
    lctabActor([ModuleCode::LEAVES], allData: true);

    $page = Livewire::test(Index::class)
        ->call('show', $request->getKey())
        ->assertSee('This request counted 2.000')
        ->assertSeeHtmlInOrder([
            'data-credits-line',
            'Sick Leave Credits · 2026',
            '<span class="num text-sm text-muted-foreground">This request counted 2.000</span>',
            'Total Credited</dt>', '>15.000</dd>',
            'Used</dt>', '>2.000</dd>',
            'Available Balance</dt>', '>13.000</dd>',
            // 2 of 15 used.
            'Credit usage', '13.33%', 'aria-valuenow="13.333',
        ]);

    expect(lctabCreditsLine($page->html()))
        ->not->toContain('pill-caution')
        ->not->toContain('needs');
});

it('says when no credit has been entered for the type of a pending request', function () {
    lctabTypes();
    $ana = lctabEmployee('Ana', 'Reyes');
    $request = lctabRequest($ana, 'LR-001');
    lctabActor([ModuleCode::LEAVES], allData: true);

    $page = Livewire::test(Index::class)
        ->call('show', $request->getKey())
        ->assertSee('No 2026 Sick Leave credits entered')
        ->assertSee('This request needs 2.000')
        ->assertDontSee('Total Credited')
        ->assertDontSee('Credit usage');

    // Nothing to exceed: no caution without an entered credit.
    expect(lctabCreditsLine($page->html()))->not->toContain('pill-caution');
});

it('shows no credits line for official business, a rejected or withdrawn request, one approved without pay, or a type with no credit and no setting', function () {
    lctabTypes();
    $ana = lctabEmployee('Ana', 'Reyes');
    lctabCredit($ana, 'SICK_LEAVE', 15.0);
    $ob = lctabRequest($ana, 'LR-001', ['request_type' => 'BUSINESS_TRAVEL', 'reason' => 'Client visit']);
    $rejected = lctabRequest($ana, 'LR-002', ['status' => 'REJECTED', 'start_date' => '2026-07-01', 'end_date' => '2026-07-01']);
    $withdrawn = lctabRequest($ana, 'LR-003', ['status' => 'WITHDRAWN', 'start_date' => '2026-07-06', 'end_date' => '2026-07-06']);
    $unpaid = lctabRequest($ana, 'LR-004', ['status' => 'APPROVED', 'is_paid' => false, 'start_date' => '2026-07-13', 'end_date' => '2026-07-13']);
    $unknown = lctabRequest($ana, 'LR-005', ['request_type' => 'EMERGENCY', 'start_date' => '2026-07-20', 'end_date' => '2026-07-20']);
    lctabActor([ModuleCode::LEAVES], allData: true);

    $page = Livewire::test(Index::class);

    foreach ([$ob, $rejected, $withdrawn, $unpaid, $unknown] as $request) {
        $page->call('show', $request->getKey())
            ->assertSet('openId', $request->getKey())
            ->assertSet('detailOpen', true)
            ->assertDontSeeHtml('data-credits-line');
    }
});
