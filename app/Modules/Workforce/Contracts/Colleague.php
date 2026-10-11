<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Contracts;

use App\Platform\ReadModels\Record;

/**
 * Name, position and portrait only; see ColleagueDirectory for why.
 *
 * @property-read string $id
 * @property-read string $first_name
 * @property-read string $last_name
 * @property-read string|null $suffix
 * @property-read string|null $position
 * @property-read string|null $photo_path
 */
final readonly class Colleague extends Record {}
