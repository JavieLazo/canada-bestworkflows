<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application;

use RuntimeException;

/** Thrown by `DatedChange::preview()` to roll its transaction back; never reaches a caller. */
final class PreviewOnly extends RuntimeException {}
