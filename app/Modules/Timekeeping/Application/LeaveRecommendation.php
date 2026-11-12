<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application;

use Illuminate\Support\Facades\Schema;

/**
 * The recommendation on a pending leave or official-business request — the
 * leave application's "For approval" / "For disapproval due to …"
 * (section 7.B). A leave manager records it before anyone decides the
 * request (RecommendAbsence); the request stays pending, and it may be
 * approved or disapproved whatever was recommended, or with no
 * recommendation at all. A recommendation for disapproval says why.
 */
final class LeaveRecommendation
{
    public const string FOR_APPROVAL = 'FOR_APPROVAL';

    public const string FOR_DISAPPROVAL = 'FOR_DISAPPROVAL';

    /** @var array<string, string> */
    public const array LABELS = [self::FOR_APPROVAL => 'For approval', self::FOR_DISAPPROVAL => 'For disapproval'];

    /** The longest reason kept: the column's length. */
    public const int MAX_LENGTH = 1000;

    private function __construct() {}

    /** Whether the request rows carry the recommendation yet (`2026_11_08_0004`). */
    public static function ready(): bool
    {
        return once(static fn (): bool => Schema::hasColumns('leave_travel_requests', ['recommendation', 'recommendation_reason', 'recommended_by', 'recommended_at']));
    }

    /** The refusal for a recommendation that cannot be recorded as given, or null. */
    public static function error(string $recommendation, string $reason): ?string
    {
        if (! isset(self::LABELS[$recommendation])) {
            return 'Choose for approval or for disapproval.';
        }
        if ($recommendation === self::FOR_DISAPPROVAL && $reason === '') {
            return 'Say why disapproval is recommended.';
        }
        if (mb_strlen($reason) > self::MAX_LENGTH) {
            return 'Keep the reason to '.self::MAX_LENGTH.' characters.';
        }

        return null;
    }

    /** "For approval", "For disapproval due to: short staff"; null when none is recorded. */
    public static function describe(?string $recommendation, ?string $reason): ?string
    {
        if ($recommendation === null || ! isset(self::LABELS[$recommendation])) {
            return null;
        }
        $reason = trim((string) $reason);

        return $recommendation === self::FOR_DISAPPROVAL && $reason !== ''
            ? self::LABELS[$recommendation].' due to: '.$reason
            : self::LABELS[$recommendation];
    }
}
