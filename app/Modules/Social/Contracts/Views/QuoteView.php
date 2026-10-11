<?php

declare(strict_types=1);

namespace App\Modules\Social\Contracts\Views;

/** The stub a reply shows of the message it answers. */
final readonly class QuoteView
{
    public function __construct(
        /** Who wrote the quoted message, as "… replied to" reads it: a name, "you" or "yourself". */
        public string $name,
        public string $text,
        public bool $photo,
        public bool $removed,
    ) {}
}
