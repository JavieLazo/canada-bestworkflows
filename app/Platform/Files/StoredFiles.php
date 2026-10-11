<?php

declare(strict_types=1);

namespace App\Platform\Files;

interface StoredFiles
{
    /** @return iterable<array{disk: string, path: string}> */
    public function references(): iterable;
}
