<?php

declare(strict_types=1);

namespace App\Platform\Records;

/** The composition root supplies a closed list; request input never chooses a model class. */
final class ReferenceCatalogs
{
    /** @param array<string, ReferenceCatalog> $catalogs */
    public function __construct(private readonly array $catalogs) {}

    public function for(string $slug): ReferenceCatalog
    {
        abort_unless(isset($this->catalogs[$slug]), 404);

        return $this->catalogs[$slug];
    }
}
