<?php

declare(strict_types=1);

namespace App\Platform\Audit;

use App\Domain\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Support\Access;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/** Approved critical writes must commit their audit on the same connection or roll back. */
final class RequiredAudit
{
    public static function record(Access $actor, AuditEvent|string $action, string $entity, string|int $entityId, string $details): void
    {
        $value = $action instanceof AuditEvent ? $action->value : $action;
        try {
            $log = new AuditLog;
            $connection = $log->getConnection();
            if ($connection !== DB::connection() || $connection->transactionLevel() < 1 || ! $actor->isSignedIn()) {
                throw new RuntimeException('Required audit needs the signed-in actor and the business transaction.');
            }
            $log->fill(['action' => $value, 'entity' => $entity, 'entity_id' => (string) $entityId, 'details' => $details]);
            $log->actor_id = (int) $actor->userId;
            $log->actor_name = mb_substr(trim($actor->fullName) === '' ? 'system' : $actor->fullName, 0, 200);
            if (! $log->save()) {
                throw new RuntimeException('Required audit insert was refused.');
            }
        } catch (Throwable $error) {
            Log::error('[audit] required write failed', ['action' => $value, 'entity' => $entity, 'error' => $error->getMessage()]);
            throw new RuntimeException('This change was not saved because its audit record could not be recorded. Please try again.', 0, $error);
        }
    }
}
