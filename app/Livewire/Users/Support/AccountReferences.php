<?php

declare(strict_types=1);

namespace App\Livewire\Users\Support;

use App\Models\User;
use App\Modules\IdentityAccess\Application\AccountRetention;

/**
 * Whether anything still points at a login — the guard that keeps
 * `Users\Index::deleteAccount` from erasing attribution.
 *
 * The `_count` block of DELETE /api/accounts/[id]: notifications, comments,
 * tasks assigned/reviewed, projects managed, runs finalized, requests
 * reviewed, documents added, task files added — plus two the source had no
 * equivalent of. The task-assignee rows handed out
 * (project_task_assignees.assigned_by): that FK nulls on delete, and "who
 * handed this person the task" is exactly the attribution this guard exists
 * to keep. And the individual task responses decided
 * (project_task_submissions.reviewed_by), for the same reason.
 *
 * `audit_logs` is deliberately absent: `actor_name` is denormalised so the
 * trail stays readable after the account is gone (access-control guide).
 *
 * It sits outside `Users\Index` so the screen imports none of those tables'
 * models for one predicate. The checks themselves live with the owners
 * of those tables, behind {@see AccountRetention}: a NEW table that references
 * `users.id` gets its check in its owning module's `AccountReferenceSource`,
 * tagged `account-reference-sources` in ModulesServiceProvider — not here.
 */
final class AccountReferences
{
    private function __construct() {}

    public static function any(User $account): bool
    {
        return app(AccountRetention::class)->any($account->getKey());
    }
}
