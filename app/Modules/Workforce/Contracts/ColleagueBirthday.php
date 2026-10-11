<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Contracts;

use Carbon\CarbonImmutable;

/**
 * A colleague's coming birthday: the day it falls on this time around.
 *
 * The year they were born never leaves the Workforce module, so nothing that
 * reads this can work out an age.
 */
final readonly class ColleagueBirthday
{
    public function __construct(public Colleague $colleague, public CarbonImmutable $date) {}
}
