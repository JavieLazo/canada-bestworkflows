<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\PayrollRun;
use App\Modules\Payroll\Application\PayrollData;
use App\Modules\Payroll\Application\PayrollReview;
use App\Support\Access;
use RuntimeException;

/** Direct-command tests must obtain the same server receipt as a displayed saved preview. */
final class PayrollReviewFixture
{
    public static function token(Access $actor, string $runId): string
    {
        $run = PayrollRun::query()->with('members.incentives', 'members.deductions')->findOrFail($runId);
        $overrides = PayrollData::overridesFromMembers($run->members);
        $calculation = PayrollData::calculateDrafts(PayrollData::memberIds($run->members),
            $run->period_start->format('Y-m-d'), $run->period_end->format('Y-m-d'),
            $overrides['deductions'], $overrides['allowances'], $overrides['earnings'], actor: $actor,
            draftIncentives: $overrides['incentives'], draftCustomDeductions: $overrides['customDeductions']);
        if ($calculation['inputs'] === null) {
            throw new RuntimeException('Review fixture requires a nonempty saved run.');
        }

        return PayrollReview::issue($actor, $runId, $run->name, $calculation['inputs']);
    }
}
