<?php

declare(strict_types=1);

namespace App\Livewire\Audit\Support;

use App\Livewire\Audit\Index;
use App\Platform\Audit\AuditRecords;
use App\Platform\Transfer\CsvExport;
use App\Platform\Transfer\TableExport;
use App\Support\Access;
use App\Support\ManilaClock;
use Generator;

/**
 * The Audit Trail as CSV: the entries the table shows — the latest
 * {@see Index::LIMIT}, all_data + AUDIT only (anyone else exports an empty
 * file, as they see an empty table), filtered and sorted as on screen
 * ({@see AuditRows}). Timestamps are Manila wall-clock, as displayed.
 * Export only: the trail is append-only and is never imported.
 */
final class AuditExport implements TableExport
{
    public function export(Access $actor, array $query): CsvExport
    {
        abort_if($actor->userId === null, 403);
        $filters = [
            'q' => $query['q'] ?? '',
            'action' => ($query['action'] ?? '') !== '' ? (string) $query['action'] : 'ALL',
            'entity' => ($query['entity'] ?? '') !== '' ? (string) $query['entity'] : 'ALL',
            'actor' => ($query['actor'] ?? '') !== '' ? (string) $query['actor'] : 'ALL',
            'sort' => in_array($query['sort'] ?? '', ['timestamp', 'actor', 'action', 'entity'], true) ? (string) $query['sort'] : 'timestamp',
            'dir' => ($query['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc',
        ];

        $rows = static function () use ($actor, $filters): Generator {
            $entries = app(AuditRecords::class)->latest($actor, Index::LIMIT);
            foreach (AuditRows::arrange($entries, $filters['q'], $filters['action'], $filters['entity'], $filters['actor'], $filters['sort'], $filters['dir']) as $entry) {
                yield [
                    $entry->created_at->setTimezone(ManilaClock::TZ)->format('Y-m-d H:i:s'),
                    Index::actorLabel($entry), $entry->action, $entry->entity ?? '', $entry->details ?? '',
                ];
            }
        };

        return new CsvExport('audit-trail-'.ManilaClock::todayKey().'.csv', ['Timestamp', 'Actor', 'Action', 'Entity', 'Details'], $rows);
    }
}
