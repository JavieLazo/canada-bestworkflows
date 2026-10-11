<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Contracts\Views;

use App\Platform\ReadModels\Record;

/** A report carries rendered scalar cells, never live persistence relationships.
 * @property string $id
 * @property list<string> $cells
 */
final readonly class ReportRow extends Record {}
