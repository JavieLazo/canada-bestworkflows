<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application;

use RuntimeException;

/** A stale or missing receipt must return to review, never silently post new figures. */
final class PayrollReviewRequired extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Payroll inputs changed or the preview is no longer valid. Review the updated payroll before finalizing.');
    }
}
