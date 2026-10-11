<?php

declare(strict_types=1);

namespace App\Modules\Social\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Notifications\Notifier;
use App\Models\CommunityGroupMember;
use App\Modules\Social\Application\Attachments;
use App\Modules\Social\Application\SocialRules;
use App\Modules\Social\Domain\Vocabulary;
use App\Support\Access;
use App\Support\AuditService;
use App\Support\Realtime;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * A group's name, description, privacy and photo, changed by someone who
 * runs it (its owner or a moderator). Making a private group public lets in
 * everyone who was waiting, since anyone could walk in now anyway, and tells
 * them. Audited.
 */
final class UpdateGroup
{
    public function handle(Access $actor, string $groupId, string $name, string $about, bool $private, ?UploadedFile $photo = null, bool $removePhoto = false): void
    {
        $group = SocialRules::runGroup($actor, $groupId, 'Only the group\'s owner or its moderators can change it.');

        $name = SocialRules::groupName($name, $group->id);
        $about = SocialRules::text($about, SocialRules::MAX_GROUP_ABOUT, 'A description');
        $opening = $group->is_private && ! $private;
        $oldPhoto = $group->photo_path;

        $group->fill(['name' => $name, 'description' => $about === '' ? null : $about, 'is_private' => $private]);

        $picture = $photo !== null ? Attachments::storeAvatar($photo, 'community') : null;
        if ($picture !== null) {
            $group->photo_path = $picture['path'];
            $group->photo_size = $picture['size'];
        } elseif ($removePhoto) {
            $group->photo_path = null;
            $group->photo_size = null;
        }

        if (! $group->isDirty()) {
            return;
        }

        $waiting = $opening
            ? array_map('intval', CommunityGroupMember::query()->where('group_id', $group->id)->where('status', Vocabulary::REQUESTED)->pluck('user_id')->all())
            : [];

        try {
            DB::transaction(static function () use ($group, $waiting): void {
                $group->save();

                if ($waiting !== []) {
                    CommunityGroupMember::query()
                        ->where('group_id', $group->id)
                        ->where('status', Vocabulary::REQUESTED)
                        ->update(['status' => Vocabulary::MEMBER, 'updated_at' => now()]);
                }
            });
        } catch (Throwable $error) {
            Attachments::discard($picture !== null ? [$picture['path']] : []);
            throw $error;
        }

        // The old photo goes once the new one is safely in place.
        if ($oldPhoto !== null && $oldPhoto !== $group->photo_path) {
            Attachments::discard([$oldPhoto]);
        }

        foreach ($waiting as $userId) {
            Notifier::account($userId, 'COMMUNITY_JOIN', "You're in {$group->name}", 'The group is public now, so your request went through.', '/community?group='.$group->id);
        }

        AuditService::record($actor, AuditEvent::COMMUNITY_GROUP_UPDATED, 'community_groups', $group->id, "Group {$name} changed".($private ? ' (private)' : ' (public)'));
        Realtime::announce([Realtime::community()]);
    }
}
