<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Attendance\AttendanceLog as DomainLog;
use App\Domain\Attendance\CalendarDays;
use App\Domain\Attendance\Shift;
use App\Domain\Attendance\ShiftResolver;
use App\Domain\Enums\ModuleCode;
use App\Domain\Payroll\PayrollPeriod;
use App\Models\AttendanceLog;
use App\Models\AttendanceRemark;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\CommunityComment;
use App\Models\CommunityPost;
use App\Models\CommunityReaction;
use App\Models\EmployeeProfile;
use App\Models\LeaveTravelRequest;
use App\Models\User;
use App\Modules\Timekeeping\Contracts\PostedPayrollDates;
use App\Modules\Timekeeping\Domain\Attendance\CorrectionWindow;
use App\Modules\Timekeeping\Domain\Roster\ResolvedLocation;
use App\Modules\Timekeeping\Infrastructure\ShiftRoster;
use App\Support\Access;
use App\Support\CodeSequence;
use App\Support\LocalClock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\Ulid;

/** Optional local samples; the default and production seeders never call this. */
final class LocalDemoSeeder extends Seeder
{
    private const MARIA_TIME_INS = ['2026-11-11' => '13:09:00', '2026-11-12' => '13:00:00'];

    /** @var array<string, int> */
    private array $added = [];

    /** @return array<string, int> */
    public function run(?string $through = null, int $days = 90): array
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('BestWorkflows demo data is available only in local and testing environments.');
        }
        $through ??= LocalClock::todayKey();
        if ($days < 1 || $days > 366 || ! PayrollPeriod::isPayrollDate($through) || $through > LocalClock::todayKey()) {
            throw new \RuntimeException('Choose 1 to 366 days and a valid --through date no later than today in local time.');
        }
        $from = PayrollPeriod::addCalendarDays($through, 1 - $days);
        $cutoff = min(LocalClock::instant($through.'T23:59:59.999')->utc(), CarbonImmutable::now('UTC'));
        $this->added = [];

        return DB::transaction(function () use ($from, $through, $cutoff): array {
            // Paid days and roster changes keep the same locks as normal attendance writes.
            $paid = app(PostedPayrollDates::class);
            $paid->lock();
            $users = User::query()->where('is_active', true)->with('role.grants')->orderBy('id')->get();
            $employees = EmployeeProfile::query()->whereIn('id', $users->pluck('employee_id')->filter())->where('is_active', true)->orderBy('id')->lockForUpdate()->get();
            if ($employees->isEmpty()) {
                throw new \RuntimeException('Link an active account to an active employee before adding demo history.');
            }
            $requestCode = (int) CodeSequence::next('LR', LeaveTravelRequest::query()->pluck('code'));
            foreach ($employees as $index => $employee) {
                $maria = $users->contains(static fn (User $user): bool => $user->employee_id === $employee->id && $user->email === 'admin@bestworkflows.local');
                $this->attendance($employee, $from, $through, $cutoff, $maria, $paid);
                $this->leaveRequests($employee, $from, $through, $cutoff, $index, $requestCode);
            }
            $this->discussions($users, $from, $through, $cutoff);

            return $this->added;
        });
    }

    private static function id(string $key): string
    {
        return (string) Ulid::fromBinary(substr(hash('sha256', 'ems-local-demo-v1:'.$key, true), 0, 16));
    }

    /** @param class-string<Model> $model
     * @param  array<string, mixed>  $attributes
     */
    private function add(string $model, string $key, array $attributes): bool
    {
        if ($model::query()->whereKey(self::id($key))->exists()) {
            return false;
        }
        $row = new $model;
        $row->forceFill(['id' => self::id($key)] + $attributes)->save();
        $table = $row->getTable();
        $this->added[$table] = ($this->added[$table] ?? 0) + 1;

        return true;
    }

    private function attendance(EmployeeProfile $employee, string $from, string $through, CarbonImmutable $cutoff, bool $maria, PostedPayrollDates $paid): void
    {
        $roster = app(ShiftRoster::class);
        $schedule = $roster->scheduleForEmployee($employee->id, CalendarDays::previous(CalendarDays::previous($from)), CalendarDays::next($through));
        $logs = AttendanceLog::query()->where('employee_id', $employee->id)
            ->whereBetween('logged_at', [LocalClock::instant(CalendarDays::previous(CalendarDays::previous($from)).'T00:00:00')->utc(), LocalClock::instant(CalendarDays::next($through).'T23:59:59.999')->utc()])
            ->orderBy('logged_at')->get()->map(static fn (AttendanceLog $row): DomainLog => DomainLog::fromArray(['id' => $row->id, 'log_type' => $row->log_type, 'logged_at' => $row->logged_at]))->all();
        $leave = LeaveTravelRequest::query()->where('employee_id', $employee->id)->where('status', 'APPROVED')->where('request_type', '!=', 'BUSINESS_TRAVEL')
            ->whereDate('start_date', '<=', $through)->whereDate('end_date', '>=', $from)->get();
        foreach (CalendarDays::each($from, $through) as $date) {
            if ($date < $employee->date_hired->format('Y-m-d') || $paid->lockAndCovers($employee->id, $date)
                || $leave->contains(static fn (LeaveTravelRequest $row): bool => $row->start_date->format('Y-m-d') <= $date && $row->end_date->format('Y-m-d') >= $date)) {
                continue;
            }
            $key = 'punch:'.$employee->id.':'.$date;
            $windowFrom = CalendarDays::previous(CalendarDays::previous($date));
            $windowTo = CalendarDays::next($date);
            $dayLogs = array_values(array_filter($logs, static function (DomainLog $row) use ($windowFrom, $windowTo): bool {
                $day = LocalClock::dayKey(LocalClock::instant($row->loggedAt));

                return $day >= $windowFrom && $day <= $windowTo;
            }));
            $existing = CorrectionWindow::replaceable($date, $dayLogs, $schedule);
            $special = $maria ? (self::MARIA_TIME_INS[$date] ?? null) : null;
            if ($special !== null) {
                if (AttendanceLog::query()->where('employee_id', $employee->id)->where('log_type', 'TIME_IN')->whereBetween('logged_at', [LocalClock::instant($date.'T00:00:00')->utc(), LocalClock::instant($date.'T23:59:59.999')->utc()])->exists()) {
                    continue;
                }
                $times = ['in' => LocalClock::instant($date.'T'.$special)->utc()];
            } else {
                if (! ShiftResolver::worksOnDate($date, $schedule) || array_any($existing, static fn (DomainLog $row): bool => ! in_array($row->id, array_map(static fn ($slot): string => self::id($key.':'.$slot), ['in', 'break-out', 'break-in', 'out']), true))) {
                    continue;
                }
                $shift = $schedule?->forDate($date) ?? Shift::default();
                $geometry = ShiftResolver::geometry($shift);
                $midnight = LocalClock::instant($date.'T00:00:00');
                $pattern = (int) substr(hash('sha256', $key), 0, 4);
                $variation = $pattern % 9;
                $arrival = $pattern % 11 === 0 ? $shift->graceMinutes + 12 : -5 + $variation;
                $times = ['in' => $midnight->addMinutes((int) $geometry->start + $arrival)->utc()];
                $break = ShiftResolver::scheduledBreakWindow($shift, $geometry);
                if ($break !== null) {
                    $times['break-out'] = $midnight->addMinutes((int) $break[0])->utc();
                    $times['break-in'] = $midnight->addMinutes((int) $break[1])->utc();
                }
                $departure = $pattern % 13 === 0 ? -30 : $variation * 2;
                $times['out'] = $midnight->addMinutes((int) $geometry->end + $departure)->utc();
            }
            $place = $roster->locationForDate($employee->id, $date) ?? ResolvedLocation::assigned($employee->work_site_id);
            $newDay = false;
            foreach ($times as $slot => $at) {
                if ($at > $cutoff) {
                    continue;
                }
                $attributes = ['employee_id' => $employee->id, 'site_id' => $maria ? null : $place->siteId, 'location_kind' => $place->kind,
                    'log_type' => in_array($slot, ['in', 'break-in'], true) ? 'TIME_IN' : 'TIME_OUT', 'source' => AttendanceLog::SOURCE_CORRECTION,
                    'latitude' => null, 'longitude' => null, 'distance_meters' => null, 'accuracy_meters' => null,
                    'is_geofenced' => true, 'is_anomaly' => false, 'anomaly_note' => null, 'logged_at' => $at];
                if ($this->add(AttendanceLog::class, $key.':'.$slot, $attributes)) {
                    $newDay = true;
                    $logs[] = DomainLog::fromArray(['id' => self::id($key.':'.$slot), 'log_type' => $attributes['log_type'], 'logged_at' => $at]);
                }
            }
            if ($newDay && ! AttendanceRemark::query()->where('employee_id', $employee->id)->whereDate('date', $date)->exists()) {
                $this->add(AttendanceRemark::class, 'remark:'.$employee->id.':'.$date, ['employee_id' => $employee->id, 'date' => $date,
                    'note' => '[Demo] Local attendance sample.', 'author_id' => null, 'created_at' => min($cutoff, end($times)), 'updated_at' => min($cutoff, end($times))]);
            }
        }
    }

    private function leaveRequests(EmployeeProfile $employee, string $from, string $through, CarbonImmutable $cutoff, int $index, int &$code): void
    {
        foreach (CalendarDays::each($from, $through) as $date) {
            $day = LocalClock::instant($date.'T09:00:00');
            if ($day->isWeekend() || ($day->dayOfYear + $index) % 17 !== 0 || $date < $employee->date_hired->format('Y-m-d')
                || LeaveTravelRequest::query()->where('employee_id', $employee->id)->whereDate('start_date', '<=', $date)->whereDate('end_date', '>=', $date)->exists()) {
                continue;
            }
            if ($this->add(LeaveTravelRequest::class, 'leave:'.$employee->id.':'.$date, ['code' => (string) $code, 'employee_id' => $employee->id,
                'request_type' => ['VACATION', 'SICK', 'BUSINESS_TRAVEL'][$index % 3], 'day_type' => $index % 3 === 0 ? 'HALF_DAY' : 'WHOLE_DAY',
                'start_date' => $date, 'end_date' => $date, 'reason' => '[Demo] Local sample for leave-list and request review.',
                'status' => $day->day % 2 === 0 ? 'PENDING' : 'WITHDRAWN', 'is_paid' => null, 'reviewed_by' => null, 'reviewed_at' => null,
                'created_at' => min($day->subDays(3)->utc(), $cutoff), 'updated_at' => min($day->subDays(3)->utc(), $cutoff)])) {
                $code++;
            }
        }
    }

    /** @param Collection<int, User> $users */
    private function discussions(Collection $users, string $from, string $through, CarbonImmutable $cutoff): void
    {
        $community = $users->filter(static fn (User $user): bool => Access::forUser($user)->hasModule(ModuleCode::COMMUNITY))->values();
        $chat = $users->filter(static fn (User $user): bool => Access::forUser($user)->hasModule(ModuleCode::CHAT))->values();
        foreach ($chat->chunk(8)->values() as $number => $members) {
            $key = 'conversation:'.$number;
            $conversationId = self::id($key);
            if (LocalClock::instant($from.'T08:00:00')->utc() > $cutoff) {
                continue;
            }
            $new = $this->add(ChatConversation::class, $key, ['kind' => 'GROUP', 'title' => 'Demo: Team discussion '.($number + 1), 'created_by' => $members->first()->id,
                'created_at' => LocalClock::instant($from.'T08:00:00')->utc(), 'last_message_at' => null]);
            foreach ($members as $member) {
                if ($new) {
                    $this->add(ChatParticipant::class, $key.':member:'.$member->id, ['conversation_id' => $conversationId, 'user_id' => $member->id,
                        'role' => $member->id === $members->first()->id ? 'ADMIN' : 'MEMBER', 'is_muted' => true, 'allow_mentions' => false,
                        'last_read_at' => $cutoff, 'created_at' => LocalClock::instant($from.'T08:00:00')->utc()]);
                }
            }
            $participants = ChatParticipant::query()->where('conversation_id', $conversationId)->pluck('user_id')->all();
            $members = $members->filter(static fn (User $member): bool => in_array($member->id, $participants, true));
            foreach (CalendarDays::each($from, $through) as $date) {
                foreach ($members->values() as $index => $member) {
                    $at = LocalClock::instant($date.'T14:00:00')->addMinutes($index * 3)->utc();
                    if ($at <= $cutoff) {
                        $this->add(ChatMessage::class, $key.':'.$date.':'.$member->id, ['conversation_id' => $conversationId, 'sender_id' => $member->id, 'kind' => 'TEXT',
                            'body' => '[Demo] '.$date.' '.['The review checklist is ready.', 'I have updated the sample delivery notes.', 'The next handover items are documented.', 'Thanks, I will check the remaining sample items.'][$index % 4], 'created_at' => $at]);
                    }
                }
            }
            $latest = ChatMessage::query()->where('conversation_id', $conversationId)->max('created_at');
            if ($latest !== null) {
                ChatConversation::query()->whereKey($conversationId)->where(static fn ($q) => $q->whereNull('last_message_at')->orWhere('last_message_at', '<', $latest))->update(['last_message_at' => $latest]);
            }
        }
        if ($community->isEmpty()) {
            return;
        }
        foreach (CalendarDays::each($from, $through) as $date) {
            $at = LocalClock::instant($date.'T10:15:00')->utc();
            if ($at > $cutoff) {
                continue;
            }
            $index = $at->setTimezone(LocalClock::zone())->dayOfYear;
            $key = 'post:'.$date;
            $postId = self::id($key);
            $this->add(CommunityPost::class, $key, ['author_id' => $community[$index % $community->count()]->id, 'group_id' => null, 'tag' => null,
                'body' => '[Demo] '.$date.' '.['Weekly planning notes are ready for review.', 'The sample records checklist has been updated.', 'A reminder to review upcoming handovers.', 'Team learning notes and delivery examples are ready.'][$index % 4], 'created_at' => $at]);
            if (CommunityPost::query()->whereKey($postId)->value('group_id') !== null) {
                continue;
            }
            foreach (range(1, min(3, $community->count())) as $offset) {
                $person = $community[($index + $offset) % $community->count()];
                $when = $at->addMinutes($offset * 7);
                if ($when <= $cutoff) {
                    $this->add(CommunityComment::class, $key.':comment:'.$person->id, ['post_id' => $postId, 'author_id' => $person->id, 'parent_id' => null,
                        'body' => '[Demo] '.['Thanks for the update.', 'I will check the sample details.', 'The checklist makes the next steps clear.'][$offset - 1], 'created_at' => $when]);
                    if (! CommunityReaction::query()->where('post_id', $postId)->where('user_id', $person->id)->exists()) {
                        $this->add(CommunityReaction::class, $key.':reaction:'.$person->id, ['post_id' => $postId, 'user_id' => $person->id, 'type' => 'LIKE', 'created_at' => $when, 'updated_at' => $when]);
                    }
                }
            }
        }
    }
}
