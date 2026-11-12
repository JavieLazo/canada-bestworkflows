<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Contracts\Views;

use App\Platform\ReadModels\Record;
use Carbon\CarbonImmutable;

/**
 * Detached LeaveCashOut data, with no persistence or lazy relationships:
 * one request to cash leave credits out, with the leave type's current
 * name and the employee's display name added by the reader.
 *
 * @property-read string $id
 * @property-read string $employee_id
 * @property-read string $employee_name
 * @property-read string $request_type
 * @property-read string $type_name
 * @property-read float $days
 * @property-read string $status
 * @property-read string|null $note
 * @property-read string|null $purpose MONETIZATION (the employee's own request) or TERMINAL (HR's payout on separation), LeaveCashOuts::PURPOSES; null on a request made before the column
 * @property-read bool|null $large_request whether it reached the share of the balance its type asks a reason for (its note is that reason); null before the column
 * @property-read CarbonImmutable $requested_at
 * @property-read string|null $reviewed_by_name
 * @property-read CarbonImmutable|null $reviewed_at
 * @property-read string|null $review_note
 * @property-read string|null $payslip_id
 * @property-read float|null $amount
 */
final readonly class LeaveCashOutView extends Record {}
