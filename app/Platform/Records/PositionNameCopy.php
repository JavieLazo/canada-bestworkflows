<?php

declare(strict_types=1);

namespace App\Platform\Records;

/**
 * An owner that keeps a copy of a position's name on its own records. A
 * rename calls `follow()` inside the renaming transaction, so the copies and
 * the position change together or not at all. It returns how many records
 * now carry the new name.
 */
interface PositionNameCopy
{
    public function follow(string $positionId, string $name): int;
}
