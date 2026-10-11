<?php

declare(strict_types=1);

namespace App\Platform\Transfer;

/**
 * What a preview found or an import did. `saved` is true only for a commit
 * whose every row succeeded — otherwise nothing was kept. Row 0 in `errors`
 * is the file itself (unreadable, too many rows); row 1 is its header.
 *
 * Only the first {@see self::LISTED} errors are carried, because the report
 * lives in the dialog's Livewire state; `failed` still counts them all.
 */
final readonly class ImportReport
{
    public const int LISTED = 100;

    /**
     * @param  list<array{row: int, message: string}>  $errors
     * @param  list<string>  $notices
     */
    public function __construct(
        public int $rows = 0,
        public int $created = 0,
        public int $updated = 0,
        public int $unchanged = 0,
        public int $failed = 0,
        public array $errors = [],
        public array $notices = [],
        public bool $saved = false,
    ) {}

    /** A file refused before any row ran. */
    public static function refused(int $row, string ...$messages): self
    {
        return new self(errors: array_map(static fn (string $message): array => ['row' => $row, 'message' => $message], array_values($messages)));
    }

    /**
     * @return array{rows: int, created: int, updated: int, unchanged: int, failed: int,
     *     errors: list<array{row: int, message: string}>, notices: list<string>, saved: bool}
     */
    public function toArray(): array
    {
        return [
            'rows' => $this->rows,
            'created' => $this->created,
            'updated' => $this->updated,
            'unchanged' => $this->unchanged,
            'failed' => $this->failed,
            'errors' => $this->errors,
            'notices' => $this->notices,
            'saved' => $this->saved,
        ];
    }
}
