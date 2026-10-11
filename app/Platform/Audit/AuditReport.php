<?php

declare(strict_types=1);

namespace App\Platform\Audit;

use App\Domain\Enums\ModuleCode;
use App\Models\AuditLog;
use App\Platform\Reports\ReportCriteria;
use App\Platform\Reports\ReportDocument;
use App\Support\Access;
use App\Support\ManilaClock;

/** Period reports filter before limiting, so the latest-500 inbox window cannot truncate history. */
final class AuditReport
{
    public function generate(Access $actor, ReportCriteria $criteria): ReportDocument
    {
        abort_unless($actor->hasModule(ModuleCode::REPORTS) && $actor->mayReadAllData(ModuleCode::AUDIT), 403);
        abort_unless($criteria->type === 'audit-logs', 422);

        $entries = AuditLog::query()
            ->where('created_at', '>=', ManilaClock::instant($criteria->from.'T00:00:00')->utc())
            ->where('created_at', '<', ManilaClock::instant($criteria->to.'T00:00:00')->addDay()->utc())
            ->orderByDesc('created_at')->orderByDesc('id')
            ->limit(ReportDocument::MAX_ROWS + 1)->get();
        ReportDocument::guardSize($entries->count());

        return new ReportDocument('Audit Logs', ['Timestamp (Manila)', 'Actor', 'Action', 'Entity', 'Record ID', 'Details'],
            array_values($entries->map(static fn (AuditLog $entry): array => [
                $entry->created_at->setTimezone(ManilaClock::TZ)->format('Y-m-d H:i:s.v'),
                $entry->actor_name ?: 'System', $entry->action, $entry->entity ?? '', $entry->entity_id ?? '', $entry->details ?? '',
            ])->all()),
            ['All audit entries in the selected Manila calendar dates, newest first. Timestamps retain millisecond precision. The audit trail is append-only.'], [], [
                ['label' => 'Audit entries', 'value' => (string) $entries->count()],
                ['label' => 'Actors', 'value' => (string) $entries->pluck('actor_id')->unique()->count()],
                ['label' => 'Action types', 'value' => (string) $entries->pluck('action')->unique()->count()],
                ['label' => 'Entity types', 'value' => (string) $entries->pluck('entity')->filter()->unique()->count()],
            ]);
    }
}
