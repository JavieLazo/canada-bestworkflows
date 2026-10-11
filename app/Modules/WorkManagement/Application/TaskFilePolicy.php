<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application;

use App\Domain\Projects\TaskPolicy;
use App\Modules\WorkManagement\Domain\Tasks\TaskSubmissionPolicy;
use App\Platform\Files\AttachmentUsage;
use App\Platform\Files\UploadLimits;
use App\Support\HrisFormat;

/** Shared limits and status rules for task evidence; existing configuration keys stay stable. */
final class TaskFilePolicy
{
    public const int MAX_FILE_BYTES = 2_000_000;

    public const int STORAGE_CEILING_BYTES = 1_000_000_000;

    public const string FILE_DIR = 'task-files';

    public const array BRIEF_EDITABLE_STATUSES = ['PENDING', 'IN_PROGRESS', 'RETURNED'];

    public const array SUBMISSION_EDITABLE_STATUSES = ['IN_PROGRESS', 'RETURNED'];

    /**
     * Whether an assignee may still attach or delete their own evidence. A
     * GROUP task (or one with no mode) keeps the task-level rule; on an
     * INDIVIDUAL task the evidence follows the member's own response —
     * editable exactly while that response could still be handed in, so it
     * locks under review and once approved. An unrecognised mode edits
     * nothing. Commands and screens must both ask this rule, never re-derive
     * it, so a button never offers what the server refuses.
     */
    public static function submissionEditable(?string $mode, string $taskStatus, ?string $memberStatus): bool
    {
        return match ($mode) {
            null, TaskPolicy::MODE_GROUP => in_array($taskStatus, self::SUBMISSION_EDITABLE_STATUSES, true),
            TaskPolicy::MODE_INDIVIDUAL => TaskSubmissionPolicy::memberCanMove($taskStatus, $memberStatus, 'SUBMITTED'),
            default => false,
        };
    }

    /**
     * The refusal for evidence submissionEditable() keeps locked. On an
     * INDIVIDUAL task in progress the lock is the member's own response;
     * anywhere else it is the task's status.
     */
    public static function lockedMessage(?string $mode, string $taskStatus): string
    {
        return TaskSubmissionPolicy::isIndividual($mode) && $taskStatus === 'IN_PROGRESS'
            ? 'Your evidence is locked while your response is under review or approved.'
            : 'Task evidence is locked in this status.';
    }

    public static function humanSize(int $bytes): string
    {
        return HrisFormat::bytes($bytes);
    }

    public static function maxFileBytes(): int
    {
        return UploadLimits::maxFileBytes();
    }

    public static function storageCeilingBytes(): int
    {
        return UploadLimits::storageCeilingBytes();
    }

    public static function attachmentRejection(string $name, int $size): ?string
    {
        if ($size > self::maxFileBytes()) {
            return sprintf(
                '%s is %s — the limit is %s per file.',
                $name,
                self::humanSize($size),
                self::humanSize(self::maxFileBytes()),
            );
        }

        return null;
    }

    public static function storageCeilingError(int $incomingBytes): ?string
    {
        $used = AttachmentUsage::bytes();

        if ($used + $incomingBytes > self::storageCeilingBytes()) {
            return 'The file store is full. Delete old attachments before uploading more.';
        }

        return null;
    }
}
