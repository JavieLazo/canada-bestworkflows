<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\Enums\AuditEvent;
use App\Models\AuditLog;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The audit trail — the port of `src/lib/api/audit.ts`.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE ACTOR COMES FROM THE SESSION. ALWAYS.
 * ─────────────────────────────────────────────────────────────────────────────
 * The reference states the reason in `src/lib/api/audit.ts:1-11`:
 *
 *   "The demo composed its entries in the browser, which made the trail exactly
 *    as trustworthy as the client — a tampered request could name any actor it
 *    liked, or skip the entry entirely. Here the actor comes from the session
 *    and the caller cannot influence it."
 *
 * Which is why {@see self::record()} takes an {@see Access} — the request-scoped
 * object built from the session cookie and the database — and NOT a name, an id,
 * or anything reachable from a request body or a Livewire public property. There
 * is no overload that accepts an actor id. Do not add one.
 *
 * The demo's `src/lib/audit.ts` is not ported. It is inert with a server
 * (`if (!DEMO_MODE) return;` on its first line) and exists only so ~40
 * browser call sites do not each grow a mode check. Its vocabulary IS ported —
 * {@see AuditEvent} carries the union of both — but its writes are not.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * FAIL-SOFT: THIS NEVER THROWS INTO THE CALLER
 * ─────────────────────────────────────────────────────────────────────────────
 * "Still fire-and-forget: a failed audit write is logged, never raised. The user
 * action it describes has already happened, and failing the request afterwards
 * would leave them told it didn't work when it did."
 *
 * So every path here is inside a `try`/`catch (Throwable)` that logs and
 * returns. A caller can treat `AuditService::record(...)` as a statement that
 * cannot fail, exactly as the reference's `await audit(...)` could.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * SYNCHRONOUS. NEVER A QUEUED JOB.
 * ─────────────────────────────────────────────────────────────────────────────
 * `QUEUE_CONNECTION=sync` on the target host, and there is no worker process —
 * one cron line runs `schedule:run`, nothing runs `queue:work`. A queued audit
 * job under the sync driver executes INLINE and RETHROWS into the user's
 * request, which inverts the entire design above: the one thing that must never
 * break a request would become the thing most likely to. There is no job class
 * here, and there must not be one.
 *
 * The insert is nevertheless kept out of the caller's business transaction: if
 * one is open on the connection, the write is deferred to `afterCommit`, which
 * still runs synchronously in this same request, immediately after COMMIT. Two
 * consequences worth knowing:
 *
 *   - a business transaction that ROLLS BACK writes no audit row, which is
 *     correct — nothing happened to record;
 *   - a long audit write cannot hold row locks inside a finalize.
 *
 * Under `RefreshDatabase` the whole test runs inside a transaction; Laravel's
 * testing transactions manager fires deferred callbacks at level 1 for exactly
 * this reason, so audit rows are still visible to assertions.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY THIS IS HAND-WRITTEN AND NOT A PACKAGE
 * ─────────────────────────────────────────────────────────────────────────────
 * `owen-it/laravel-auditing` records attribute diffs off model events. This
 * trail records curated sentences composed at the call site ("Sick Leave
 * approved for Liza Domingo"), and six of its events — the sign-in family, the
 * bootstrap, the sign-out — have no model behind them for an observer to hook.
 *
 * @see AuditEvent  the vocabulary, and the demo/server divergences it resolves
 * @see AuditLog  append-only, no FK to the actor
 */
final class AuditService
{
    /**
     * Record something an identified actor did.
     *
     * `actor_name` is read off the session object here rather than passed in, so
     * that no call site can vary it — the reference has the demo writing an
     * email into that column and the server writing a full name into it for
     * LOGIN_SUCCESS, so its audit screen shows both. Here it is the actor's
     * FULL NAME wherever a session exists.
     *
     * An {@see Access::forGuest()} actor is legal and lands as `actor_id = null`
     * with `actor_name = 'System'`, which is the port of the demo's
     * `entry.actorName ?? me?.email ?? "system"` tail.
     */
    public static function record(
        Access $actor,
        AuditEvent|string $action,
        ?string $entity = null,
        string|int|null $entityId = null,
        ?string $details = null,
    ): void {
        // TODO(schema-split): `users.id` is a bigint auto-increment left behind by
        // the Laravel starter kit, while every other table in this port keys on a
        // ULID char(26) — and `Access::userId` carries the id as a string because
        // everything else it holds is one. `audit_logs.actor_id` follows the column
        // it points at, so the string is narrowed back to an int here. Unifying the
        // two id shapes is an open architectural decision; do not migrate either
        // column to settle it from this file.
        self::write(
            $actor->userId === null ? null : (int) $actor->userId,
            $actor->fullName,
            $action,
            $entity,
            $entityId,
            $details,
        );
    }

    /**
     * Record something that happened before anyone was signed in.
     *
     * LOGIN_FAILED, LOGIN_BLOCKED, BOOTSTRAP_ADMIN — `actor_name` carries
     * whatever identified the attempt, "usually the email that was typed".
     *
     * PORTED QUIRK: LOGIN_SUCCESS goes through here too
     * (`src/app/api/auth/login/route.ts:97-103`), even though the account has
     * just been positively identified and its id is in hand. So a successful
     * sign-in is recorded with `actor_id = NULL` and the account's full name in
     * `actor_name`, while `entity_id` does carry the account id. That looks like
     * an oversight and it is preserved deliberately: the two systems have to
     * reconcile row for row, and the account is still identifiable from
     * `entity_id`.
     */
    public static function anonymous(
        string $actorName,
        AuditEvent|string $action,
        ?string $entity = null,
        string|int|null $entityId = null,
        ?string $details = null,
    ): void {
        self::write(null, $actorName, $action, $entity, $entityId, $details);
    }

    /**
     * The single write path. Everything above funnels through here so there is
     * exactly one place that can touch the actor columns.
     */
    private static function write(
        // `int|null`, not `string|null`: `audit_logs.actor_id` is a bigint because
        // `users.id` is — see the TODO in `record()`.
        ?int $actorId,
        string $actorName,
        AuditEvent|string $action,
        ?string $entity,
        string|int|null $entityId,
        ?string $details,
    ): void {
        $value = $action instanceof AuditEvent ? $action->value : $action;

        // `?? "system"`, from src/lib/audit.ts:35. `audit_logs.actor_name` is
        // NOT NULL, and a blank name in the trail names nobody.
        $name = trim($actorName) === '' ? 'system' : $actorName;

        // `actor_name` is varchar(200) here against an unbounded Postgres text
        // in the source, and this method must not be the reason a LOGIN_FAILED
        // row goes missing. Clamp rather than let MySQL strict mode reject it.
        $name = mb_substr($name, 0, 200);

        $insert = static function () use ($actorId, $name, $value, $entity, $entityId, $details): void {
            try {
                $log = new AuditLog;

                // Only these four are fillable. The two actor columns are set
                // directly, on purpose — mass assignment is how a request body
                // would reach them.
                $log->fill([
                    'action' => $value,
                    'entity' => $entity,
                    'entity_id' => $entityId === null ? null : (string) $entityId,
                    'details' => $details,
                ]);

                // No foreign key, and no `belongsTo`: the row has to stay
                // readable after the account that wrote it is deleted.
                $log->actor_id = $actorId;
                $log->actor_name = $name;

                // `created_at` is left to Eloquent (UPDATED_AT is null on the
                // model, so it fills created_at on insert and never emits an
                // UPDATE). config/app.php is UTC, and BaseModel writes the
                // milliseconds the DATETIME(3) column declares.
                $log->save();
            } catch (Throwable $e) {
                // Swallowed on purpose. Never rethrow — see the class docblock.
                Log::error('[audit] write failed', [
                    'action' => $value,
                    'entity' => $entity,
                    'error' => $e->getMessage(),
                ]);
            }
        };

        try {
            // transactionLevel() must be read on the SAME connection the insert
            // will use, or a business transaction on a second connection would
            // be missed.
            $connection = self::connection();

            if ($connection->transactionLevel() > 0) {
                $connection->afterCommit($insert);

                return;
            }
        } catch (Throwable $e) {
            // A connection that cannot be resolved, or a transactions manager
            // that was never set (afterCommit throws RuntimeException in that
            // case). Fall through and write immediately; $insert has its own
            // catch, so this still cannot escape.
            Log::error('[audit] deferral failed, writing inline', [
                'action' => $value,
                'error' => $e->getMessage(),
            ]);
        }

        $insert();
    }

    /** The connection `audit_logs` actually lives on — usually the default. */
    private static function connection(): Connection
    {
        return DB::connection((new AuditLog)->getConnectionName());
    }
}
