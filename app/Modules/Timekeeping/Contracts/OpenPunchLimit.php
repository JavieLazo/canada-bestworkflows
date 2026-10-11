<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Contracts;

/** Consumer-owned port for the overtime ceiling used to expire abandoned punches. */
interface OpenPunchLimit
{
    public function hours(): float;
}
