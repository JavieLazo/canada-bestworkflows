<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Application;

use App\Platform\Retention\AccountReferenceSource;

/**
 * Whether anything still points at a login — the guard that keeps
 * `ManageAccounts::delete` from erasing attribution. It answers what the
 * `_count` block of DELETE /api/accounts/[id] answers
 * (src/app/api/accounts/[id]/route.ts:118), across every table EMS
 * attributes to an account.
 *
 * Each owner supplies the checks for its own tables as an
 * {@see AccountReferenceSource}, so this aggregate imports none of their
 * models: a NEW table that references `users.id` gets its check in its owning
 * module's source, tagged `account-reference-sources` in
 * ModulesServiceProvider — never here.
 *
 * The task-assignee rows handed out (`project_task_assignees.assigned_by`)
 * and the individual task responses decided
 * (`project_task_submissions.reviewed_by`) count even though both foreign keys
 * null on delete: "who handed this person the task" and "who decided this
 * response" are exactly the attribution this guard keeps.
 *
 * `audit_logs` is deliberately absent: `actor_id` carries no foreign key and
 * `actor_name` is denormalised, so the trail stays readable after the account
 * is gone (access-control guide).
 */
final class AccountRetention
{
    /** @param iterable<AccountReferenceSource> $sources */
    public function __construct(private readonly iterable $sources) {}

    public function any(int|string $accountId): bool
    {
        foreach ($this->sources as $source) {
            if ($source->references($accountId)) {
                return true;
            }
        }

        return false;
    }
}
