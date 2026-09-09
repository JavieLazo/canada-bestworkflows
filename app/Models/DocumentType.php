<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\IsReferenceList;
use Carbon\CarbonImmutable;

/**
 * Employee-document categories.
 *
 * Seeded with 'Résumé'. Under `utf8mb4_unicode_ci` — which is accent- as well
 * as case-insensitive — 'Resume' is then a duplicate-key error on the `name`
 * unique rather than a new row. That is a deliberate collation trade (the same
 * insensitivity is what replaces the source's three `mode: "insensitive"`
 * modifiers for free), not a bug.
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 */
class DocumentType extends BaseModel
{
    use IsReferenceList;

    /** @var string */
    protected $table = 'document_types';
}
