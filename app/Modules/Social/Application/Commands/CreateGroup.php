<?php

declare(strict_types=1);

namespace App\Modules\Social\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Domain\Notifications\Notifier;
use App\Models\CommunityGroup;
use App\Models\CommunityGroupMember;
use App\Modules\IdentityAccess\Contracts\ModuleAccounts;
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
 * A new Community group. Only company-wide moderators make them.
 * Whoever makes it owns it and is its first member, so it shows up under
 * their groups straight away. It can start with a photo and the people it's
 * for, who are let straight in and told. Audited.
 */
final class CreateGroup
{
    /**
     * @param  list<int>  $memberIds  people to put in it from the start
     * @return string the new group's id
     */
    public function handle(Access $actor, string $name, string $about, bool $private, ?UploadedFile $photo = null, array $memberIds = []): string
    {
        $me = SocialRules::moderator($actor, 'Only moderators can create groups.');

        $name = SocialRules::groupName($name, null);
        $about = SocialRules::text($about, SocialRules::MAX_GROUP_ABOUT, 'A description');
        $others = array_values(array_diff(
            app(ModuleAccounts::class)->filter($actor, ModuleCode::COMMUNITY, array_values(array_unique(array_map('intval', $memberIds)))),
            [$me],
        ));

        $picture = $photo !== null ? Attachments::storeAvatar($photo, 'community') : null;

        try {
            $group = DB::transaction(static function () use ($me, $name, $about, $private, $picture, $others): CommunityGroup {
                $group = new CommunityGroup;
                $group->fill(['name' => $name, 'description' => $about === '' ? null : $about, 'is_private' => $private]);
                $group->created_by = $me;
                $group->photo_path = $picture['path'] ?? null;
                $group->photo_size = $picture['size'] ?? null;
                $group->save();

                foreach ([$me, ...$others] as $userId) {
                    $member = new CommunityGroupMember;
                    $member->fill(['group_id' => $group->id, 'status' => Vocabulary::MEMBER]);
                    $member->user_id = $userId;
                    $member->role = $userId === $me ? Vocabulary::OWNER : Vocabulary::MEMBER;
                    $member->added_by = $userId === $me ? null : $me;
                    $member->save();
                }

                return $group;
            });
        } catch (Throwable $error) {
            Attachments::discard($picture !== null ? [$picture['path']] : []);
            throw $error;
        }

        foreach ($others as $userId) {
            Notifier::account($userId, 'COMMUNITY_GROUP_ADDED', $actor->fullName.' added you to '.$name, $about !== '' ? SocialRules::preview($about) : null, '/community?group='.$group->id);
        }

        AuditService::record($actor, AuditEvent::COMMUNITY_GROUP_CREATED, 'community_groups', $group->id, "Group {$name} created".($private ? ' (private)' : '').($others !== [] ? ' with '.(count($others) + 1).' members' : ''));
        Realtime::announce([Realtime::community()]);

        return $group->id;
    }
}
