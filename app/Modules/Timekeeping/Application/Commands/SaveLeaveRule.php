<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Models\LeaveRule;
use App\Models\LeaveType;
use App\Modules\Timekeeping\Application\LeaveRules;
use App\Modules\Timekeeping\Application\LeaveSettingsAccess;
use App\Modules\Timekeeping\Application\Queries\LeaveTypeCreditRecords;
use App\Modules\Timekeeping\Domain\Credits\LeaveTypeRule;
use App\Platform\Audit\RequiredAudit;
use App\Support\Access;
use App\Support\HrisFormat;
use App\Support\LocalClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Saves a leave type's rule — its waiting period, what the employee holds
 * on acquiring it, its monthly earning and the type it falls back on — IN
 * FORCE FROM TODAY for every employee. Whoever manages the leave credit
 * settings (LeaveSettingsAccess).
 *
 * There is one rule per leave type and no draft. Underneath, each save is
 * kept as the version for its day (`leave_rules`, one per type and date),
 * so the earning job, payroll's charge read and filing still read the rule
 * that was in force on any past day: a second save on the same day
 * replaces that day's version, and nothing dated after today survives a
 * save — it never applied to anyone and nothing points at it. Credits
 * already posted and payroll already paid keep what they captured. Saving
 * what the type's rule already says writes nothing; the FIRST save of a
 * type writes its version whatever it says, because the rules are in
 * force from the first version saved (LeaveRuleBook::startsOn).
 *
 * Saving a monthly amount is what makes a type earn: the leave type's own
 * switch follows it, so nobody has to turn earning on in a second place.
 * A RequiredAudit row on the same transaction, so a rule whose change
 * cannot be recorded is not saved.
 *
 * The rule is the same for every employee and holds only what was entered:
 * no figure is shipped and nothing is checked against a legal minimum.
 */
final class SaveLeaveRule
{
    /**
     * @param  array<string, mixed>  $params
     * @return string|null the version saved for today, or null when the type's rule already says this
     */
    public function handle(Access $access, string $typeId, array $params): ?string
    {
        if (! LeaveSettingsAccess::mayManage($access)) {
            throw new RuntimeException('Your role cannot manage organization-wide data.');
        }
        if (! LeaveRules::ready() || ! LeaveTypeCreditRecords::ready()) {
            throw new RuntimeException('Leave rules can be set once this installation\'s upgrade finishes.');
        }
        $types = LeaveTypeCreditRecords::rules();
        $type = LeaveType::query()->whereKey($typeId)->first();
        $scope = $type === null ? '' : HrisFormat::normalizeRequestType((string) $type->getAttribute('name'));
        if ($type === null || ! isset($types[$scope])) {
            throw new RuntimeException('That leave type no longer exists.');
        }
        // Only a type filed against earned credits earns or falls back, and
        // only onto another such type: the other kinds hold no credits.
        $creditTokens = array_keys(array_filter($types, static fn (array $entry): bool => $entry['rules']->filedAgainstCredits()));
        $error = LeaveTypeRule::error($params, $scope, $types[$scope]['rules']->filedAgainstCredits(), array_map('strval', $creditTokens));
        if ($error !== null) {
            throw new RuntimeException($error);
        }
        $rule = LeaveTypeRule::fromParams($params);
        $stored = $rule->toArray();
        $label = $types[$scope]['name'].' rule';
        $summary = $rule->describe(array_map(static fn (array $entry): string => $entry['name'], $types));
        $today = LocalClock::todayKey();
        $current = LeaveRules::book()->version($scope, $today);
        if ($current !== null && LeaveTypeRule::fromParams($current['params'])->toArray() === $stored) {
            return null;
        }

        return DB::transaction(static function () use ($access, $type, $scope, $today, $stored, $summary, $label): string {
            // Plain reads, then rows by their keys: a locking read or a
            // delete of a version that is not there would take a gap lock.
            $later = LeaveRule::query()->where('scope', $scope)->where('effective_from', '>', $today)->pluck('id')->all();
            if ($later !== []) {
                LeaveRule::query()->whereKey($later)->delete();
            }
            $row = LeaveRule::query()->where('scope', $scope)->where('effective_from', $today)->first();
            if ($row !== null) {
                $row = LeaveRule::query()->whereKey($row->id)->lockForUpdate()->first();
            }
            $row ??= new LeaveRule(['scope' => $scope, 'effective_from' => $today]);
            $row->fill(['params' => $stored, 'status' => LeaveRule::APPROVED]);
            $row->prepared_by = $row->approved_by = $access->userId === null ? null : (int) $access->userId;
            $row->prepared_by_name = $row->approved_by_name = $access->fullName;
            $row->approved_at = CarbonImmutable::now('UTC');
            if (! $row->save()) {
                throw new RuntimeException('The rule could not be saved.');
            }
            if (($stored['earning'] ?? null) !== null && ! $type->getAttribute('earns_credits')) {
                $type->forceFill(['earns_credits' => true])->save();
            }
            RequiredAudit::record($access, AuditEvent::LEAVE_RULE_SAVED, 'leave_rules', $row->id, "{$label} from {$today}: {$summary}");

            return $row->id;
        });
    }
}
