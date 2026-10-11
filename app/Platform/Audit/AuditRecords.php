<?php

declare(strict_types=1);

namespace App\Platform\Audit;

use App\Domain\Enums\ModuleCode;
use App\Models\AuditLog;
use App\Platform\ReadModels\AuditLogView;
use App\Platform\ReadModels\Snapshot;
use App\Support\Access;
use Illuminate\Support\Collection;

final class AuditRecords
{
    /** @return Collection<int, AuditLogView> */
    public function latest(Access $actor, int $limit = 500): Collection
    {
        return $actor->mayReadAllData(ModuleCode::AUDIT)
            ? AuditLog::query()->orderByDesc('created_at')->limit(max(1, min(500, $limit)))->get()
                ->map(static fn (AuditLog $row): AuditLogView => Snapshot::of($row, AuditLogView::class))
            : collect();
    }
}
