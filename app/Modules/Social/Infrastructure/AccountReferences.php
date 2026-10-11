<?php

declare(strict_types=1);

namespace App\Modules\Social\Infrastructure;

use App\Models\ChatMessage;
use App\Models\CommunityComment;
use App\Models\CommunityPost;
use App\Platform\Retention\AccountReferenceSource;

/**
 * An account that has posted, commented or sent a message is attributed on
 * those records, so it is deactivated rather than deleted, like an account
 * that has commented on a task.
 */
final class AccountReferences implements AccountReferenceSource
{
    public function references(int|string $accountId): bool
    {
        return CommunityPost::query()->where('author_id', $accountId)->exists()
            || CommunityComment::query()->where('author_id', $accountId)->exists()
            || ChatMessage::query()->where('sender_id', $accountId)->exists();
    }
}
