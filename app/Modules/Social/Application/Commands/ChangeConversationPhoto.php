<?php

declare(strict_types=1);

namespace App\Modules\Social\Application\Commands;

use App\Modules\Social\Application\Attachments;
use App\Modules\Social\Application\ChatLines;
use App\Modules\Social\Application\ChatScope;
use App\Modules\Social\Application\SocialRules;
use App\Modules\Social\Domain\Vocabulary;
use App\Support\Access;
use App\Support\Realtime;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Sets or clears a group chat's photo. Anyone in it can, and the thread says
 * who did. The photo is made into a small square first, so the chat list
 * never loads anything heavy.
 */
final class ChangeConversationPhoto
{
    public function handle(Access $actor, string $conversationId, ?UploadedFile $photo): void
    {
        $me = SocialRules::chat($actor);
        $conversation = ChatScope::conversation($me, $conversationId);

        if ($conversation->kind !== Vocabulary::GROUP) {
            throw new RuntimeException('Only a group chat has its own photo.');
        }
        if ($photo === null && $conversation->photo_path === null) {
            return;
        }

        $old = $conversation->photo_path;
        $picture = $photo !== null ? Attachments::storeAvatar($photo, 'chat') : null;
        $changer = $actor->fullName !== '' ? $actor->fullName : 'Someone';

        try {
            DB::transaction(static function () use ($conversation, $picture, $changer): void {
                $conversation->photo_path = $picture['path'] ?? null;
                $conversation->photo_size = $picture['size'] ?? null;
                ChatLines::write($conversation, $changer.($picture !== null ? ' changed the group photo' : ' removed the group photo'));
            });
        } catch (Throwable $error) {
            Attachments::discard($picture !== null ? [$picture['path']] : []);
            throw $error;
        }

        if ($old !== null) {
            Attachments::discard([$old]);
        }

        Realtime::announce(array_map(static fn (int $id): string => Realtime::account($id), ChatScope::memberIds($conversation->id)));
    }
}
