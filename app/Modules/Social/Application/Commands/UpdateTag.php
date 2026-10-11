<?php

declare(strict_types=1);

namespace App\Modules\Social\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Models\CommunityTag;
use App\Modules\Social\Application\SocialRules;
use App\Support\Access;
use App\Support\AuditService;
use App\Support\Realtime;
use RuntimeException;

/**
 * Renames or recolours a tag. Moderators only. Posts store the tag's code,
 * so they follow along. Audited.
 */
final class UpdateTag
{
    public function handle(Access $actor, string $tagId, string $name, string $tone): void
    {
        SocialRules::moderator($actor, 'Only moderators can manage tags.');

        $tag = CommunityTag::query()->find($tagId);
        if ($tag === null) {
            throw new RuntimeException('That tag was not found.');
        }

        $name = SocialRules::tagName($name, $tag->id);
        $tone = SocialRules::tone($tone);

        $tag->fill(['name' => $name, 'tone' => $tone]);
        if (! $tag->isDirty()) {
            return;
        }
        $tag->save();

        AuditService::record($actor, AuditEvent::COMMUNITY_TAG_UPDATED, 'community_tags', $tag->id, "Tag {$name} changed");
        Realtime::announce([Realtime::community()]);
    }
}
