<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application;

use App\Domain\Enums\ModuleCode;
use App\Domain\Notifications\Notifier;
use App\Models\ProjectClient;
use App\Models\ProjectTask;
use App\Modules\WorkManagement\Application\Queries\ClientPeople;
use App\Modules\WorkManagement\Domain\Tasks\TaskSubmissionPolicy;
use App\Modules\WorkManagement\Infrastructure\WorkScope;
use App\Platform\Notifications\NotificationRecipients;
use App\Support\Access;
use Illuminate\Support\Facades\Log;
use Throwable;

final class WorkNotifications
{
    /** @param iterable<?string> $employees
     * @param  iterable<int|string|null>  $except
     */
    public static function employees(Access $actor, ProjectTask $task, iterable $employees, iterable $except, string $kind, string $title, ?string $body = null, ?string $href = null): void
    {
        Notifier::employees($employees, $except, $kind, $title, $body, self::href($task, $href), self::allowed($actor, $task));
    }

    public static function account(Access $actor, ProjectTask $task, int|string|null $userId, string $kind, string $title, ?string $body = null, ?string $href = null): void
    {
        try {
            foreach (app(NotificationRecipients::class)->accounts($userId === null ? [] : [(int) $userId]) as $recipient) {
                if (self::readsTask($recipient, $task)) {
                    Notifier::account($userId, $kind, $title, $body, self::href($task, $href, $recipient));
                }
            }
        } catch (Throwable $e) {
            Log::error('[projects] notification recipient failed', ['error' => $e->getMessage()]);
        }
    }

    /** @return list<int> Accounts already told, so a crew place cannot duplicate the notice. */
    public static function submitted(Access $actor, ProjectTask $task, ?string $sharedNote = null): array
    {
        $told = [];
        try {
            $task = $task->fresh(['project', 'assignments']);
            if ($task === null || $task->project === null) {
                return [];
            }
            $owner = $task->project->client_id === null ? null : ProjectClient::query()->whereKey($task->project->client_id)->first(['owner_id'])?->owner_id;
            $ids = array_values(array_unique(array_map(intval(...), array_filter([$task->assigned_by, $task->project->manager_id, $owner]))));
            $individual = TaskSubmissionPolicy::isIndividual($task->submission_mode);
            $label = trim($task->title);
            $label = $label === '' || $label === WorkRules::taskLabel($task) ? WorkRules::taskLabel($task) : $label.' ('.$task->code.')';
            foreach (app(NotificationRecipients::class)->accounts($ids) as $recipient) {
                if ($recipient->userId === $actor->userId || ! self::readsTask($recipient, $task)
                    || ($recipient->userId !== (string) $task->assigned_by && ! WorkRules::reviewsTask($recipient, $task))) {
                    continue;
                }
                $title = $individual ? WorkRules::actorName($task, $actor).' submitted a response on '.$label
                    : $label.' submitted for review';
                Notifier::account($recipient->userId, $individual ? 'TASK_RESPONSE_SUBMITTED' : 'TASK_SUBMITTED', $title,
                    $individual ? $task->title : $sharedNote, self::href($task, '/projects', $recipient));
                $told[] = (int) $recipient->userId;
            }
        } catch (Throwable $e) {
            Log::error('[projects] review notification failed', ['error' => $e->getMessage()]);
        }

        return $told;
    }

    private static function readsTask(Access $recipient, ProjectTask $task): bool
    {
        return $recipient->hasModule(ModuleCode::PROJECTS, ModuleCode::TASKS)
            && WorkScope::tasks($recipient)->whereKey($task->id)->exists();
    }

    private static function href(ProjectTask $task, ?string $href, ?Access $recipient = null): ?string
    {
        if ($href === '/my-tasks') {
            return '/my-tasks?task='.$task->id;
        }
        if ($href === '/projects' || $href === '/projects/'.$task->project_id) {
            return $recipient !== null && ! $recipient->hasModule(ModuleCode::PROJECTS)
                ? '/my-tasks?task='.$task->id : '/projects/'.$task->project_id.'?task='.$task->id;
        }

        return $href;
    }

    /** @return list<int>|null */
    private static function allowed(Access $actor, ProjectTask $task): ?array
    {
        try {
            $project = $task->project;
            if ($project === null) {
                return [];
            }
            if ($project->client_id === null) {
                return null;
            }

            return array_values(app(ClientPeople::class)->accounts($actor, $project->client_id)->pluck('id')->all());
        } catch (Throwable $e) {
            Log::error('[projects] notification scope failed', ['error' => $e->getMessage()]);

            return [];
        }
    }
}
