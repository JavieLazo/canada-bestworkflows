<?php

declare(strict_types=1);

namespace App\Platform\Notifications;

use App\Support\Access;

/** Minimal recipient scope for the scheduled sweep, not an account model. */
final readonly class ReminderRecipient
{
    public function __construct(public Access $actor) {}
}
