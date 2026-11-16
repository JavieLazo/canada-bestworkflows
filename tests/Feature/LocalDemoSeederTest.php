<?php

declare(strict_types=1);

use App\Events\RealtimeChange;
use App\Models\AttendanceLog;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\CommunityPost;
use App\Models\EmployeeProfile;
use App\Models\LeaveTravelRequest;
use App\Models\Shift;
use App\Modules\Timekeeping\Contracts\PostedPayrollDates;
use App\Support\ManilaClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

require_once __DIR__.'/../Support/ClientProjectFixtures.php';

beforeEach(function (): void {
    $this->travelTo(new DateTimeImmutable('2026-11-12T23:10:00+08:00'));
});

function localDemoPunch(EmployeeProfile $employee, string $date, string $time, string $type = 'TIME_OUT'): AttendanceLog
{
    return AttendanceLog::query()->create(['employee_id' => $employee->id, 'log_type' => $type, 'source' => AttendanceLog::SOURCE_CORRECTION,
        'site_id' => null, 'logged_at' => ManilaClock::instant($date.'T'.$time)->utc()]);
}

/** @return array<string, string> */
function localDemoHashes(): array
{
    $hashes = [];
    foreach (['users', 'roles', 'role_module_grants', 'employee_profiles', 'attendance_logs', 'attendance_remarks', 'leave_travel_requests', 'community_posts', 'community_comments',
        'community_reactions', 'chat_conversations', 'chat_participants', 'chat_messages', 'notifications'] as $table) {
        $hashes[$table] = hash('sha256', DB::table($table)->orderBy('id')->get()->toJson());
    }

    return $hashes;
}

it('adds current history for linked accounts, preserves Maria exceptions and existing days, and repeats without rewrites', function () {
    $maria = cpaUser(cpaEmployee('Maria'), ['EDTR', 'LEAVES', 'COMMUNITY', 'CHAT'], allData: true);
    $maria->update(['email' => 'admin@bestworkflows.local']);
    $other = cpaUser(cpaEmployee('Colleague'), ['EDTR', 'LEAVES', 'COMMUNITY', 'CHAT']);
    $in = localDemoPunch($maria->employee, '2026-11-10', '08:14:18', 'TIME_IN');
    $out11 = localDemoPunch($maria->employee, '2026-11-11', '17:09:00');
    $out12 = localDemoPunch($maria->employee, '2026-11-12', '17:00:00');
    $original = [$in->id => $in->fresh()->getRawOriginal(), $out11->id => $out11->fresh()->getRawOriginal(), $out12->id => $out12->fresh()->getRawOriginal()];
    $identities = array_intersect_key(localDemoHashes(), array_flip(['users', 'roles', 'role_module_grants', 'employee_profiles']));
    Mail::fake();
    Notification::fake();
    Event::fake([RealtimeChange::class]);

    expect(Artisan::call('ems:demo', ['--days' => 30, '--through' => '2026-11-12']))->toBe(0);
    foreach ($original as $id => $row) {
        expect(AttendanceLog::query()->findOrFail($id)->getRawOriginal())->toBe($row);
    }
    foreach (['2026-11-11' => '13:09:00', '2026-11-12' => '13:00:00'] as $date => $time) {
        $rows = AttendanceLog::query()->where('employee_id', $maria->employee_id)->whereBetween('logged_at', [ManilaClock::instant($date.'T00:00:00')->utc(), ManilaClock::instant($date.'T23:59:59.999')->utc()])->orderBy('logged_at')->get();
        expect($rows->pluck('log_type')->all())->toBe(['TIME_IN', 'TIME_OUT'])
            ->and($rows->first()->logged_at->setTimezone(ManilaClock::TZ)->format('H:i:s'))->toBe($time);
    }
    expect(AttendanceLog::query()->where('employee_id', $maria->employee_id)->whereNotNull('site_id')->exists())->toBeFalse()
        ->and(AttendanceLog::query()->where('employee_id', $maria->employee_id)->whereNotNull('latitude')->exists())->toBeFalse()
        ->and(AttendanceLog::query()->where('employee_id', $maria->employee_id)->whereNotNull('distance_meters')->exists())->toBeFalse()
        ->and(AttendanceLog::query()->where('employee_id', $other->employee_id)->count())->toBeGreaterThan(20)
        ->and(AttendanceLog::query()->max('logged_at'))->toBeLessThanOrEqual(CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.v'))
        ->and(LeaveTravelRequest::query()->count())->toBeGreaterThan(0)
        ->and(LeaveTravelRequest::query()->where('status', 'APPROVED')->exists())->toBeFalse()
        ->and(CommunityPost::query()->count())->toBe(30)
        ->and(ChatMessage::query()->count())->toBe(60)
        ->and(array_intersect_key(localDemoHashes(), $identities))->toBe($identities);
    $post = CommunityPost::query()->firstOrFail();
    $post->update(['body' => 'My edited sample']);
    $conversation = ChatConversation::query()->firstOrFail();
    $conversation->update(['title' => 'My renamed group']);
    $participant = ChatParticipant::query()->where('conversation_id', $conversation->id)->where('user_id', $other->id)->firstOrFail();
    $participant->delete();
    $hashes = localDemoHashes();
    expect(Artisan::call('ems:demo', ['--days' => 30, '--through' => '2026-11-12']))->toBe(0)
        ->and(localDemoHashes())->toBe($hashes)
        ->and($maria->employee->fresh()->work_site_id)->toBeNull();
    Mail::assertNothingOutgoing();
    Notification::assertNothingSent();
    Event::assertNotDispatched(RealtimeChange::class);
});

it('finishes its own current-day samples later without future punches or duplicates', function () {
    $user = cpaUser(cpaEmployee());
    $this->travelTo(new DateTimeImmutable('2026-11-12T09:00:00+08:00'));
    expect(Artisan::call('ems:demo', ['--days' => 1]))->toBe(0);
    $first = AttendanceLog::query()->where('employee_id', $user->employee_id)->sole();
    $original = $first->getRawOriginal();
    expect($first->log_type)->toBe('TIME_IN')->and($first->logged_at <= CarbonImmutable::now('UTC'))->toBeTrue();
    $this->travelTo(new DateTimeImmutable('2026-11-12T18:00:00+08:00'));
    expect(Artisan::call('ems:demo', ['--days' => 1]))->toBe(0)
        ->and(AttendanceLog::query()->count())->toBe(2)
        ->and($first->fresh()->getRawOriginal())->toBe($original);
});

it('keeps overnight pairs on their scheduled dates and respects posted-payroll coverage', function () {
    $user = cpaUser(cpaEmployee());
    $shift = new Shift;
    $shift->forceFill(['employee_id' => $user->employee_id, 'start_time' => '22:00:00', 'end_time' => '06:00:00', 'grace_minutes' => 15,
        'mode' => 'FIXED', 'work_days' => [1, 2, 3, 4, 5]])->save();
    $guard = Mockery::mock(PostedPayrollDates::class);
    $guard->shouldReceive('lock')->once();
    $guard->shouldReceive('lockAndCovers')->andReturnUsing(static fn ($employee, $date): bool => $date === '2026-11-10');
    app()->instance(PostedPayrollDates::class, $guard);
    expect(Artisan::call('ems:demo', ['--days' => 3, '--through' => '2026-11-12']))->toBe(0);
    $rows = AttendanceLog::query()->where('employee_id', $user->employee_id)->orderBy('logged_at')->get();
    expect($rows->pluck('log_type')->all())->toBe(['TIME_IN', 'TIME_OUT', 'TIME_IN'])
        ->and($rows[0]->logged_at->setTimezone(ManilaClock::TZ)->format('Y-m-d'))->toBe('2026-11-11')
        ->and($rows[1]->logged_at->setTimezone(ManilaClock::TZ)->format('Y-m-d'))->toBe('2026-11-12')
        ->and($rows[2]->logged_at <= CarbonImmutable::now('UTC'))->toBeTrue();
});

it('keeps approved leave dates clear of newly invented attendance', function () {
    $user = cpaUser(cpaEmployee());
    $leave = LeaveTravelRequest::query()->create(['employee_id' => $user->employee_id, 'code' => '1', 'request_type' => 'VACATION',
        'start_date' => '2026-11-11', 'end_date' => '2026-11-11', 'status' => 'APPROVED', 'is_paid' => false]);
    $original = $leave->fresh()->getRawOriginal();
    expect(Artisan::call('ems:demo', ['--days' => 3, '--through' => '2026-11-12']))->toBe(0)
        ->and(AttendanceLog::query()->count())->toBe(4)
        ->and(AttendanceLog::query()->whereBetween('logged_at', [ManilaClock::instant('2026-11-11T00:00:00')->utc(), ManilaClock::instant('2026-11-11T23:59:59.999')->utc()])->exists())->toBeFalse()
        ->and($leave->fresh()->getRawOriginal())->toBe($original);
});

it('refuses future dates, invalid spans and production without adding data', function (array $options) {
    cpaUser(cpaEmployee());
    $before = localDemoHashes();
    expect(Artisan::call('ems:demo', $options))->toBe(1)->and(localDemoHashes())->toBe($before);
})->with([
    'future date' => [['--through' => '2026-11-13']],
    'bad date' => [['--through' => '2026-02-30']],
    'zero days' => [['--days' => 0]],
    'too many days' => [['--days' => 367]],
    'non-integer days' => [['--days' => 'many']],
]);

it('never runs in production even through the seeder entry point', function () {
    cpaUser(cpaEmployee());
    $before = localDemoHashes();
    app()->instance('env', 'production');
    try {
        expect(Artisan::call('ems:demo'))->toBe(1)->and(localDemoHashes())->toBe($before);
    } finally {
        app()->instance('env', 'testing');
    }
});
