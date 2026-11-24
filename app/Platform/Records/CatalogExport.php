<?php

declare(strict_types=1);

namespace App\Platform\Records;

use App\Platform\Transfer\CsvExport;
use App\Platform\Transfer\TableExport;
use App\Support\Access;
use App\Support\LocalClock;
use Generator;

/**
 * One reference list as CSV: the columns the list shows — ID, the name,
 * and Description where the list shows one — searched and sorted as on
 * screen, read through {@see ReferenceCatalog::page()}. The same header is
 * what {@see CatalogImport} reads back. Only the lists the composition root
 * registers have one: Leave Settings and Payroll Deductions.
 */
final class CatalogExport implements TableExport
{
    /** @param array{nameLabel: string, showDescription: bool, noun: string, plural: string} $list ReferenceListPage::LISTS[$slug] */
    public function __construct(
        private readonly string $slug,
        private readonly array $list,
    ) {}

    public function export(Access $actor, array $query): CsvExport
    {
        abort_if($actor->userId === null, 403);
        $catalog = app(ReferenceCatalogs::class)->for($this->slug);
        $search = $query['q'] ?? '';
        $sort = in_array($query['sort'] ?? '', ['id', 'name'], true) ? (string) $query['sort'] : 'name';
        $direction = ($query['dir'] ?? '') === 'desc' ? 'desc' : 'asc';
        $described = $this->list['showDescription'];

        $rows = static function () use ($catalog, $actor, $search, $sort, $direction, $described): Generator {
            foreach (CsvExport::pages(static fn (int $page, int $perPage) => $catalog->page($actor, $search, $sort, $direction, $page, $perPage)) as $entry) {
                yield $described ? [$entry->code, $entry->name, $entry->description ?? ''] : [$entry->code, $entry->name];
            }
        };

        return new CsvExport(
            str_replace(' ', '-', $this->list['plural']).'-'.LocalClock::todayKey().'.csv',
            self::columns($this->list),
            $rows,
        );
    }

    /**
     * @param  array{nameLabel: string, showDescription: bool}  $list
     * @return list<string>
     */
    public static function columns(array $list): array
    {
        return $list['showDescription'] ? ['ID', $list['nameLabel'], 'Description'] : ['ID', $list['nameLabel']];
    }
}
