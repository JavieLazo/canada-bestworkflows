<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application;

use RuntimeException;

/**
 * A schedule change the safeguards refuse: it would start in the past,
 * alter an earlier date's attendance record, overlap a shift still
 * running, or reach a day already paid. The message is the sentence shown to
 * the person saving; nothing was written.
 */
final class ScheduleChangeRefused extends RuntimeException {}
