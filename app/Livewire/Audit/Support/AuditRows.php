<?php

declare(strict_types=1);

namespace App\Livewire\Audit\Support;

use App\Livewire\Audit\Index;
use App\Platform\Audit\AuditLabels;
use App\Platform\Audit\AuditRecords;
use App\Platform\ReadModels\AuditLogView as AuditLog;
use Illuminate\Support\Collection;

/**
 * The Audit Trail's filter and sort over the loaded entries (the latest 500,
 * {@see AuditRecords::latest()}), mirroring the source's
 * client-side pipeline (audit-page.tsx:45-71) including its quirks: `sort()`
 * then a whole-list `reverse()` for descending, which puts blanks FIRST on
 * desc. One copy, shared by the table and its CSV export.
 *
 * DELIBERATE DIVERGENCE — the source filtered and sorted Action and Entity by
 * their stored codes; here they go by the labels the table shows
 * ({@see AuditLabels}), so the order and the filter lists read as the column
 * does, and two tables with one label ("User account") are one choice. A filter
 * value that is the stored code still matches, so an export link built from a
 * code keeps working.
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
            $actionLabel = AuditLabels::action($r->action);
            $entityLabel = AuditLabels::entity($r->entity);

            if ($action !== 'ALL' && $actionLabel !== $action && $r->action !== $action) {
                return false;
            }

            if ($entity !== 'ALL' && $entityLabel !== $entity && $r->entity !== $entity) {
                return false;
            }

            if ($actor !== 'ALL' && Index::actorLabel($r) !== $actor) {
                return false;
            }

            if ($needle === '') {
                return true;
            }

            // `[action, entity, actor_name, details].some(...)`, with the labels
            // beside the codes so either spelling finds a row. The search
            // matches the RAW actor_name, not the "System" fallback
            // (audit-page.tsx:52-54).
            foreach ([$actionLabel, $r->action, $entityLabel, $r->entity, $r->actor_name, $r->details] as $v) {
                if (str_contains(mb_strtolower($v ?? ''), $needle)) {
                    return true;
                }
            }

            return false;
        })->values();

        // Timestamps compare as strings the way ISO strings did through compareText.
        $sorted = $visible->sort(static fn (AuditLog $a, AuditLog $b): int => match ($sortKey) {
            'actor' => Index::compareText($a->actor_name, $b->actor_name),
            'action' => Index::compareText(AuditLabels::action($a->action), AuditLabels::action($b->action)),
            'entity' => Index::compareText(AuditLabels::entity($a->entity), AuditLabels::entity($b->entity)),
            default => Index::compareText(
                $a->created_at->format('Y-m-d H:i:s.v'),
                $b->created_at->format('Y-m-d H:i:s.v'),
            ),
        })->values();

        return $sortDir === 'desc' ? $sorted->reverse()->values() : $sorted;
    }
}
