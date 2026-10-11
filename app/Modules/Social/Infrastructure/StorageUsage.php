<?php

declare(strict_types=1);

namespace App\Modules\Social\Infrastructure;

use App\Models\ChatAttachment;
use App\Models\ChatConversation;
use App\Models\CommunityAttachment;
use App\Models\CommunityGroup;
use App\Platform\Files\StorageUsageSource;
use App\Platform\Files\StoredFiles;

/**
 * Everything Community and Chat keep on the disk (photos and files on posts,
 * comments and messages, and the photos of groups and group chats). It is
 * counted against their own budget (Attachments::storageCeilingBytes), not
 * the one documents and task files share, and its references tell
 * `ems:check-files` which of those files are still wanted.
 */
final class StorageUsage implements StorageUsageSource, StoredFiles
{
    public function bytes(): int
    {
        return (int) CommunityAttachment::query()->sum('size')
            + (int) ChatAttachment::query()->sum('size')
            + (int) CommunityGroup::query()->sum('photo_size')
            + (int) ChatConversation::query()->sum('photo_size');
    }

    public function references(): iterable
    {
        foreach (CommunityAttachment::query()->select(['id', 'path'])->lazy() as $row) {
            yield ['disk' => 'local', 'path' => $row->path];
        }

        foreach (ChatAttachment::query()->select(['id', 'path'])->lazy() as $row) {
            yield ['disk' => 'local', 'path' => $row->path];
        }

        foreach (CommunityGroup::query()->whereNotNull('photo_path')->select(['id', 'photo_path'])->lazy() as $row) {
            yield ['disk' => 'local', 'path' => (string) $row->photo_path];
        }

        foreach (ChatConversation::query()->whereNotNull('photo_path')->select(['id', 'photo_path'])->lazy() as $row) {
            yield ['disk' => 'local', 'path' => (string) $row->photo_path];
        }
    }
}
