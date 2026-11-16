<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Queries;

use App\Domain\Enums\ModuleCode;
use App\Modules\IdentityAccess\Contracts\AccountLabel;
use App\Modules\IdentityAccess\Contracts\ModuleAccounts;
use App\Modules\IdentityAccess\Contracts\ProjectAccounts;
use App\Modules\Workforce\Contracts\AssignmentEmployees;
use App\Modules\Workforce\Contracts\DirectoryEmployee;
use App\Modules\WorkManagement\Infrastructure\ClientScope;
use App\Support\Access;
use App\Support\EmployeeName;
use Illuminate\Support\Collection;

final class ClientPeople
{
    /** @return Collection<int, AccountLabel> */
    public function accounts(Access $actor, string $clientId): Collection
    {
        abort_unless(ClientScope::allowsWork($actor, $clientId), 403);
        $client = ClientScope::workClients($actor)->whereKey($clientId)->firstOrFail();
        $ids = $client->members()->pluck('user_id')->map(static fn ($id): int => (int) $id)->all();

        return app(ProjectAccounts::class)->participants($actor, array_values([$client->owner_id, ...$ids]));
    }

    /** @return Collection<int, AccountLabel> */
    public function leaders(Access $actor, string $clientId): Collection
    {
        $accounts = $this->accounts($actor, $clientId);
        $ids = app(ModuleAccounts::class)->filter($actor, ModuleCode::PROJECTS, array_values($accounts->pluck('id')->all()));

        return $accounts->whereIn('id', $ids)->values();
    }

    /** @param list<string>|null $ids
     * @return Collection<int, DirectoryEmployee>
     */
    public function employees(Access $actor, string $clientId, ?array $ids = null, string $search = '', ?string $taskId = null): Collection
    {
        abort_unless(ClientScope::manageable($actor)->whereKey($clientId)->exists(), 403);
        $allowed = $this->accounts($actor, $clientId)->pluck('employee_id')->filter()->unique()->values()->all();
        if ($taskId !== null) {
            $task = app(WorkRecords::class)->task($actor, $taskId);
            abort_unless($task !== null && $task->project?->client_id === $clientId, 403);
            $allowed = array_values(array_unique([...$allowed, ...$task->assigneeIds()]));
        }
        $chosen = $ids === null ? $allowed : array_values(array_intersect($ids, $allowed));

        $terms = preg_split('/\s+/', mb_strtolower(trim($search)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return app(AssignmentEmployees::class)->identities(array_values($chosen))
            ->filter(static function (DirectoryEmployee $employee) use ($terms): bool {
                $text = mb_strtolower(implode(' ', [EmployeeName::display($employee), $employee->employee_code, $employee->employee_id_no ?? '', $employee->position ?? '']));
                foreach ($terms as $term) {
                    if (! str_contains($text, $term)) {
                        return false;
                    }
                }

                return true;
            })->sortBy([['last_name', 'asc'], ['first_name', 'asc']], SORT_NATURAL | SORT_FLAG_CASE)->values();
    }

    /** @param list<string> $ids
     * @return list<string>|string
     */
    public function resolveAssignees(Access $actor, string $clientId, array $ids): array|string
    {
        $eligible = $this->employees($actor, $clientId, $ids)->where('is_active', true)->pluck('id')->values()->all();

        return count($eligible) === count($ids) ? array_values($eligible) : 'Choose active employees with client access for this task.';
    }
}
