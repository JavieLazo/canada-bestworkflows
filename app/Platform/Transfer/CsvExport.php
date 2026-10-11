<?php

declare(strict_types=1);

namespace App\Platform\Transfer;

use Closure;
use Generator;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * A table's CSV, resolved but not yet read: the filename, the header, and
 * the rows as a generator {@see CsvDownload} drains inside one snapshot.
 *
 * Whoever builds one has already authorized the actor and every route or
 * filter value — a download cannot answer 403 once its first bytes are out.
 */
final readonly class CsvExport
{
    /**
     * @param  list<string>  $headers
     * @param  Closure(): iterable<array<int, mixed>>  $rows
     */
    public function __construct(
        public string $filename,
        public array $headers,
        public Closure $rows,
    ) {}

    /** The header alone — the import template — under its own filename. */
    public function template(): self
    {
        return new self(
            (string) preg_replace('/(-\d{4}-\d{2}-\d{2})?\.csv$/', '-template.csv', $this->filename),
            $this->headers,
            static fn (): array => [],
        );
    }

    /**
     * Every item of a paginated owner query, page after page, so an export
     * reads the same scoped, filtered, ordered rows as the table without
     * holding them all at once. 100 is QueryPage's ceiling.
     *
     * @template T
     *
     * @param  Closure(int $page, int $perPage): LengthAwarePaginator<int, T>  $page
     * @return Generator<int, T>
     */
    public static function pages(Closure $page): Generator
    {
        $number = 1;
        do {
            $batch = $page($number++, 100);
            foreach ($batch->items() as $item) {
                yield $item;
            }
        } while ($batch->hasMorePages());
    }
}
