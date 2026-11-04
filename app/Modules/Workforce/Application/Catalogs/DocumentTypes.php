<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Catalogs;

use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use App\Platform\Records\ReferenceCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Owns this catalog's persistence while sharing its name/description editor
 * mechanics. `employee_documents.category` keeps the type's NAME, so a
 * rename is copied onto every document carrying the old one in the renaming
 * transaction (a query-builder update, no model events), and a delete is
 * refused while any document still carries it, counted unscoped.
 */
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

    protected function copyRename(Model $row, string $from, string $to): int
    {
        return self::holders($from)->update(['category' => $to]);
    }

    protected function inUse(string $name): int
    {
        return self::holders($name)->count();
    }

    /** @return Builder<EmployeeDocument> */
    private static function holders(string $name): Builder
    {
        return EmployeeDocument::query()->whereRaw('LOWER(TRIM(category)) = ?', [mb_strtolower(trim($name))]);
    }
}
