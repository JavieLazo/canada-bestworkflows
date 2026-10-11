<?php

declare(strict_types=1);

namespace App\Platform\Transfer;

use App\Support\Access;

/**
 * The port a module implements to accept a CSV into one of its tables.
 *
 * An implementation is a business command: `row()` saves ONE row through
 * the owner's existing create/update operations, which keep their own
 * validation, authorization and audit. It never writes around them, never
 * deletes, and throws a RuntimeException whose message names what is wrong
 * with the row. {@see ImportRunner} runs every row inside one transaction and
 * decides whether any of it is kept.
 *
 * `$context` is the record a table lives under (a project for its tasks), or
 * "". It reaches the dialog as a locked property, and every method still
 * re-checks it.
 */
interface TableImport
{
    public function layout(Access $actor, string $context): ImportLayout;

    /**
     * Whether the dialog is offered at all. The runner asks again, and every
     * row() opens with it, so a direct call without the runner is refused too.
     */
    public function allows(Access $actor, string $context): bool;

    public function row(Access $actor, string $context, ImportRow $row): ImportOutcome;
}
