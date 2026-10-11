<?php

declare(strict_types=1);

namespace App\Platform\Records;

use App\Platform\Transfer\ImportLayout;
use App\Platform\Transfer\ImportOutcome;
use App\Platform\Transfer\ImportRow;
use App\Platform\Transfer\TableImport;
use App\Support\Access;
use Illuminate\Database\UniqueConstraintViolationException;
use RuntimeException;

/**
 * A reference list read back from CSV. Every row goes through
 * {@see ReferenceCatalog::save()} — the same validation, code minting and
 * audit row as the Add/Edit dialog, behind the same all_data + RECORDS
 * gate. The ID is the one the list shows (`code`): blank adds an entry with
 * the next code, a known ID renames that entry, an unknown ID is refused
 * rather than minted, because codes are the system's to assign.
 */
final class CatalogImport implements TableImport
{
    /** @param array{nameLabel: string, showDescription: bool, noun: string, plural: string} $list ReferenceListPage::LISTS[$slug] */
    public function __construct(
        private readonly string $slug,
        private readonly array $list,
    ) {}

    public function layout(Access $actor, string $context): ImportLayout
    {
        return new ImportLayout(
            $this->list['plural'],
            'ID',
            CatalogExport::columns($this->list),
            $this->list['showDescription'] ? [] : ['Descriptions are not part of this list\'s file and stay as they are.'],
        );
    }

    public function allows(Access $actor, string $context): bool
    {
        return app(ReferenceCatalogs::class)->for($this->slug)->mayManage($actor);
    }

    public function row(Access $actor, string $context, ImportRow $row): ImportOutcome
    {
        abort_unless($this->allows($actor, $context), 403);
        $catalog = app(ReferenceCatalogs::class)->for($this->slug);
        $code = $row->identifier('ID');
        $name = $row->text($this->list['nameLabel']);

        $existing = null;
        if ($code !== '') {
            $existing = $catalog->findByCode($actor, $code);
            if ($existing === null) {
                throw new RuntimeException("ID {$code} is not on the list. Leave ID blank to add a new {$this->list['noun']}.");
            }
        }
        $description = $this->list['showDescription'] ? $row->text('Description') : (string) ($existing->description ?? '');

        if ($existing !== null && $existing->name === $name && (string) ($existing->description ?? '') === $description) {
            return ImportOutcome::UNCHANGED;
        }

        try {
            $catalog->save($actor, $existing?->id, $name, $description);
        } catch (UniqueConstraintViolationException) {
            throw new RuntimeException("\"{$name}\" is already on the list.");
        }

        return $existing === null ? ImportOutcome::CREATED : ImportOutcome::UPDATED;
    }
}
