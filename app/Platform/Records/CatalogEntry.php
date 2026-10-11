<?php

declare(strict_types=1);

namespace App\Platform\Records;

use App\Platform\ReadModels\Record;

/** Common name/description editor data, detached from each owner's model.
 * @property-read string $id
 * @property-read string $code
 * @property-read string $name
 * @property-read string|null $description
 */
final readonly class CatalogEntry extends Record {}
