<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Contracts;

use Carbon\CarbonImmutable;

/** A colleague's coming work anniversary: the date it falls on and the years it completes. */
final readonly class ColleagueAnniversary
{
    public function __construct(public Colleague $colleague, public CarbonImmutable $date, public int $years) {}
}
