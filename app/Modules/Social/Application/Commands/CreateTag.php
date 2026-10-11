<?php

declare(strict_types=1);

namespace App\Modules\Social\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Models\CommunityTag;
use App\Modules\Social\Application\SocialRules;
use App\Support\Access;
use App\Support\AuditService;
use App\Support\Realtime;
use Illuminate\Support\Str;

/**
 * A new tag at the end of the tag menu. Moderators only. Its code is made
 * from the name once and never changes, so the tag can be renamed later
 * without touching a single post. Audited.
 */
final class CreateTag
{
    /** @return string the new tag's code */
    public function handle(Access $actor, string $name, string $tone): string
    {
        $me = SocialRules::moderator($actor, 'Only moderators can manage tags.');

        $name = SocialRules::tagName($name, null);
        $tone = SocialRules::tone($tone);

        $tag = new CommunityTag;
        $tag->fill(['name' => $name, 'tone' => $tone, 'position' => (int) CommunityTag::query()->max('position') + 1]);
        $tag->code = self::code($name);
        $tag->created_by = $me;
        $tag->save();

        AuditService::record($actor, AuditEvent::COMMUNITY_TAG_CREATED, 'community_tags', $tag->id, "Tag {$name} created");
        Realtime::announce([Realtime::community()]);

        return $tag->code;
    }

    /** "Safety tips" becomes SAFETY_TIPS, or SAFETY_TIPS_2 if that's taken. */
    private static function code(string $name): string
    {
        $base = Str::of($name)->ascii()->upper()->replaceMatches('/[^A-Z0-9]+/', '_')->trim('_')->limit(16, '')->toString();
        $base = $base !== '' ? $base : 'TAG';
        $code = $base;

        for ($n = 2; CommunityTag::query()->where('code', $code)->exists(); $n++) {
            $code = $base.'_'.$n;
        }

        return $code;
    }
}
