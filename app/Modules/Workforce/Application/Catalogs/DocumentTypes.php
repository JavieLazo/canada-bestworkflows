<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Catalogs;

use App\Models\DocumentType;
use App\Platform\Records\ReferenceCatalog;

/** Owns this catalog's persistence while sharing its name/description editor mechanics. */
final class DocumentTypes extends ReferenceCatalog
{
    protected function model(): string
    {
        return DocumentType::class;
    }

    protected function prefix(): string
    {
        return 'DOC';
    }

    protected function noun(): string
    {
        return 'document type';
    }
}
