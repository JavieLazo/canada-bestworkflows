<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Domain\Notifications\Notifier;
use App\Models\LeaveTravelRequest;
use App\Modules\Timekeeping\Application\LeaveRecommendation;
use App\Support\Access;
use App\Support\AuditService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Records the recommendation on a PENDING leave or official-business
 * request (LeaveRecommendation): "For approval", or "For disapproval due
 * to …" with the reason. An all-data LEAVES holder records it, their own
 * request included, as for the decision itself. The request stays
 * PENDING: whoever decides it — the same manager or another — may approve
 * or disapprove it whatever was recommended, and a request is decided with
 * no recommendation at all just as well.
 *
 * A later recommendation on the same pending request replaces the one
 * before; once the request is decided, none is recorded. The state guard is
 * in the UPDATE, so a decision landing at the same moment wins and this
 * refuses. Audited best-effort after commit (the reason stays on the
 * request, as a disapproval's does), and every other LEAVES holder is told,
 * so whoever decides knows it is ready. A save that changes nothing writes
 * nothing.
 */
final class RecommendAbsence
{
    public function handle(Access $actor, string $id, string $recommendation, ?string $reason = null): string
    {
        if (! $actor->mayReadAllData(ModuleCode::LEAVES)) {
            throw new RuntimeException('Your role cannot manage organization-wide data.');
        }
        if (! LeaveRecommendation::ready()) {
            throw new RuntimeException('Recommendations can be recorded once this installation\'s upgrade finishes.');
        }
        $reason = $recommendation === LeaveRecommendation::FOR_DISAPPROVAL ? trim((string) $reason) : '';
        $refusal = LeaveRecommendation::error($recommendation, $reason);
        if ($refusal !== null) {
            throw new RuntimeException($refusal);
        }

        $existing = LeaveTravelRequest::query()->toEmployee($actor, 'employee_id', ModuleCode::LEAVES)->whereKey($id)
            ->first(['id', 'code', 'employee_id', 'status', 'recommendation', 'recommendation_reason']);
        if ($existing === null) {
            throw new RuntimeException('That request no longer exists.');
        }
        if ($existing->status !== 'PENDING') {
            throw new RuntimeException('That request has already been reviewed.');
        }
        $label = strtolower(LeaveRecommendation::LABELS[$recommendation]);
        if ($existing->recommendation === $recommendation && trim((string) $existing->recommendation_reason) === $reason) {
            return "Request {$existing->code} is already recommended {$label}.";
        }

        $won = DB::transaction(static function () use ($actor, $id, $recommendation, $reason, $existing, $label): int {
            $won = LeaveTravelRequest::query()->whereKey($id)->where('status', 'PENDING')->update([
                'recommendation' => $recommendation,
                'recommendation_reason' => $reason === '' ? null : $reason,
                'recommended_by' => (int) $actor->userId,
                'recommended_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.v'),
            ]);
            if ($won === 0) {
                return 0;
            }
            AuditService::record($actor, AuditEvent::REQUEST_RECOMMENDED, 'leave_travel_requests', $id,
                "Request {$existing->code} recommended {$label}");
            DB::afterCommit(static fn () => Notifier::moduleHolders(ModuleCode::LEAVES, $actor->userId, 'LEAVE_RECOMMENDED',
                "Request {$existing->code} recommended {$label}", "By {$actor->fullName}", '/leaves'));

            return $won;
        });
        if ($won === 0) {
            throw new RuntimeException('That request has already been reviewed.');
        }

        return "Request {$existing->code} recommended {$label}.";
    }
}
