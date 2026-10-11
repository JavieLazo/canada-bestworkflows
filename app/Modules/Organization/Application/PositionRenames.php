<?php

declare(strict_types=1);

namespace App\Modules\Organization\Application;

use App\Platform\Records\PositionNameCopy;

/**
 * Carries a position's new name onto every copy other owners keep of it,
 * inside the renaming transaction. History and posted payroll are not
 * copies: they keep the name they were written with.
 */
final class PositionRenames
{
    /** @param iterable<PositionNameCopy> $copies */
    public function __construct(private readonly iterable $copies) {}

    /** How many records now carry the new name. */
    public function follow(string $positionId, string $name): int
    {
        $count = 0;
        foreach ($this->copies as $copy) {
            $count += $copy->follow($positionId, $name);
        }

        return $count;
    }
}
