<?php

declare(strict_types=1);

namespace App\Livewire\Audit\Support;

use App\Livewire\Audit\Index;
use App\Platform\Audit\AuditRecords;
use App\Platform\ReadModels\AuditLogView as AuditLog;
use Illuminate\Support\Collection;

/**
 * The Audit Trail's filter and sort over the loaded entries (the latest 500,
 * {@see AuditRecords::latest()}), mirroring the source's
 * client-side pipeline (audit-page.tsx:45-71) including its quirks: `sort()`
 * then a whole-list `reverse()` for descending, which puts blanks FIRST on
 * desc. One copy, shared by the table and its CSV export.
 */
final class AuditRows
{
    /**
     * @param  Collection<int, AuditLog>  $rows
     * @return Collection<int, AuditLog>
     */
    public static function arrange(Collection $rows, string $search, string $action, string $entity, string $actor, string $sortKey, string $sortDir): Collection
    {
        $needle = mb_strtolower(trim($search));

        $visible = $rows->filter(static function (AuditLog $r) use ($needle, $action, $entity, $actor): bool {
            if ($action !== 'ALL' && $r->action !== $action) {
                return false;
            }

            if ($entity !== 'ALL' && $r->entity !== $entity) {
                return false;
            }

            if ($actor !== 'ALL' && Index::actorLabel($r) !== $actor) {
                return false;
            }

            if ($needle === '') {
                return true;
            }

            // `[action, entity, actor_name, details].some(...)` — the search
            // matches the RAW actor_name, not the "System" fallback
            // (audit-page.tsx:52-54).
            foreach ([$r->action, $r->entity, $r->actor_name, $r->details] as $v) {
                if (str_contains(mb_strtolower($v ?? ''), $needle)) {
                    return true;
                }
            }

            return false;
        })->values();

        // Timestamps compare as strings the way ISO strings did through compareText.
        $sorted = $visible->sort(static fn (AuditLog $a, AuditLog $b): int => match ($sortKey) {
            'actor' => Index::compareText($a->actor_name, $b->actor_name),
            'action' => Index::compareText($a->action, $b->action),
            'entity' => Index::compareText($a->entity, $b->entity),
            default => Index::compareText(
                $a->created_at->format('Y-m-d H:i:s.v'),
                $b->created_at->format('Y-m-d H:i:s.v'),
            ),
        })->values();

        return $sortDir === 'desc' ? $sorted->reverse()->values() : $sorted;
    }
}
