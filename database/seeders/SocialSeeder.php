<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ChatAttachment;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\ChatReaction;
use App\Models\CommunityAttachment;
use App\Models\CommunityComment;
use App\Models\CommunityCommentReaction;
use App\Models\CommunityGroup;
use App\Models\CommunityGroupMember;
use App\Models\CommunityPost;
use App\Models\CommunityReaction;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

/**
 * A lived-in Community and Chat for the demo: six groups (each with an
 * owner, two with a moderator), ten posts with their comments and reactions
 * (some threads three levels deep), and seven conversations between the
 * eight demo accounts, each in its own read state. A post and a message
 * carry a PDF, so files show up too.
 *
 * The demo gives only four of the eight employees a login, and Community and
 * Chat happen between accounts, so this adds the other four (accounts()).
 * They live here, not in EmployeeSeeder, because only Community and Chat
 * need them.
 *
 * Every time is minutes before the run, so the feed reads fresh on whatever
 * day it is seeded. Mentions are written `@[maria]` here and stored as the
 * account id, the way the Social module stores them.
 *
 * The three photos (PNGs) and two files (PDFs) are under
 * database/seeders/assets/community, copied onto the private `local` disk
 * (community-photos/, community-files/, chat-photos/, chat-files/) under
 * fixed names, so a re-run overwrites them instead of piling up copies.
 *
 * Running it again on a database people have used keeps what they wrote,
 * and it never moves a chat's "last message" time back past a real message
 * (that would leave a chat stuck as unread).
 *
 * Demo only: ProductionSeeder never calls this.
 */
final class SocialSeeder extends PortSeeder
{
    /** person key => demo account (EmployeeSeeder's four, then accounts() below) */
    private const PEOPLE = [
        'maria' => 'admin@bestworkflows.local',
        'jose' => 'payroll@bestworkflows.local',
        'andrea' => 'supervisor@bestworkflows.local',
        'paolo' => 'employee@bestworkflows.local',
        'grace' => 'grace@bestworkflows.local',
        'nathaniel' => 'nathaniel@bestworkflows.local',
        'liza' => 'liza@bestworkflows.local',
        'ramon' => 'ramon@bestworkflows.local',
    ];

    /**
     * The four employees who have no login otherwise, on the Employee
     * role: email, full name, employee index (EmployeeSeeder's emp-5 to
     * emp-8), last login (days ago, hour, minute).
     */
    private const ACCOUNTS = [
        ['grace@bestworkflows.local', 'Grace Mendoza', 4, [0, 6, 40]],
        ['nathaniel@bestworkflows.local', 'Nathaniel Cruz', 5, [1, 7, 20]],
        ['liza@bestworkflows.local', 'Liza Domingo', 6, [0, 8, 30]],
        ['ramon@bestworkflows.local', 'Ramon Aquino', 7, [2, 6, 15]],
    ];

    /** photo key => file under database/seeders/assets/community */
    private const PHOTOS = [
        'court' => 'court.png',
        'town-hall' => 'town-hall.png',
        'congrats' => 'congrats.png',
    ];

    /** file key => [file under database/seeders/assets/community, the name people see] */
    private const FILES = [
        'agenda' => ['town-hall-agenda.pdf', 'Q3 town hall agenda.pdf'],
        'checklist' => ['201-file-checklist.pdf', '201 file checklist.pdf'],
    ];

    private CarbonImmutable $now;

    /** @var array<string, int> person key => account id */
    private array $people = [];

    public function run(): void
    {
        $this->now = CarbonImmutable::now('UTC');
        $this->accounts();

        foreach (self::PEOPLE as $key => $email) {
            $id = $this->userId($email);

            // Every sample line names one of the eight demo accounts; without
            // all of them (EmployeeSeeder not run) there is nothing to seed.
            if ($id === null) {
                return;
            }

            $this->people[$key] = $id;
        }

        $this->community();
        $this->chat();
    }

    /**
     * EmployeeSeeder's own pattern for a demo account: an existing account
     * keeps its name and password; only role, employee link and active flag
     * are asserted.
     */
    private function accounts(): void
    {
        $employees = $this->employeeIdsByIndex();
        $roleId = Role::query()->where('name', 'Employee')->value('id');

        foreach (self::ACCOUNTS as [$email, $name, $empIdx, $login]) {
            $user = User::query()->where('email', $email)->first();

            if ($user === null) {
                $user = new User;
                $user->forceFill([
                    'name' => $name,
                    'email' => $email,
                    // The demo credential every seeded account shares.
                    'password' => 'bestworkflows123',
                    'email_verified_at' => CarbonImmutable::now(),
                    'last_login_at' => $this->at($login[0], $login[1], $login[2]),
                ]);
            }

            $user->forceFill([
                'role_id' => $roleId,
                'employee_id' => $employees[$empIdx] ?? null,
                'is_active' => true,
            ]);

            $user->save();
        }
    }

    private function community(): void
    {
        $groups = [];

        foreach (self::groups() as [$id, $key, $name, $description, $private, $creator, $members]) {
            $group = $this->upsert(CommunityGroup::class, ['name' => $name], [
                'id' => $id,
                'description' => $description,
                'is_private' => $private,
                'created_by' => $this->people[$creator],
                'created_at' => $this->ago(40000),
            ]);
            $groups[$key] = (string) $group->getKey();

            // MEMBER, OWNER or MODERATOR are all in the group; REQUESTED is
            // waiting, and "REQUESTED:jose" is waiting because Jose asked.
            foreach ($members as $person => $place) {
                [$place, $addedBy] = array_pad(explode(':', $place), 2, null);
                $this->upsert(CommunityGroupMember::class, ['group_id' => $groups[$key], 'user_id' => $this->people[$person]], [
                    'status' => $place === 'REQUESTED' ? 'REQUESTED' : 'MEMBER',
                    'role' => in_array($place, ['OWNER', 'MODERATOR'], true) ? $place : 'MEMBER',
                    'added_by' => $addedBy !== null ? $this->people[$addedBy] : null,
                    'created_at' => $this->ago($place === 'REQUESTED' ? 600 : 30000),
                ]);
            }
        }

        $posts = [];
        foreach (self::posts() as [$id, $author, $group, $tag, $minutes, $pinnedBy, $photo, $body, $reactions, $file]) {
            $post = $this->upsert(CommunityPost::class, ['id' => $id], [
                'author_id' => $this->people[$author],
                'group_id' => $group === null ? null : $groups[$group],
                'tag' => $tag,
                'body' => $this->tokens($body),
                'pinned_at' => $pinnedBy === null ? null : $this->ago($minutes - 5),
                'pinned_by' => $pinnedBy === null ? null : $this->people[$pinnedBy],
                'created_at' => $this->ago($minutes),
            ]);
            $posts[$id] = (string) $post->getKey();

            if ($photo !== null) {
                $path = $this->photo($photo, 'community-photos');
                $this->upsert(CommunityAttachment::class, ['id' => $id.'-photo'], [
                    'post_id' => $posts[$id],
                    'kind' => 'PHOTO',
                    'path' => $path,
                    'mime' => 'image/png',
                    'size' => (int) Storage::disk('local')->size($path),
                    'position' => 0,
                    'created_at' => $this->ago($minutes),
                ]);
            }

            if ($file !== null) {
                $path = $this->file($file, 'community-files');
                $this->upsert(CommunityAttachment::class, ['id' => $id.'-file'], [
                    'post_id' => $posts[$id],
                    'kind' => 'FILE',
                    'path' => $path,
                    'name' => self::FILES[$file][1],
                    'mime' => 'application/pdf',
                    'size' => (int) Storage::disk('local')->size($path),
                    'position' => 1,
                    'created_at' => $this->ago($minutes),
                ]);
            }

            $delay = 3;
            foreach ($reactions as $person => $type) {
                $this->upsert(CommunityReaction::class, ['post_id' => $posts[$id], 'user_id' => $this->people[$person]], [
                    'type' => $type,
                    'created_at' => $this->ago(max($minutes - $delay, 1)),
                ]);
                $delay += 4;
            }
        }

        $comments = [];
        foreach (self::comments() as [$id, $post, $parent, $author, $minutes, $body, $reactions]) {
            $comment = $this->upsert(CommunityComment::class, ['id' => $id], [
                'post_id' => $posts[$post],
                'parent_id' => $parent === null ? null : $comments[$parent],
                'author_id' => $this->people[$author],
                'body' => $this->tokens($body),
                'created_at' => $this->ago($minutes),
            ]);
            $comments[$id] = (string) $comment->getKey();

            $delay = 2;
            foreach ($reactions as $person => $type) {
                $this->upsert(CommunityCommentReaction::class, ['comment_id' => $comments[$id], 'user_id' => $this->people[$person]], [
                    'type' => $type,
                    'created_at' => $this->ago(max($minutes - $delay, 1)),
                ]);
                $delay++;
            }
        }
    }

    private function chat(): void
    {
        $sentAt = [];
        $firstAt = [];
        $lastAt = [];

        foreach (self::messages() as [$id, $conversation, , $minutes]) {
            $sentAt[$id] = $this->ago($minutes);
            $firstAt[$conversation] ??= $sentAt[$id];
            $lastAt[$conversation] = $sentAt[$id];
        }

        $conversations = [];

        foreach (self::conversations() as [$id, $kind, $title, $starter, $members]) {
            $ids = array_map(fn (string $person): int => $this->people[$person], array_keys($members));
            sort($ids);

            // A direct chat is matched on its pair, like StartConversation
            // does, so a pair that already talks keeps its one conversation.
            $match = $kind === 'DIRECT' ? ['direct_key' => $ids[0].':'.$ids[1]] : ['id' => $id];

            $row = $this->upsert(ChatConversation::class, $match, [
                'id' => $id,
                'kind' => $kind,
                'title' => $title,
                'created_by' => $this->people[$starter],
                'last_message_at' => $lastAt[$id],
                'created_at' => $firstAt[$id],
            ]);
            $conversations[$id] = (string) $row->getKey();

            foreach ($members as $person => [$readThrough, $muted]) {
                $this->upsert(ChatParticipant::class, ['conversation_id' => $conversations[$id], 'user_id' => $this->people[$person]], [
                    // Whoever started a group chat is its admin.
                    'role' => $kind === 'GROUP' && $person === $starter ? 'ADMIN' : 'MEMBER',
                    'is_muted' => $muted,
                    'last_read_at' => $readThrough === null ? null : $sentAt[$readThrough],
                    'created_at' => $firstAt[$id],
                ]);
            }
        }

        $messages = [];
        foreach (self::messages() as [$id, $conversation, $sender, $minutes, $body, $replyTo, $photo, $reactions, $file]) {
            $message = $this->upsert(ChatMessage::class, ['id' => $id], [
                'conversation_id' => $conversations[$conversation],
                'sender_id' => $sender === null ? null : $this->people[$sender],
                'kind' => $sender === null ? 'SYSTEM' : 'TEXT',
                'body' => $body === null ? null : $this->tokens($body),
                'reply_to_id' => $replyTo === null ? null : $messages[$replyTo],
                // A sender's message with no body is one they removed.
                'removed_at' => $sender !== null && $body === null ? $this->ago(max($minutes - 1, 1)) : null,
                'created_at' => $sentAt[$id],
            ]);
            $messages[$id] = (string) $message->getKey();

            if ($photo !== null) {
                $path = $this->photo($photo, 'chat-photos');
                $this->upsert(ChatAttachment::class, ['id' => $id.'-photo'], [
                    'message_id' => $messages[$id],
                    'kind' => 'PHOTO',
                    'path' => $path,
                    'mime' => 'image/png',
                    'size' => (int) Storage::disk('local')->size($path),
                    'position' => 0,
                    'created_at' => $sentAt[$id],
                ]);
            }

            if ($file !== null) {
                $path = $this->file($file, 'chat-files');
                $this->upsert(ChatAttachment::class, ['id' => $id.'-file'], [
                    'message_id' => $messages[$id],
                    'kind' => 'FILE',
                    'path' => $path,
                    'name' => self::FILES[$file][1],
                    'mime' => 'application/pdf',
                    'size' => (int) Storage::disk('local')->size($path),
                    'position' => 1,
                    'created_at' => $sentAt[$id],
                ]);
            }

            foreach ($reactions as $person => $type) {
                $this->upsert(ChatReaction::class, ['message_id' => $messages[$id], 'user_id' => $this->people[$person]], [
                    'type' => $type,
                    'created_at' => $this->ago(max($minutes - 2, 1)),
                ]);
            }
        }

        // A chat's "last message" time is its real newest message, which on
        // a database people have used can be one of theirs, newer than any
        // demo line. Never the demo's own guess.
        foreach ($conversations as $seedKey => $id) {
            $newest = ChatMessage::query()->where('conversation_id', $id)->max('created_at');
            if ($newest !== null) {
                $this->upsert(ChatConversation::class, ['id' => $seedKey], ['last_message_at' => CarbonImmutable::parse((string) $newest, 'UTC')]);
            }
        }
    }

    /** `@[maria]` → `@[<her account id>]`, the stored form of a mention. */
    private function tokens(string $body): string
    {
        $body = (string) preg_replace_callback(
            '/@\[g:([a-z0-9-]+)\]/',
            fn (array $m): string => '@[g:'.($this->seedId(CommunityGroup::class, $m[1]) ?? $m[1]).']',
            $body,
        );

        return (string) preg_replace_callback(
            '/@\[([a-z]+)\]/',
            fn (array $m): string => '@['.($this->people[$m[1]] ?? $m[1]).']',
            $body,
        );
    }

    /** Copies a sample photo onto the private disk; returns its path there. */
    private function photo(string $key, string $directory): string
    {
        $path = $directory.'/seed-'.$key.'.png';
        Storage::disk('local')->put($path, (string) file_get_contents(database_path('seeders/assets/community/'.self::PHOTOS[$key])));

        return $path;
    }

    /** Copies a sample file onto the private disk; returns its path there. */
    private function file(string $key, string $directory): string
    {
        $path = $directory.'/seed-'.pathinfo(self::FILES[$key][0], PATHINFO_FILENAME).'.pdf';
        Storage::disk('local')->put($path, (string) file_get_contents(database_path('seeders/assets/community/'.self::FILES[$key][0])));

        return $path;
    }

    private function ago(int $minutes): CarbonImmutable
    {
        return $this->now->subMinutes($minutes);
    }

    // The sample itself.

    /**
     * id, key, name, description, private, created by, members (person =>
     * OWNER, MODERATOR, MEMBER, REQUESTED, or REQUESTED:<who asked>)
     *
     * @return list<array{string, string, string, string, bool, string, array<string, string>}>
     */
    private static function groups(): array
    {
        return [
            ['cgrp-1', 'makati', 'Makati Head Office', 'Announcements and chatter for everyone at the head office.', false, 'maria',
                ['maria' => 'OWNER', 'andrea' => 'MEMBER', 'grace' => 'MEMBER', 'liza' => 'MEMBER', 'jose' => 'MEMBER']],
            ['cgrp-2', 'field', 'Field & Remote Team', 'For everyone working on site or on the road.', false, 'andrea',
                ['andrea' => 'OWNER', 'nathaniel' => 'MODERATOR', 'ramon' => 'MEMBER', 'paolo' => 'MEMBER', 'grace' => 'MEMBER', 'maria' => 'MEMBER']],
            ['cgrp-3', 'lunch', 'Lunch Club', 'Where to eat near the office, and who is buying.', false, 'ramon',
                ['ramon' => 'OWNER', 'liza' => 'MEMBER', 'maria' => 'MEMBER', 'jose' => 'MEMBER', 'andrea' => 'MEMBER', 'nathaniel' => 'MEMBER']],
            ['cgrp-4', 'qc', 'Quezon City Depot', 'Shift swaps, depot updates and the occasional merienda run.', false, 'paolo',
                ['paolo' => 'OWNER', 'jose' => 'MEMBER', 'grace' => 'MEMBER', 'andrea' => 'MEMBER', 'ramon' => 'MEMBER']],
            ['cgrp-5', 'hoops', 'Saturday Hoops', 'Weekend basketball at the barangay court. All levels welcome.', false, 'nathaniel',
                ['nathaniel' => 'OWNER', 'paolo' => 'MEMBER', 'ramon' => 'MEMBER', 'jose' => 'MEMBER']],
            ['cgrp-6', 'books', 'Book Club', 'One book a month, and one long lunch to talk about it.', true, 'liza',
                ['liza' => 'OWNER', 'jose' => 'MODERATOR', 'andrea' => 'MEMBER', 'grace' => 'REQUESTED', 'ramon' => 'REQUESTED:jose']],
        ];
    }

    /**
     * id, author, group key (null = Everyone), tag, minutes ago, pinned by,
     * photo, body, reactions (person => type), file
     *
     * @return list<array{string, string, string|null, string|null, int, string|null, string|null, string, array<string, string>, string|null}>
     */
    private static function posts(): array
    {
        return [
            ['cpost-1', 'andrea', 'field', 'KUDOS', 190, null, null,
                "Shout-out to @[paolo] and @[grace] for covering the double shift at the QC depot during last night's storm. Gates secured, generators checked, and everyone got home safe. Salamat, team \u{1F64F}",
                ['maria' => 'LOVE', 'nathaniel' => 'LIKE', 'ramon' => 'SUPPORT', 'paolo' => 'LIKE', 'grace' => 'LOVE'], null],
            ['cpost-2', 'jose', null, 'QUESTION', 310, null, null,
                "Payslips for the latest cutoff are up in My Portal \u{2192} My payslips. If a line looks off, message me in Chat instead of printing it and walking it over \u{1F604}\n\nQuick poll while I have you: would you rather get a notification the moment your payslip is posted?",
                ['ramon' => 'LIKE', 'liza' => 'LIKE', 'paolo' => 'LIKE', 'grace' => 'INSIGHTFUL', 'andrea' => 'LIKE'], null],
            ['cpost-3', 'paolo', 'qc', 'QUESTION', 700, null, null,
                'Anyone free to swap shifts on Sunday? I have the 6 AM and need the 2 PM.',
                ['grace' => 'LIKE', 'andrea' => 'SUPPORT'], null],
            ['cpost-4', 'nathaniel', 'hoops', 'SOCIAL', 1560, null, 'court',
                "Court is booked for Saturday, 7 AM. We need two more for a full run. Who's in? \u{1F3C0} Bring water, the fountain is still broken.",
                ['ramon' => 'LIKE', 'paolo' => 'FUNNY', 'jose' => 'LIKE'], null],
            ['cpost-5', 'liza', 'makati', 'LOST_FOUND', 1800, null, null,
                "Found in the 3rd floor pantry: a black umbrella and a blue tumbler with a \u{201C}Kape Muna\u{201D} sticker. Both are at the front desk until Friday.",
                ['andrea' => 'FUNNY', 'maria' => 'LIKE', 'grace' => 'LIKE'], null],
            ['cpost-6', 'liza', 'books', 'SOCIAL', 2400, null, null,
                'This month we are reading Noli Me Tangere. Lunch discussion on the last Friday of the month. Bring your favorite line.',
                ['jose' => 'LIKE', 'andrea' => 'LOVE'], null],
            ['cpost-7', 'maria', null, 'ANNOUNCEMENT', 3060, 'maria', 'town-hall',
                "Our Q3 town hall is this Friday, 3:00 PM at the Makati office. The field team can join on Google Meet. We'll walk through the new leave policy, the eDTR rollout, and (yes) the year-end party.\n\nSend your questions to @[liza] before Thursday so we can cover as many as we can.",
                ['jose' => 'LIKE', 'liza' => 'CELEBRATE', 'andrea' => 'LIKE', 'paolo' => 'LIKE', 'nathaniel' => 'CELEBRATE', 'grace' => 'LOVE', 'ramon' => 'LIKE'], 'agenda'],
            ['cpost-8', 'maria', null, 'KUDOS', 4400, null, 'congrats',
                "Please join me in congratulating @[grace] on passing her probationary evaluation! \u{1F389} Grace joined the depot team this year and is already the person everyone asks about the new eDTR punch station.",
                ['andrea' => 'CELEBRATE', 'jose' => 'CELEBRATE', 'liza' => 'LOVE', 'paolo' => 'CELEBRATE', 'nathaniel' => 'LIKE', 'ramon' => 'LIKE', 'grace' => 'LOVE'], null],
            ['cpost-9', 'ramon', 'lunch', 'SOCIAL', 5900, null, null,
                "New carinderia opened on Valero St. \u{20B1}85 for rice and two viands, and the sinigang is legit. Who's coming tomorrow?",
                ['liza' => 'LOVE', 'jose' => 'LIKE', 'nathaniel' => 'LIKE'], null],
            ['cpost-10', 'maria', null, 'ANNOUNCEMENT', 7300, 'maria', null,
                "Welcome to Community \u{1F44B} This is our space for the informal side of work: kudos, questions, lost and found, photos from the site, weekend plans.\n\nA few ground rules: be kind, keep payroll and personal records out of posts (message HR in Chat for those), and remember that everyone in the company can see a post shared with Everyone.",
                ['jose' => 'LIKE', 'andrea' => 'LOVE', 'paolo' => 'LIKE', 'grace' => 'LIKE', 'nathaniel' => 'SUPPORT', 'liza' => 'LOVE', 'ramon' => 'LIKE'], null],
        ];
    }

    /**
     * id, post, replying to (null = a comment), author, minutes ago, body,
     * reactions (person => type). A reply always comes after what it answers,
     * and threads go three levels deep, as far as the app allows.
     *
     * @return list<array{string, string, string|null, string, int, string, array<string, string>}>
     */
    private static function comments(): array
    {
        return [
            ['ccmt-1', 'cpost-1', null, 'paolo', 150, "All in a night's work, ma'am! Grace brought the coffee \u{2615}", ['andrea' => 'LIKE', 'grace' => 'LOVE', 'maria' => 'CELEBRATE']],
            ['ccmt-2', 'cpost-1', 'ccmt-1', 'grace', 140, "And @[ramon] drove everyone home after \u{1F64C}", ['paolo' => 'LIKE', 'ramon' => 'FUNNY']],
            ['ccmt-20', 'cpost-1', 'ccmt-2', 'ramon', 135, "Anytime. Next time someone else drives \u{1F605}", ['grace' => 'FUNNY']],
            ['ccmt-21', 'cpost-1', 'ccmt-1', 'andrea', 130, 'Coffee hero. Noted for the next night shift.', ['grace' => 'LIKE']],
            ['ccmt-3', 'cpost-1', null, 'maria', 120, 'This is what I like to see. Well done, depot team.', []],
            ['ccmt-4', 'cpost-2', null, 'ramon', 280, 'Yes please! I always forget to check.', ['jose' => 'LIKE']],
            ['ccmt-5', 'cpost-2', null, 'liza', 250, 'A notification and an email would be perfect.', []],
            ['ccmt-6', 'cpost-3', null, 'grace', 650, 'I can take the 6 AM. Message me.', ['paolo' => 'SUPPORT']],
            ['ccmt-7', 'cpost-4', null, 'ramon', 1500, "Count me in. I'll bring the ball.", ['nathaniel' => 'LIKE']],
            ['ccmt-8', 'cpost-4', null, 'paolo', 1490, "In, if I'm not on shift \u{1F91E}", []],
            ['ccmt-9', 'cpost-5', null, 'andrea', 1700, "The tumbler is mine! I'll pick it up later, thank you!", []],
            ['ccmt-10', 'cpost-6', null, 'andrea', 2300, 'Finally a classic. Count me in.', ['liza' => 'LOVE']],
            ['ccmt-11', 'cpost-7', null, 'jose', 2900, 'Will it be recorded for those on shift?', ['paolo' => 'LIKE', 'grace' => 'INSIGHTFUL']],
            ['ccmt-12', 'cpost-7', 'ccmt-11', 'maria', 2880, 'Yes. The recording goes up here by Monday.', ['jose' => 'LIKE', 'andrea' => 'LIKE', 'paolo' => 'SUPPORT']],
            ['ccmt-22', 'cpost-7', 'ccmt-12', 'jose', 2870, 'Perfect, thank you!', ['maria' => 'LIKE']],
            ['ccmt-13', 'cpost-7', null, 'grace', 2000, "Excited for the year-end party news \u{1F389}", ['liza' => 'CELEBRATE', 'maria' => 'LOVE']],
            ['ccmt-14', 'cpost-8', null, 'grace', 4300, "Thank you po! Couldn't have done it without the depot team \u{1F499}", ['maria' => 'LOVE', 'andrea' => 'LOVE', 'paolo' => 'CELEBRATE', 'liza' => 'LIKE']],
            ['ccmt-15', 'cpost-8', null, 'andrea', 4250, 'Well deserved!', []],
            ['ccmt-16', 'cpost-8', null, 'nathaniel', 4100, "Congrats, Grace! Merienda's on you \u{1F604}", ['grace' => 'FUNNY', 'ramon' => 'FUNNY']],
            ['ccmt-17', 'cpost-9', null, 'liza', 5800, 'Sama ako! Save me a seat.', []],
            ['ccmt-18', 'cpost-10', null, 'nathaniel', 7200, "Finally! Where do I post the court schedule? \u{1F604}", ['paolo' => 'LIKE']],
            ['ccmt-19', 'cpost-10', 'ccmt-18', 'maria', 7150, '@[g:cgrp-5] is already on the right. Join it and post there.', ['nathaniel' => 'SUPPORT']],
        ];
    }

    /**
     * id, kind, title (groups only), started by, and who is in it: person =>
     * [the newest message they have read (null = none yet), muted]
     *
     * @return list<array{string, string, string|null, string, array<string, array{string|null, bool}>}>
     */
    private static function conversations(): array
    {
        return [
            ['cconv-1', 'GROUP', 'QC Depot Ops', 'andrea', [
                'andrea' => ['cmsg-1-8', false], 'paolo' => ['cmsg-1-7', false], 'grace' => ['cmsg-1-8', false],
                'ramon' => ['cmsg-1-6', false], 'nathaniel' => ['cmsg-1-8', false], 'maria' => ['cmsg-1-3', true],
            ]],
            ['cconv-2', 'DIRECT', null, 'andrea', ['andrea' => ['cmsg-2-6', false], 'maria' => ['cmsg-2-4', false]]],
            ['cconv-3', 'GROUP', 'HR Team', 'maria', [
                'maria' => ['cmsg-3-7', false], 'liza' => ['cmsg-3-7', false], 'jose' => ['cmsg-3-5', false],
            ]],
            ['cconv-4', 'GROUP', 'Saturday Hoops', 'nathaniel', [
                'nathaniel' => ['cmsg-4-2', false], 'paolo' => ['cmsg-4-3', false], 'ramon' => ['cmsg-4-3', false], 'jose' => ['cmsg-4-2', false],
            ]],
            ['cconv-5', 'DIRECT', null, 'jose', ['jose' => ['cmsg-5-5', false], 'paolo' => ['cmsg-5-5', false]]],
            ['cconv-6', 'DIRECT', null, 'liza', ['liza' => ['cmsg-6-3', false], 'maria' => ['cmsg-6-3', false]]],
            ['cconv-7', 'DIRECT', null, 'maria', ['maria' => ['cmsg-7-2', false], 'grace' => ['cmsg-7-2', false]]],
        ];
    }

    /**
     * id, conversation, sender (null = a line the app writes, like "created
     * the group"), minutes ago, body (null = removed), replying to, photo,
     * reactions (person => type), file. Oldest first within a conversation.
     *
     * @return list<array{string, string, string|null, int, string|null, string|null, string|null, array<string, string>, string|null}>
     */
    private static function messages(): array
    {
        return [
            ['cmsg-1-0', 'cconv-1', null, 12000, 'Andrea Lim created the group', null, null, [], null],
            ['cmsg-1-1', 'cconv-1', 'paolo', 2000, "Gate 2 sensor is acting up again \u{1F629}", null, null, [], null],
            ['cmsg-1-2', 'cconv-1', 'nathaniel', 1980, "I'll swing by after lunch to check it.", null, null, ['paolo' => 'SUPPORT', 'grace' => 'SUPPORT'], null],
            ['cmsg-1-3', 'cconv-1', 'nathaniel', 1700, 'Fixed. Loose connector, logged it in the maintenance sheet.', null, null, [], null],
            ['cmsg-1-4', 'cconv-1', 'grace', 900, "Storm's getting worse. Who's still on site?", null, null, [], null],
            ['cmsg-1-5', 'cconv-1', 'paolo', 895, 'Me and Grace. @[ramon] is on standby with the van.', null, null, [], null],
            ['cmsg-1-6', 'cconv-1', 'ramon', 890, "Here, ready when you are \u{1F690}", null, null, [], null],
            ['cmsg-1-7', 'cconv-1', 'andrea', 880, 'Thank you all. Stay safe, and secure the generators first.', null, null, [], null],
            ['cmsg-1-8', 'cconv-1', 'grace', 300, "All good this morning, gates are open \u{2705}", null, null, ['andrea' => 'LIKE'], null],

            ['cmsg-2-1', 'cconv-2', 'andrea', 1500, 'Hi! Quick favor: can you check if the Saturday roster already has Grace on the morning shift?', null, null, [], null],
            ['cmsg-2-2', 'cconv-2', 'maria', 1490, 'Checking now.', null, null, [], null],
            ['cmsg-2-3', 'cconv-2', 'maria', 1488, "She's on 6 AM\u{2013}2 PM at the QC depot. Paolo covers 2\u{2013}10.", null, null, [], null],
            ['cmsg-2-4', 'cconv-2', 'andrea', 1480, "Perfect, thank you! \u{1F64F}", null, null, ['maria' => 'LIKE'], null],
            ['cmsg-2-5', 'cconv-2', 'andrea', 38, 'The town hall moved to 3:30 PM, by the way.', null, null, [], null],
            ['cmsg-2-6', 'cconv-2', 'andrea', 36, 'Can you update the calendar invite for the field team?', null, null, [], null],

            ['cmsg-3-0', 'cconv-3', null, 9000, 'Maria Santos created the group', null, null, [], null],
            ['cmsg-3-1', 'cconv-3', 'maria', 4460, 'Card for the announcement about Grace. Okay to post?', null, 'congrats', [], null],
            ['cmsg-3-2', 'cconv-3', 'liza', 4455, "Love it \u{1F389}", null, null, ['maria' => 'LOVE'], null],
            ['cmsg-3-3', 'cconv-3', 'maria', 300, 'Reminder: 201 file updates are due Friday. @[liza] can you send the checklist here?', null, null, [], null],
            ['cmsg-3-4', 'cconv-3', 'liza', 290, "Here it is: ID, NBI clearance, updated TIN form, and two 1\u{00D7}1 photos.", null, null, [], 'checklist'],
            ['cmsg-3-5', 'cconv-3', 'jose', 280, 'Payroll needs the TIN forms by Thursday so they make the next cutoff.', 'cmsg-3-4', null, [], null],
            ['cmsg-3-6', 'cconv-3', 'liza', 276, null, null, null, [], null],
            ['cmsg-3-7', 'cconv-3', 'maria', 60, "Noted. I'll remind everyone in Community.", null, null, ['liza' => 'LIKE'], null],

            ['cmsg-4-0', 'cconv-4', null, 10000, 'Nathaniel Cruz created the group', null, null, [], null],
            ['cmsg-4-1', 'cconv-4', 'nathaniel', 1600, "Booked! Saturday, 7 AM \u{1F3C0}", null, 'court', [], null],
            ['cmsg-4-2', 'cconv-4', 'ramon', 1590, "I'll bring the ball.", null, null, ['nathaniel' => 'LIKE', 'paolo' => 'LIKE'], null],
            ['cmsg-4-3', 'cconv-4', 'paolo', 70, "Can we push to 8? I'm off shift at 7.", null, null, [], null],

            ['cmsg-5-1', 'cconv-5', 'jose', 2900, 'Your payslip correction is in. The overtime on the 12th is now counted.', null, null, [], null],
            ['cmsg-5-2', 'cconv-5', 'paolo', 2890, 'Thanks, Jose! That was fast.', null, null, ['jose' => 'LOVE'], null],
            ['cmsg-5-3', 'cconv-5', 'paolo', 2885, 'Will it show in the next cutoff?', null, null, [], null],
            ['cmsg-5-4', 'cconv-5', 'jose', 2870, 'Yes, as an adjustment line.', null, null, [], null],
            ['cmsg-5-5', 'cconv-5', 'paolo', 2860, 'Great, thanks!', null, null, [], null],

            ['cmsg-6-1', 'cconv-6', 'liza', 4300, "Hi! Can you approve my leave for Friday when you get a chance? It's in Leave Management.", null, null, [], null],
            ['cmsg-6-2', 'cconv-6', 'maria', 4200, "Approved \u{2705} Enjoy the long weekend!", null, null, [], null],
            ['cmsg-6-3', 'cconv-6', 'liza', 4190, "Thank you!! \u{1F973}", null, null, [], null],

            ['cmsg-7-1', 'cconv-7', 'maria', 4390, "Congratulations on your evaluation! \u{1F389}", null, null, [], null],
            ['cmsg-7-2', 'cconv-7', 'grace', 4380, "Thank you po! \u{1F499}", null, null, ['maria' => 'LOVE'], null],
        ];
    }
}
