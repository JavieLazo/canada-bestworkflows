<?php

declare(strict_types=1);

namespace App\Platform\Transfer;

/**
 * What one imported row did. UNCHANGED exists so re-importing an untouched
 * export neither calls the owner's command nor writes an audit row per line.
 */
enum ImportOutcome: string
{
    case CREATED = 'created';
    case UPDATED = 'updated';
    case UNCHANGED = 'unchanged';
}
