<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Contracts\Views;

use App\Platform\ReadModels\Record;
use Illuminate\Support\Collection;

/**
 * Detached inventory facts; presentation cannot query or mutate persistence.
 *
 * @property-read string|null $corrected_to_id
 * @property-read string|null $corrected_to_code
 * @property-read string|null $corrected_to_mr_number
 * @property-read string $id
 * @property-read string|null $created_at
 * @property-read string|null $updated_at
 * @property-read string $code
 * @property-read string $mr_number
 * @property-read string $employee_id
 * @property-read string $issuer_employee_id
 * @property-read string $issued_on
 * @property-read string $office_name
 * @property-read array<string,mixed> $employee_snapshot
 * @property-read array<string,mixed> $issuer_snapshot
 * @property-read string $employee_name
 * @property-read string $issuer_name
 * @property-read string|null $remarks
 * @property-read string $status
 * @property-read string|null $corrected_from_id
 * @property-read string|null $replacement_id
 * @property-read int|null $recorded_by
 * @property-read string $recorder_name
 * @property-read Collection<int, ReceiptLineView> $lines
 * @property-read int $item_count
 * @property-read string $item_summary
 * @property-read float $total_cost
 */
final readonly class ReceiptView extends Record {}
