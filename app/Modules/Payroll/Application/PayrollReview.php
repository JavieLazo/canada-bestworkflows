<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application;

use App\Domain\Enums\ModuleCode;
use App\Support\Access;
use RuntimeException;

/**
 * A server-signed receipt binds displayed inputs to one actor, run and saved
 * draft identity — a regular run's PayrollInputs or a 13th month run's
 * ThirteenthMonthInputs (each fingerprints its own schema).
 */
final class PayrollReview
{
    public static function issue(Access $actor, string $runId, string $name, PayrollInputs|ThirteenthMonthInputs $inputs): string
    {
        if (! $actor->mayReadAllData(ModuleCode::PAYROLL)) {
            throw new RuntimeException('Your role cannot manage organization-wide data.');
        }
        $key = config('app.key');
        if (! is_string($key) || $key === '') {
            throw new RuntimeException('The payroll preview could not be verified.');
        }

        return hash_hmac('sha256', PayrollInputs::json([
            'purpose' => 'payroll-finalization-review-v1', 'actor_id' => $actor->userId,
            'run_id' => $runId, 'name' => $name, 'inputs' => $inputs->fingerprint(),
        ]), $key);
    }

    public static function verify(Access $actor, string $runId, string $name, PayrollInputs|ThirteenthMonthInputs $inputs, ?string $receipt): void
    {
        if ($receipt === null || ! hash_equals(self::issue($actor, $runId, $name, $inputs), $receipt)) {
            throw new PayrollReviewRequired;
        }
    }
}
