<?php

declare(strict_types=1);

namespace App\Platform\Transfer;

use Closure;

/**
 * Every table that can be exported or imported, by key ("records.holidays").
 *
 * The composition root (ModulesServiceProvider) supplies both closed lists;
 * a key arrives as a route default or a locked Livewire property and only
 * ever selects an entry, never a class. The factories are lazy so a
 * download builds one exporter, not all of them.
 */
final class TableTransfers
{
    /**
     * @param  array<string, Closure(): TableExport>  $exports
     * @param  array<string, Closure(): TableImport>  $imports
     */
    public function __construct(
        private readonly array $exports,
        private readonly array $imports,
    ) {}

    public function export(string $table): TableExport
    {
        abort_unless(isset($this->exports[$table]), 404);

        return ($this->exports[$table])();
    }

    public function import(string $table): TableImport
    {
        abort_unless(isset($this->imports[$table]), 404);

        return ($this->imports[$table])();
    }

    public function exports(string $table): bool
    {
        return isset($this->exports[$table]);
    }

    public function imports(string $table): bool
    {
        return isset($this->imports[$table]);
    }

    /** @return list<string> */
    public function importKeys(): array
    {
        return array_keys($this->imports);
    }
}
