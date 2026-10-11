<?php

declare(strict_types=1);

namespace App\Platform\Transfer;

/**
 * The columns an import reads, as the import dialog explains them.
 *
 * `columns` are the headers the file must carry — the same spellings the
 * table's export writes, so an exported file is its own template. Other
 * export columns (counts, ages, progress) are read-only and ignored. `key`
 * is the column that names an existing row: filled in, the row updates that
 * record; left blank, it adds one. A table whose rows cannot be added from a
 * file (credits and schedules hang off an existing employee) says so in
 * `keyRule`, which replaces that sentence in the dialog.
 */
final readonly class ImportLayout
{
    /**
     * @param  list<string>  $columns
     * @param  list<string>  $notes  table-specific rules the dialog lists
     */
    public function __construct(
        public string $title,
        public string $key,
        public array $columns,
        public array $notes = [],
        public ?string $keyRule = null,
    ) {}
}
