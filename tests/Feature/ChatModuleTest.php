<?php

declare(strict_types=1);

use App\Events\RealtimeChange;
use App\Livewire\Chat\ChatPage;
use App\Livewire\Chat\Support\ChatThread;
use App\Livewire\Chat\UnreadBadge;
use App\Livewire\Edtr\LiveFeed;
use App\Models\ChatAttachment;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\ChatReaction;
use App\Models\EmployeeProfile;
use App\Models\Notification;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Social\Application\Commands\AddChatMembers;
use App\Modules\Social\Application\Commands\ChangeConversationPhoto;
use App\Modules\Social\Application\Commands\EditMessage;
use App\Modules\Social\Application\Commands\LeaveConversation;
use App\Modules\Social\Application\Commands\MuteConversation;
use App\Modules\Social\Application\Commands\ReactToMessage;
use App\Modules\Social\Application\Commands\ReadConversation;
use App\Modules\Social\Application\Commands\RemoveChatMember;
use App\Modules\Social\Application\Commands\RemoveMessage;
use App\Modules\Social\Application\Commands\RenameConversation;
use App\Modules\Social\Application\Commands\SendMessage;
use App\Modules\Social\Application\Commands\SetChatAdmin;
use App\Modules\Social\Application\Commands\StartConversation;
use App\Modules\Social\Application\Queries\ChatInbox;
use App\Modules\Social\Application\SocialRules;
use App\Support\Access;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * The Chat module: one-to-one and group conversations.
 *
 * Gated on the module grant; behind it, being in a conversation is the whole
 * permission model, and these tests hold every path to it: the list, the
 * thread, sending, reacting, editing, removing and the photos all refuse an
 * outsider whatever id the client sends. Then the behaviour people notice: one
 * direct chat per pair, unread counts and "Seen" from one read position,
 * viewing is reading, mentions only in groups and never through a mute, a
 * removal leaves a tombstone, and the last person out of a group takes it with
 * them. ONLY THE SENDER EDITS A MESSAGE, and A REMOVED MESSAGE STAYS REMOVED:
 * no edit brings its text back, not even one a removal overtakes. An edit
 * tells only the people it newly mentions and moves nobody's unread count.
 */

/** @param list<string> $modules ModuleCode constants to grant */
function chatRole(array $modules): Role
{
    $role = new Role;
    $role->forceFill(['name' => 'Chat '.Str::random(6), 'all_data' => false, 'is_system' => false])->save();

    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }

    return $role;
}

/** @param list<string> $modules ModuleCode constants to grant */
function chatUser(string $name, array $modules = ['CHAT']): User
{
    $employee = EmployeeProfile::query()->create([
        'employee_code' => 'CHAT-'.Str::random(12),
        'first_name' => Str::before($name, ' '),
        'last_name' => Str::after($name, ' '),
        'employment_status' => 'Regular',
        'date_hired' => '2024-01-01',
        'is_active' => true,
    ]);

    return User::factory()->create(['employee_id' => $employee->id, 'role_id' => chatRole($modules)->getKey(), 'is_active' => true]);
}

/**
 * Signs in as a new account (Access is rebuilt per request, so the one
 * already built is dropped).
 *
 * @param  list<string>  $modules  ModuleCode constants to grant
 */
function chatActor(array $modules = ['CHAT'], string $name = 'Kim Reyes'): User
{
    $user = chatUser($name, $modules);
    chatSignIn($user);

    return $user;
}

function chatSignIn(User $user): void
{
    test()->actingAs($user);
    app()->forgetInstance(Access::class);
}

function chatAccess(User $user): Access
{
    return Access::forUser($user->fresh() ?? $user);
}

/**
 * Opens a conversation as $starter: one other person makes a direct chat,
 * more make a group.
 *
 * @param  list<User>  $others
 */
function chatStart(User $starter, array $others, string $title = ''): string
{
    return app(StartConversation::class)->handle(chatAccess($starter), array_map(static fn (User $u): int => (int) $u->getKey(), $others), $title);
}

/**
 * Sends as $sender, a second later than whatever was sent before, so read
 * positions compare cleanly.
 *
 * @param  list<UploadedFile>  $photos
 */
function chatSay(User $sender, string $conversationId, string $body, ?string $replyTo = null, array $photos = []): string
{
    test()->travel(1)->seconds();

    return app(SendMessage::class)->handle(chatAccess($sender), $conversationId, $body, $replyTo, $photos);
}

function chatUnread(User $user, string $conversationId): int
{
    foreach (app(ChatInbox::class)->conversations(chatAccess($user)) as $conversation) {
        if ($conversation->id === $conversationId) {
            return $conversation->unread;
        }
    }

    throw new LogicException('Not in that conversation.');
}

/*
|--------------------------------------------------------------------------
| Route gating
|--------------------------------------------------------------------------
*/

it('redirects a guest to login', function () {
    $this->get('/chat')->assertRedirect(route('login'));
});

it('redirects an account whose role lacks CHAT to its landing page', function () {
    chatActor(['PORTAL']);

    $this->get('/chat')->assertRedirect('/portal');
});

it('lists only the conversations the viewer is in', function () {
    $andrea = chatUser('Andrea Lim');
    $jose = chatUser('Jose Rivera');
    $liza = chatUser('Liza Domingo');
    $me = chatActor();
    $mine = chatStart($andrea, [$me]);
    chatSay($andrea, $mine, 'Hi from Andrea');
    $theirs = chatStart($jose, [$liza]);
    chatSay($jose, $theirs, 'Private between Jose and Liza');

    $this->get('/chat')
        ->assertOk()
        ->assertSee('Andrea Lim')
        ->assertSee('Hi from Andrea')
        ->assertDontSee('Private between Jose and Liza');
});

/*
|--------------------------------------------------------------------------
| Starting conversations
|--------------------------------------------------------------------------
*/

it('opens a direct chat, and reopens the same one instead of starting a second', function () {
    $andrea = chatUser('Andrea Lim');
    $me = chatActor();

    $page = Livewire::test(ChatPage::class)
        ->call('openNewChat')
        ->set('pickedIds', [(int) $andrea->getKey()])
        ->call('startChat');

    $conversation = ChatConversation::query()->sole();
    expect($conversation->kind)->toBe('DIRECT')
        ->and($conversation->direct_key)->toBe(min((int) $me->getKey(), (int) $andrea->getKey()).':'.max((int) $me->getKey(), (int) $andrea->getKey()));
    $page->assertSet('conversationId', $conversation->getKey());

    // From the other side, too.
    expect(chatStart($andrea, [$me]))->toBe($conversation->getKey())
        ->and(ChatConversation::query()->count())->toBe(1);
});

it('refuses to start a chat with someone who cannot use Chat', function () {
    $outsider = chatUser('Liza Domingo', ['PORTAL']);
    chatActor();

    Livewire::test(ChatPage::class)
        ->call('openNewChat')
        ->set('pickedIds', [(int) $outsider->getKey()])
        ->call('startChat')
        ->assertSet('conversationId', null);

    expect(ChatConversation::query()->count())->toBe(0);
});

it('starts a group named after its first members, announcing who made it', function () {
    $jose = chatUser('Jose Rivera');
    $andrea = chatUser('Andrea Lim');
    $me = chatActor();

    Livewire::test(ChatPage::class)
        ->call('openNewChat')
        ->set('pickedIds', [(int) $jose->getKey(), (int) $andrea->getKey()])
        ->call('startChat')
        ->assertSee('Kim Reyes created the group');

    $group = ChatConversation::query()->sole();
    expect($group->kind)->toBe('GROUP')
        ->and($group->title)->toBe('Andrea, Jose')
        ->and($group->direct_key)->toBeNull()
        ->and(ChatParticipant::query()->where('conversation_id', $group->getKey())->count())->toBe(3);

    // The starter has seen the line announcing their own group; the others have not, but it is not a message to count.
    expect(chatUnread($me, (string) $group->getKey()))->toBe(0)
        ->and(chatUnread($jose, (string) $group->getKey()))->toBe(0);

    $named = chatStart($me, [$jose, $andrea], 'Weekend plans');
    expect(ChatConversation::query()->find($named)?->title)->toBe('Weekend plans');
});

/*
|--------------------------------------------------------------------------
| Sending, reading, seen
|--------------------------------------------------------------------------
*/

it('sends a message the other person sees as unread until they open it, then as seen', function () {
    $andrea = chatUser('Andrea Lim');
    $me = chatActor();
    $id = chatStart($me, [$andrea]);

    Livewire::test(ChatPage::class)
        ->call('openConversation', $id)
        ->set("drafts.{$id}", 'Can you update the calendar invite?')
        ->call('send')
        ->assertSet("drafts.{$id}", '')
        ->assertSee('Can you update the calendar invite?')
        ->assertSee('Sent');

    $message = ChatMessage::query()->where('conversation_id', $id)->sole();
    expect($message->sender_id)->toBe((int) $me->getKey())
        ->and(ChatConversation::query()->find($id)?->last_message_at?->equalTo($message->created_at))->toBeTrue()
        ->and(chatUnread($me, $id))->toBe(0)
        ->and(chatUnread($andrea, $id))->toBe(1)
        ->and(app(ChatInbox::class)->unread(chatAccess($andrea)))->toBe(1);

    chatSignIn($andrea);
    Livewire::test(ChatPage::class)->call('openConversation', $id);
    expect(chatUnread($andrea, $id))->toBe(0);

    chatSignIn($me);
    Livewire::test(ChatPage::class)
        ->call('openConversation', $id)
        ->assertSee('Seen');
});

it('counts a message that arrives while the conversation is open as read (viewing is reading)', function () {
    $andrea = chatUser('Andrea Lim');
    $me = chatActor();
    $id = chatStart($me, [$andrea]);

    $page = Livewire::test(ChatPage::class)->call('openConversation', $id);

    chatSay($andrea, $id, 'Are you there?');
    expect(chatUnread($me, $id))->toBe(1);

    $page->call('$refresh')->assertSee('Are you there?');
    expect(chatUnread($me, $id))->toBe(0);
});

it('opens a linked conversation only for someone in it', function () {
    $andrea = chatUser('Andrea Lim');
    $jose = chatUser('Jose Rivera');
    $id = chatStart($andrea, [$jose]);
    chatSay($andrea, $id, 'Just us two');

    chatSignIn($jose);
    $this->get('/chat?c='.$id)->assertOk()->assertSee('Just us two');

    chatActor();
    $this->get('/chat?c='.$id)->assertOk()->assertDontSee('Just us two');
});

it('answers a message in the same conversation, and drops a reply aimed at another one', function () {
    $andrea = chatUser('Andrea Lim');
    $jose = chatUser('Jose Rivera');
    $me = chatActor();
    $ours = chatStart($me, [$andrea]);
    $original = chatSay($andrea, $ours, 'Is Grace on the morning shift?');
    $elsewhere = chatStart($andrea, [$jose]);
    $foreign = chatSay($andrea, $elsewhere, 'Not for you');

    Livewire::test(ChatPage::class)
        ->call('openConversation', $ours)
        ->call('startReply', $original)
        ->set("drafts.{$ours}", 'Yes, 6 AM to 2 PM.')
        ->call('send')
        ->assertSee('You replied to Andrea Lim')
        ->call('startReply', $foreign)
        ->set("drafts.{$ours}", 'Another one')
        ->call('send');

    expect(ChatMessage::query()->where('body', 'Yes, 6 AM to 2 PM.')->value('reply_to_id'))->toBe($original)
        ->and(ChatMessage::query()->where('body', 'Another one')->value('reply_to_id'))->toBeNull();

    // Seen from the other side, a reply to your message is a reply to you.
    chatSignIn($andrea);
    Livewire::test(ChatPage::class)
        ->call('openConversation', $ours)
        ->assertSee('Kim replied to you');
});

it('filters the list to unread chats or groups, and searches by name', function () {
    $andrea = chatUser('Andrea Lim');
    $jose = chatUser('Jose Rivera');
    $me = chatActor();
    $direct = chatStart($andrea, [$me]);
    chatSay($andrea, $direct, 'Unread from Andrea');
    $group = chatStart($me, [$andrea, $jose], 'Depot crew');
    chatSay($me, $group, 'Read by me, my own message');

    Livewire::test(ChatPage::class)
        ->set('filter', 'unread')
        ->assertSee('Unread from Andrea')
        ->assertDontSee('Depot crew')
        ->set('filter', 'groups')
        ->assertSee('Depot crew')
        ->assertDontSee('Unread from Andrea')
        ->set('filter', 'all')
        ->set('search', 'jose')
        ->assertSee('Depot crew')
        ->assertDontSee('Unread from Andrea');
});

it('shows the newest fifty messages and loads earlier ones on request', function () {
    $andrea = chatUser('Andrea Lim');
    $me = chatActor();
    $id = chatStart($me, [$andrea]);
    foreach (range(1, 55) as $n) {
        chatSay($n % 2 === 0 ? $me : $andrea, $id, "Message number {$n}.");
    }

    Livewire::test(ChatPage::class)
        ->call('openConversation', $id)
        ->assertSee('Message number 55.')
        ->assertSee('Message number 6.')
        ->assertDontSee('Message number 5.')
        ->assertSee('Load earlier messages')
        ->call('showEarlier')
        ->assertSee('Message number 1.')
        ->assertDontSee('Load earlier messages');
});

/*
|--------------------------------------------------------------------------
| Mentions and mute
|--------------------------------------------------------------------------
*/

it('turns mentions into tokens in a group and tells the person, unless they muted it', function () {
    $andrea = chatUser('Andrea Lim');
    $jose = chatUser('Jose Rivera');
    $me = chatActor();
    $id = chatStart($me, [$andrea, $jose], 'Depot');
    app(MuteConversation::class)->handle(chatAccess($jose), $id, true);

    chatSay($me, $id, '@Andrea Lim and @Jose Rivera, the gate is open.');

    expect(ChatMessage::query()->where('kind', 'TEXT')->value('body'))->toBe('@['.$andrea->getKey().'] and @['.$jose->getKey().'], the gate is open.');
    $bell = Notification::query()->where('user_id', $andrea->getKey())->sole();
    expect($bell->kind)->toBe('CHAT_MENTION')
        ->and($bell->title)->toBe('Kim Reyes mentioned you in Depot')
        ->and($bell->body)->toBe('@Andrea Lim and @Jose Rivera, the gate is open.')
        ->and($bell->href)->toBe('/chat?c='.$id.'&m='.ChatMessage::query()->where('kind', 'TEXT')->value('id'));
    expect(Notification::query()->where('user_id', $jose->getKey())->exists())->toBeFalse();
});

it('does not ring someone who has since lost Chat, even if still in the group', function () {
    $andrea = chatUser('Andrea Lim');
    $jose = chatUser('Jose Rivera');
    $me = chatActor();
    $id = chatStart($me, [$andrea, $jose], 'Depot');
    // Jose's role changes: the seat stays, the module does not.
    $jose->forceFill(['role_id' => chatRole(['PORTAL'])->getKey()])->save();

    chatSay($me, $id, '@Andrea Lim and @Jose Rivera, heads up.');

    expect(Notification::query()->where('user_id', $andrea->getKey())->count())->toBe(1)
        ->and(Notification::query()->where('user_id', $jose->getKey())->count())->toBe(0);
});

it('leaves a direct chat\'s text as typed', function () {
    $andrea = chatUser('Andrea Lim');
    $me = chatActor();
    $id = chatStart($me, [$andrea]);

    chatSay($me, $id, 'Thanks @Andrea Lim');

    expect(ChatMessage::query()->value('body'))->toBe('Thanks @Andrea Lim')
        ->and(Notification::query()->count())->toBe(0);
});

it('mutes and unmutes the open conversation, and a muted chat leaves the sidebar count', function () {
    $andrea = chatUser('Andrea Lim');
    $me = chatActor();
    $id = chatStart($andrea, [$me]);
    chatSay($andrea, $id, 'One');
    chatSay($andrea, $id, 'Two');

    expect(app(ChatInbox::class)->unread(chatAccess($me)))->toBe(2);
    Livewire::test(UnreadBadge::class)->assertSee('2 unread messages');

    // Opening reads it, so mute from the thread, then let Andrea speak again.
    $page = Livewire::test(ChatPage::class)->call('openConversation', $id)->call('mute');
    expect(ChatParticipant::query()->where('conversation_id', $id)->where('user_id', $me->getKey())->value('is_muted'))->toBeTrue();

    chatSay($andrea, $id, 'Three');
    expect(chatUnread($me, $id))->toBe(1)
        ->and(app(ChatInbox::class)->unread(chatAccess($me)))->toBe(0);

    $page->call('unmute');
    expect(ChatParticipant::query()->where('conversation_id', $id)->where('user_id', $me->getKey())->value('is_muted'))->toBeFalse();
});

it('mutes a group chat but keeps mentions: they still ring, and only they count in the badge', function () {
    $andrea = chatUser('Andrea Lim');
    $jose = chatUser('Jose Rivera');
    $me = chatActor();
    $id = chatStart($andrea, [$me, $jose], 'Depot');
    $mine = static fn (): ChatParticipant => ChatParticipant::query()->where('conversation_id', $id)->where('user_id', $me->getKey())->sole();

    $page = Livewire::test(ChatPage::class)->call('openConversation', $id)->call('mute', 'MENTIONS');
    expect($mine()->is_muted)->toBeTrue()->and($mine()->allow_mentions)->toBeTrue();

    chatSay($andrea, $id, 'The truck is late.');
    chatSay($andrea, $id, '@Kim Reyes can you call the driver?');
    chatSay($jose, $id, '@everyone lunch is here');

    expect(Notification::query()->where('user_id', $me->getKey())->orderBy('created_at')->pluck('title')->all())
        ->toBe(['Andrea Lim mentioned you in Depot', 'Jose Rivera mentioned everyone in Depot'])
        ->and(chatUnread($me, $id))->toBe(3)
        ->and(app(ChatInbox::class)->unread(chatAccess($me)))->toBe(2);
    $page->call('backToList')->assertSee('2 unread mentions');

    // Everything muted: nothing rings, and nothing counts.
    $page->call('openConversation', $id)->call('mute', 'ALL');
    expect($mine()->allow_mentions)->toBeFalse();
    chatSay($andrea, $id, '@Kim Reyes one more thing');
    expect(Notification::query()->where('user_id', $me->getKey())->count())->toBe(2)
        ->and(app(ChatInbox::class)->unread(chatAccess($me)))->toBe(0);

    // Unmuting forgets the choice. A direct chat has no mentions, so it only mutes one way.
    $page->call('mute', 'MENTIONS')->call('unmute');
    expect($mine()->is_muted)->toBeFalse()->and($mine()->allow_mentions)->toBeFalse();
    $direct = chatStart($andrea, [$me]);
    $page->call('openConversation', $direct)->call('mute', 'MENTIONS');
    expect(ChatParticipant::query()->where('conversation_id', $direct)->where('user_id', $me->getKey())->value('allow_mentions'))->toBeFalse();
});

it('links a mention to its message, and opens the thread far enough back to show it', function () {
    $andrea = chatUser('Andrea Lim');
    $jose = chatUser('Jose Rivera');
    $me = chatActor();
    $id = chatStart($andrea, [$me, $jose], 'Depot');
    $mention = chatSay($andrea, $id, '@Kim Reyes the forklift key is in the office.');
    foreach (range(1, ChatInbox::PAGE + 5) as $n) {
        chatSay($jose, $id, "Update {$n}");
    }

    expect(Notification::query()->where('user_id', $me->getKey())->value('href'))->toBe('/chat?c='.$id.'&m='.$mention);

    // Past the first 50, so only the link brings it into view, highlighted.
    Livewire::withQueryParams(['c' => $id]);
    Livewire::test(ChatPage::class)->assertDontSee('the forklift key is in the office.');
    Livewire::withQueryParams(['c' => $id, 'm' => $mention]);
    Livewire::test(ChatPage::class)
        ->assertSet('focusMessageId', $mention)
        ->assertSet('limit', ChatInbox::PAGE + 6)
        ->assertSee('the forklift key is in the office.')
        ->assertSeeHtml('data-found="true"');

    // A message that isn't in this chat highlights nothing.
    Livewire::withQueryParams(['c' => $id, 'm' => '01NOTAMESSAGE00000000000000']);
    Livewire::test(ChatPage::class)
        ->assertSet('focusMessageId', null)
        ->assertSet('limit', ChatInbox::PAGE)
        ->assertDontSeeHtml('data-found="true"');
});

it('refuses a message over 2,000 characters on the server, whatever the box allows', function () {
    $andrea = chatUser('Andrea Lim');
    $me = chatActor();
    $id = chatStart($me, [$andrea]);

    expect(fn () => chatSay($me, $id, str_repeat('a', SocialRules::MAX_MESSAGE + 1)))
        ->toThrow(RuntimeException::class, 'A message holds up to 2,000 characters.');
    expect(ChatMessage::query()->where('kind', 'TEXT')->count())->toBe(0);
});

it('keeps a hand-typed mention token as plain text, in a group and in a direct chat', function () {
    $andrea = chatUser('Andrea Lim');
    $jose = chatUser('Jose Rivera');
    $me = chatActor();
    $group = chatStart($me, [$andrea, $jose], 'Depot');
    $direct = chatStart($me, [$andrea]);

    chatSay($me, $group, 'Hey @['.$andrea->getKey().'] and @[everyone]');
    chatSay($me, $direct, 'Copied from the notes: @[everyone]');

    $body = static fn (string $id): string => (string) ChatMessage::query()->where('conversation_id', $id)->where('kind', 'TEXT')->value('body');
    expect(Notification::query()->count())->toBe(0)
        ->and($body($group))->toBe("Hey @\u{2060}[".$andrea->getKey()."] and @\u{2060}[everyone]")
        ->and($body($direct))->toBe("Copied from the notes: @\u{2060}[everyone]")
        ->and(app(ChatInbox::class)->unread(chatAccess($andrea)))->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Reactions and removal
|--------------------------------------------------------------------------
*/

it('keeps one reaction per person on a message', function () {
    $andrea = chatUser('Andrea Lim');
    $me = chatActor();
    $id = chatStart($me, [$andrea]);
    $message = chatSay($andrea, $id, 'Perfect, thank you!');

    $page = Livewire::test(ChatPage::class)->call('openConversation', $id)->call('react', $message, 'LIKE');
    expect(ChatReaction::query()->where('message_id', $message)->value('type'))->toBe('LIKE');

    $page->call('react', $message, 'LOVE');
    expect(ChatReaction::query()->count())->toBe(1)
        ->and(ChatReaction::query()->value('type'))->toBe('LOVE');

    $page->call('react', $message, 'LOVE');
    expect(ChatReaction::query()->count())->toBe(0);
});

it('removes only the sender\'s own message, leaving a tombstone without its photos', function () {
    Storage::fake('local');
    $andrea = chatUser('Andrea Lim');
    $me = chatActor();
    $id = chatStart($me, [$andrea]);
    $theirs = chatSay($andrea, $id, 'Mine, not yours');
    $mine = chatSay($me, $id, 'Oops, wrong chat', photos: [UploadedFile::fake()->image('wrong.png', 20, 20)]);
    $path = (string) ChatAttachment::query()->where('message_id', $mine)->value('path');
    Storage::disk('local')->assertExists($path);

    Livewire::test(ChatPage::class)
        ->call('openConversation', $id)
        ->call('removeMessage', $theirs)
        ->call('removeMessage', $mine)
        ->assertSee('You removed a message')
        ->assertDontSee('Oops, wrong chat');

    $removed = ChatMessage::query()->find($mine);
    expect($removed?->body)->toBeNull()
        ->and($removed?->removed_at)->not->toBeNull()
        ->and(ChatAttachment::query()->where('message_id', $mine)->exists())->toBeFalse()
        ->and(ChatMessage::query()->find($theirs)?->removed_at)->toBeNull();
    Storage::disk('local')->assertMissing($path);

    expect(fn () => app(ReactToMessage::class)->handle(chatAccess($andrea), $mine, 'LIKE'))
        ->toThrow(RuntimeException::class, 'That message can no longer be reacted to.');
});

it('lets the sender edit their own message with no time limit, says so, and tells only the people the edit newly mentions', function () {
    $andrea = chatUser('Andrea Lim');
    $jose = chatUser('Jose Rivera');
    $me = chatActor();
    $id = chatStart($me, [$andrea, $jose], 'Depot');
    $message = chatSay($me, $id, '@Andrea Lim, the gate is open.');
    $unread = chatUnread($andrea, $id);
    $stamp = app(ChatInbox::class)->stamp(chatAccess($andrea));

    // A month later is fine: like Teams, an edit has no time limit.
    $this->travel(30)->days();
    Livewire::test(ChatPage::class)
        ->call('openConversation', $id)
        ->set('edits.'.$message, '@Andrea Lim and @Jose Rivera, the gate is open.')
        ->call('saveEdit', $message)
        ->assertDispatched('chat-edited', id: $message)
        ->assertSeeText('Edited');

    $edited = ChatMessage::query()->find($message);
    expect($edited?->body)->toBe('@['.$andrea->getKey().'] and @['.$jose->getKey().'], the gate is open.')
        ->and($edited?->edited_at)->not->toBeNull()
        ->and(Notification::query()->where('user_id', $andrea->getKey())->count())->toBe(1)
        ->and(Notification::query()->where('user_id', $jose->getKey())->value('title'))->toBe('Kim Reyes mentioned you in Depot')
        // An edit isn't a new message: nobody's unread count moves, but their page notices the change.
        ->and(chatUnread($andrea, $id))->toBe($unread)
        ->and(app(ChatInbox::class)->stamp(chatAccess($andrea)))->not->toBe($stamp);
});

it('refuses an edit to someone else\'s message, a system line, a removed message, and one that would leave it empty', function () {
    $andrea = chatUser('Andrea Lim');
    $me = chatActor();
    $id = chatStart($me, [$andrea, chatUser('Jose Rivera')], 'Depot');
    $theirs = chatSay($andrea, $id, 'Mine, not yours');
    $mine = chatSay($me, $id, 'Mine');
    $gone = chatSay($me, $id, 'Oops');
    app(RemoveMessage::class)->handle(chatAccess($me), $gone);
    $line = (string) ChatMessage::query()->where('kind', 'SYSTEM')->value('id');
    $lineBefore = ChatMessage::query()->find($line)?->getAttributes();
    $edit = app(EditMessage::class);

    expect(fn () => $edit->handle(chatAccess($me), $theirs, 'Reworded'))
        ->toThrow(RuntimeException::class, 'You can only edit your own messages.');
    expect(fn () => $edit->handle(chatAccess($me), $line, 'Reworded'))
        ->toThrow(RuntimeException::class, 'You can only edit your own messages.');
    expect(fn () => $edit->handle(chatAccess($me), $gone, 'Back again'))
        ->toThrow(RuntimeException::class, 'That message was removed.');
    expect(fn () => $edit->handle(chatAccess($me), $mine, '  '))
        ->toThrow(RuntimeException::class, 'Write something, or remove the message instead.');
    expect(fn () => $edit->handle(chatAccess(chatUser('Grace Mendoza')), $mine, 'Hi'))
        ->toThrow(RuntimeException::class, 'That message was not found.');

    $removed = ChatMessage::query()->find($gone);
    expect(ChatMessage::query()->find($theirs)?->body)->toBe('Mine, not yours')
        ->and(ChatMessage::query()->find($mine)?->body)->toBe('Mine')
        ->and(ChatMessage::query()->find($mine)?->edited_at)->toBeNull()
        // The removed message keeps its tombstone: no text, never edited.
        ->and($removed?->body)->toBeNull()
        ->and($removed?->edited_at)->toBeNull()
        ->and(ChatMessage::query()->find($line)?->getAttributes())->toBe($lineBefore);
});

it('refuses an edit that a removal overtakes, so the text never comes back', function () {
    Event::fake([RealtimeChange::class]);
    $this->withoutDefer();
    config()->set('copems.realtime.enabled', true);
    $andrea = chatUser('Andrea Lim');
    $me = chatActor();
    $id = chatStart($me, [$andrea, chatUser('Jose Rivera')], 'Depot');
    $message = chatSay($me, $id, 'Oops');
    $announced = Event::dispatched(RealtimeChange::class)->count();

    // The removal commits after the edit has read the message and passed its
    // removed_at check: just as the edit looks up the conversation.
    $overtaken = false;
    DB::listen(static function (QueryExecuted $query) use (&$overtaken, $message): void {
        if (! $overtaken && str_contains($query->sql, 'chat_conversations')) {
            $overtaken = true;
            ChatMessage::query()->whereKey($message)->update(['body' => null, 'removed_at' => CarbonImmutable::now()->utc()->format('Y-m-d H:i:s.v')]);
        }
    });

    expect(fn () => app(EditMessage::class)->handle(chatAccess($me), $message, '@Andrea Lim, never mind'))
        ->toThrow(RuntimeException::class, 'That message was removed.');

    $gone = ChatMessage::query()->find($message);
    expect($overtaken)->toBeTrue()
        ->and($gone?->removed_at)->not->toBeNull()
        ->and($gone?->body)->toBeNull()
        ->and($gone?->edited_at)->toBeNull()
        ->and(Notification::query()->count())->toBe(0)
        ->and(Event::dispatched(RealtimeChange::class)->count())->toBe($announced);
});

it('leaves a direct chat\'s edited text as typed, and tells nobody', function () {
    $andrea = chatUser('Andrea Lim');
    $me = chatActor();
    $id = chatStart($me, [$andrea]);
    $message = chatSay($me, $id, 'Thanks');

    app(EditMessage::class)->handle(chatAccess($me), $message, 'Thanks @Andrea Lim, and @['.$andrea->getKey().']');

    $edited = ChatMessage::query()->find($message);
    expect($edited?->body)->toBe("Thanks @Andrea Lim, and @\u{2060}[".$andrea->getKey().']')
        ->and($edited?->edited_at)->not->toBeNull()
        ->and(Notification::query()->count())->toBe(0);
});

it('clears a message\'s text when its photos stay, keeping them and saying it was edited', function () {
    Storage::fake('local');
    $me = chatActor();
    $id = chatStart($me, [chatUser('Andrea Lim')]);
    $message = chatSay($me, $id, 'Look at the gate', photos: [
        UploadedFile::fake()->image('gate.png', 20, 20),
        UploadedFile::fake()->image('fence.png', 20, 20),
    ]);
    $paths = array_map('strval', ChatAttachment::query()->where('message_id', $message)->pluck('path')->all());

    app(EditMessage::class)->handle(chatAccess($me), $message, '  ');

    $edited = ChatMessage::query()->find($message);
    expect($edited?->body)->toBeNull()
        ->and($edited?->edited_at)->not->toBeNull()
        ->and($edited?->removed_at)->toBeNull()
        ->and($paths)->toHaveCount(2)
        ->and(ChatAttachment::query()->where('message_id', $message)->count())->toBe(2);
    foreach ($paths as $path) {
        Storage::disk('local')->assertExists($path);
    }
});

/*
|--------------------------------------------------------------------------
| Confinement: an outsider gets nothing, whatever id they send
|--------------------------------------------------------------------------
*/

it('refuses every action on a conversation the caller is not in', function () {
    Storage::fake('local');
    $andrea = chatUser('Andrea Lim');
    $jose = chatUser('Jose Rivera');
    $id = chatStart($andrea, [$jose]);
    $message = chatSay($andrea, $id, 'Just between us two', photos: [UploadedFile::fake()->image('private.png', 20, 20)]);
    $photo = (string) ChatAttachment::query()->value('id');
    $outsider = chatActor();

    Livewire::test(ChatPage::class)
        ->call('openConversation', $id)
        ->assertSet('conversationId', null)
        ->assertDontSee('Just between us two');

    expect(app(ChatInbox::class)->thread(chatAccess($outsider), $id))->toBeNull()
        ->and(fn () => chatSay($outsider, $id, 'Let me in'))->toThrow(RuntimeException::class, 'That conversation was not found.')
        ->and(fn () => app(ReactToMessage::class)->handle(chatAccess($outsider), $message, 'LIKE'))->toThrow(RuntimeException::class, 'That message was not found.');

    $this->get(route('chat.photos', ['photo' => $photo]))->assertNotFound();

    chatSignIn($jose);
    $this->get(route('chat.photos', ['photo' => $photo]))->assertOk()->assertHeader('Content-Type', 'image/png');

    expect(ChatMessage::query()->where('conversation_id', $id)->count())->toBe(1)
        ->and(ChatReaction::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Leaving
|--------------------------------------------------------------------------
*/

it('leaves a group with a line in the thread, and the last one out takes it along', function () {
    Storage::fake('local');
    $andrea = chatUser('Andrea Lim');
    $jose = chatUser('Jose Rivera');
    $me = chatActor();
    $id = chatStart($me, [$andrea, $jose], 'Hoops');
    chatSay($andrea, $id, 'A photo', photos: [UploadedFile::fake()->image('court.png', 20, 20)]);
    $path = (string) ChatAttachment::query()->value('path');

    $this->travel(1)->seconds();
    Livewire::test(ChatPage::class)
        ->call('openConversation', $id)
        ->call('leaveConversation')
        ->assertSet('conversationId', null);

    expect(ChatParticipant::query()->where('conversation_id', $id)->count())->toBe(2)
        // Kim started it, so Kim was its admin; whoever has been in longest takes over.
        ->and(ChatMessage::query()->where('conversation_id', $id)->where('kind', 'SYSTEM')->pluck('body')->all())->toContain('Kim Reyes left the group', 'Andrea Lim is now an admin')
        ->and(app(ChatInbox::class)->conversations(chatAccess($me)))->toBe([]);

    foreach ([$jose, $andrea] as $member) {
        chatSignIn($member);
        Livewire::test(ChatPage::class)->call('openConversation', $id)->call('leaveConversation');
    }

    expect(ChatConversation::query()->find($id))->toBeNull()
        ->and(ChatMessage::query()->count())->toBe(0)
        ->and(ChatAttachment::query()->count())->toBe(0);
    Storage::disk('local')->assertMissing($path);
});

it('never lets anyone leave a direct chat', function () {
    $andrea = chatUser('Andrea Lim');
    $me = chatActor();
    $id = chatStart($me, [$andrea]);

    Livewire::test(ChatPage::class)
        ->call('openConversation', $id)
        ->call('leaveConversation')
        ->assertSet('conversationId', $id);

    expect(ChatParticipant::query()->where('conversation_id', $id)->count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Photos and the list preview
|--------------------------------------------------------------------------
*/

it('sends a photo on its own and previews it in the list', function () {
    Storage::fake('local');
    $andrea = chatUser('Andrea Lim');
    $me = chatActor();
    $id = chatStart($me, [$andrea]);

    chatSay($me, $id, '', photos: [UploadedFile::fake()->image('court.png', 20, 20)]);

    $photo = ChatAttachment::query()->sole();
    expect($photo->path)->toStartWith('chat-photos/');

    $list = app(ChatInbox::class)->conversations(chatAccess($me));
    expect($list[0]->preview)->toBe('You sent a photo');

    $theirs = app(ChatInbox::class)->conversations(chatAccess($andrea));
    expect($theirs[0]->preview)->toBe('Kim sent a photo')
        ->and($theirs[0]->unread)->toBe(1);
});

it('moves only the chat stamp of the people in the conversation', function () {
    $andrea = chatUser('Andrea Lim');
    $jose = chatUser('Jose Rivera');
    $me = chatActor();
    $id = chatStart($me, [$andrea]);
    $inbox = app(ChatInbox::class);
    $mineBefore = $inbox->stamp(chatAccess($andrea));
    $joseBefore = $inbox->stamp(chatAccess($jose));

    chatSay($me, $id, 'Hello');

    expect($inbox->stamp(chatAccess($andrea)))->not->toBe($mineBefore)
        ->and($inbox->stamp(chatAccess($jose)))->toBe($joseBefore);
});

/*
|--------------------------------------------------------------------------
| Realtime (here rather than in RealtimeBroadcastTest, which stays theirs)
|--------------------------------------------------------------------------
*/

it('announces a message on the own channel of everyone in the conversation, and nobody else', function () {
    Event::fake([RealtimeChange::class]);
    $this->withoutDefer();
    config()->set('copems.realtime.enabled', true);
    $andrea = chatUser('Andrea Lim');
    $jose = chatUser('Jose Rivera');
    $me = chatActor();
    $id = chatStart($me, [$andrea]);

    chatSay($me, $id, 'Gate 2 is open.');

    $names = static fn (RealtimeChange $event): array => array_map(static fn ($channel): string => $channel->name, $event->broadcastOn());
    $expected = ['private-user.'.$andrea->getKey(), 'private-user.'.$me->getKey()];
    sort($expected);

    Event::assertDispatched(RealtimeChange::class, static function (RealtimeChange $event) use ($names, $expected): bool {
        $channels = $names($event);
        sort($channels);

        return $channels === $expected;
    });
    Event::assertNotDispatched(RealtimeChange::class, static fn (RealtimeChange $event): bool => in_array('private-user.'.$jose->getKey(), $names($event), true));
});

it('listens on the account\'s own channel, on the page and the sidebar badge', function () {
    config()->set('copems.realtime.enabled', true);
    $me = chatActor();

    expect(Livewire::test(ChatPage::class)->effects['listeners'] ?? [])
        ->toContain('echo-private:user.'.$me->getKey().',.changed')
        ->and(Livewire::test(UnreadBadge::class)->effects['listeners'] ?? [])
        ->toContain('echo-private:user.'.$me->getKey().',.changed');
});

it('says in the details when someone in the conversation can no longer use Chat', function () {
    $me = chatActor();
    $ana = chatUser('Ana Ramos');
    $conversation = chatStart($me, [$ana]);
    chatSay($ana, $conversation, 'I will send the file tonight.');

    // Her role loses Chat. Her message stays where it is.
    RoleModuleGrant::query()->where('role_id', $ana->role_id)->where('module', 'CHAT')->delete();

    $thread = app(ChatInbox::class)->thread(chatAccess($me), $conversation);

    expect($thread?->withoutChat)->toBe([(int) $ana->getKey()])
        ->and($thread?->messages[0]->body)->toBe('I will send the file tonight.');

    Livewire::test(ChatPage::class)
        ->call('openConversation', $conversation)
        ->call('toggleDetails')
        ->assertSee('Ana Ramos can no longer use Chat.');
});

it('keeps the status under your last message on one line: Sent, then who has seen it', function () {
    $andrea = chatUser('Andrea Lim');
    $jose = chatUser('Jose Rivera');
    $liza = chatUser('Liza Domingo');
    $me = chatActor();
    $group = chatStart($me, [$andrea, $jose, $liza], 'Ops');
    chatSay($me, $group, 'Truck leaves at 6.');

    $label = static function () use ($me, $group): ?string {
        $thread = app(ChatInbox::class)->thread(chatAccess($me), $group);

        return $thread !== null ? ChatThread::seenLabel($thread) : null;
    };
    $read = static function (User $who) use ($group): void {
        app(ReadConversation::class)->handle(chatAccess($who), $group);
    };

    expect($label())->toBe('Sent');
    $read($andrea);
    expect($label())->toBe('Seen by Andrea');
    $read($jose);
    expect($label())->toBe('Seen by Andrea and Jose');
    $read($liza);
    expect($label())->toBe('Seen by 3 people');

    // A click on the line lists everyone who has seen it, by full name.
    Livewire::test(ChatPage::class)
        ->call('openConversation', $group)
        ->assertSee('Seen by 3 people')
        ->assertSee('Liza Domingo');

    // Once someone else speaks, the line goes: it only sits under your own last word.
    chatSay($andrea, $group, 'Copy.');
    expect($label())->toBeNull();

    // A direct chat has one other person, so "Seen" says it all.
    $direct = chatStart($me, [$andrea]);
    chatSay($me, $direct, 'Thanks!');
    app(ReadConversation::class)->handle(chatAccess($andrea), $direct);
    expect(ChatThread::seenLabel(app(ChatInbox::class)->thread(chatAccess($me), $direct)))->toBe('Seen');
});

it('shows a message time only on hover, and lists who reacted from the badge', function () {
    $andrea = chatUser('Andrea Lim');
    $me = chatActor();
    $id = chatStart($me, [$andrea]);
    $message = chatSay($me, $id, 'Lunch at 12?');
    app(ReactToMessage::class)->handle(chatAccess($andrea), $message, 'LOVE');

    Livewire::test(ChatPage::class)
        ->call('openConversation', $id)
        // The time lives with the hover tools, not on its own line.
        ->assertSeeHtml('group-hover/message:opacity-100')
        ->assertSeeHtml('<time')
        ->assertSee('1 reaction. Show who reacted')
        ->assertSee('Andrea Lim');
});

/*
|--------------------------------------------------------------------------
| Group chats, files, read state and time labels
|--------------------------------------------------------------------------
*/

it('reads, previews and marks seen by the newest message itself, even when the list\'s copy of its time is stale', function () {
    Storage::fake('local');
    $grace = chatUser('Grace Mendoza');
    $me = chatActor(name: 'Maria Santos');
    $id = chatStart($me, [$grace]);
    chatSay($grace, $id, 'Thank you po!');
    app(ReadConversation::class)->handle(chatAccess($me), $id);
    chatSay($me, $id, '', photos: array_map(static fn (int $n): UploadedFile => UploadedFile::fake()->image("p{$n}.png", 20, 20), range(1, 4)));

    // What a demo-data refresh once did: the chat's copy of "last
    // message at" went back to an older time, under a real message.
    ChatConversation::query()->whereKey($id)->update(['last_message_at' => CarbonImmutable::now()->subDays(3)->format('Y-m-d H:i:s.v')]);

    $grace_view = app(ChatInbox::class)->conversations(chatAccess($grace))[0];
    expect($grace_view->preview)->toBe('Maria sent 4 photos')
        ->and($grace_view->unread)->toBe(1);

    // Opening it reads it, and Maria's line says so.
    chatSignIn($grace);
    Livewire::test(ChatPage::class)->call('openConversation', $id)->assertDispatched('chat-read');
    expect(chatUnread($grace, $id))->toBe(0)
        ->and(ChatThread::seenLabel(app(ChatInbox::class)->thread(chatAccess($me), $id)))->toBe('Seen');
});

it('sends files, previews them in the list, and serves them only to people in the chat', function () {
    Storage::fake('local');
    $andrea = chatUser('Andrea Lim');
    $me = chatActor();
    $id = chatStart($me, [$andrea]);
    $pdf = UploadedFile::fake()->createWithContent('Roster.pdf', "%PDF-1.4\n1 0 obj\n<< >>\nendobj\n%%EOF\n");

    chatSay($me, $id, '', photos: [$pdf]);

    $file = ChatAttachment::query()->sole();
    expect($file->kind)->toBe('FILE')
        ->and($file->name)->toBe('Roster.pdf')
        ->and($file->path)->toStartWith('chat-files/')
        ->and(app(ChatInbox::class)->conversations(chatAccess($andrea))[0]->preview)->toBe('Kim sent a file');

    $thread = app(ChatInbox::class)->thread(chatAccess($me), $id);
    expect($thread?->messages[0]->files[0]->name)->toBe('Roster.pdf')
        ->and($thread?->files[0]->name)->toBe('Roster.pdf');

    expect($this->get(route('chat.files', ['file' => $file->getKey()]))->assertOk()->headers->get('Content-Disposition'))->toContain('Roster.pdf');
    chatActor(name: 'Paolo Bautista');
    $this->get(route('chat.files', ['file' => $file->getKey()]))->assertNotFound();
});

it('lets anyone in a group chat use @everyone, and skips whoever muted it', function () {
    $andrea = chatUser('Andrea Lim');
    $jose = chatUser('Jose Rivera');
    $liza = chatUser('Liza Domingo');
    $me = chatActor();
    $id = chatStart($me, [$andrea, $jose, $liza], 'Ops');
    app(MuteConversation::class)->handle(chatAccess($liza), $id, true);

    chatSay($me, $id, 'Meeting in 5, @everyone. @Jose Rivera, bring the keys.');

    expect(ChatMessage::query()->where('kind', 'TEXT')->sole()->body)->toBe('Meeting in 5, @[everyone]. @['.$jose->getKey().'], bring the keys.')
        ->and(Notification::query()->where('user_id', $andrea->getKey())->value('title'))->toBe('Kim Reyes mentioned everyone in Ops')
        ->and(Notification::query()->where('user_id', $jose->getKey())->value('title'))->toBe('Kim Reyes mentioned you in Ops')
        ->and(Notification::query()->where('user_id', $liza->getKey())->exists())->toBeFalse();

    // The @ list offers everyone else in the chat, and @everyone.
    $options = app(ChatInbox::class)->mentionOptions(chatAccess($me), $id);
    expect(array_column($options['people'], 'name'))->toBe(['Andrea Lim', 'Jose Rivera', 'Liza Domingo'])
        ->and($options['everyone'])->toBeTrue()
        ->and(app(ChatInbox::class)->mentionOptions(chatAccess($me), chatStart($me, [$andrea]))['everyone'])->toBeFalse();
});

it('lets anyone in a group chat add people, rename it and change its photo, each saying so in the thread', function () {
    Storage::fake('local');
    $andrea = chatUser('Andrea Lim');
    $jose = chatUser('Jose Rivera');
    $grace = chatUser('Grace Mendoza');
    $me = chatActor();
    $id = chatStart($me, [$andrea, $jose], 'Ops');
    chatSay($andrea, $id, 'Before Grace joined');

    $this->travel(1)->seconds();
    expect(app(AddChatMembers::class)->handle(chatAccess($andrea), $id, [(int) $grace->getKey(), (int) $jose->getKey()]))->toBe(1);
    expect(chatUnread($grace, $id))->toBe(0)
        ->and(Notification::query()->where('user_id', $grace->getKey())->value('title'))->toBe('Andrea Lim added you to Ops');

    $this->travel(1)->seconds();
    app(RenameConversation::class)->handle(chatAccess($jose), $id, 'Depot Ops');
    $this->travel(1)->seconds();
    app(ChangeConversationPhoto::class)->handle(chatAccess($grace), $id, UploadedFile::fake()->image('truck.png', 500, 300));

    $conversation = ChatConversation::query()->findOrFail($id);
    expect($conversation->title)->toBe('Depot Ops')
        ->and($conversation->photo_path)->toStartWith('chat-photos/')
        ->and(ChatMessage::query()->where('conversation_id', $id)->where('kind', 'SYSTEM')->orderBy('created_at')->pluck('body')->all())->toBe([
            'Kim Reyes created the group',
            'Andrea Lim added Grace Mendoza',
            'Jose Rivera named the group Depot Ops',
            'Grace Mendoza changed the group photo',
        ]);

    $this->get(route('chat.conversations.photo', ['conversation' => $id]))->assertOk()->assertHeader('Content-Type', 'image/jpeg');

    // A direct chat has no people to add, no name and no photo.
    $direct = chatStart($me, [$andrea]);
    expect(fn () => app(AddChatMembers::class)->handle(chatAccess($me), $direct, [(int) $grace->getKey()]))
        ->toThrow(RuntimeException::class, 'People can only be added to a group chat.');
});

it('lets only admins remove people or make admins, and hands admin on when the last one leaves', function () {
    $andrea = chatUser('Andrea Lim');
    $jose = chatUser('Jose Rivera');
    $me = chatActor();
    $id = chatStart($me, [$andrea, $jose], 'Ops');
    $role = static fn (User $u): ?string => ChatParticipant::query()->where('conversation_id', $id)->where('user_id', $u->getKey())->value('role');

    // Whoever started it is the admin.
    expect($role($me))->toBe('ADMIN')
        ->and(app(ChatInbox::class)->thread(chatAccess($me), $id)?->admins)->toBe([(int) $me->getKey()]);

    expect(fn () => app(RemoveChatMember::class)->handle(chatAccess($andrea), $id, (int) $jose->getKey()))
        ->toThrow(RuntimeException::class, 'Only an admin of the group chat can remove people.');

    $this->travel(1)->seconds();
    app(RemoveChatMember::class)->handle(chatAccess($me), $id, (int) $jose->getKey());
    expect($role($jose))->toBeNull()
        ->and(app(ChatInbox::class)->thread(chatAccess($jose), $id))->toBeNull();

    // The last admin can't step down, and leaving hands it to whoever's been there longest.
    expect(fn () => app(SetChatAdmin::class)->handle(chatAccess($me), $id, (int) $me->getKey(), false))
        ->toThrow(RuntimeException::class, 'A group chat needs at least one admin.');
    $this->travel(1)->seconds();
    app(LeaveConversation::class)->handle(chatAccess($me), $id);
    expect($role($andrea))->toBe('ADMIN')
        ->and(ChatMessage::query()->where('conversation_id', $id)->where('kind', 'SYSTEM')->latest('created_at')->value('body'))->toBe('Andrea Lim is now an admin');
});

it('checks for news at the punch feeds\' cadence without realtime, and the sidebar badge every 60 seconds', function () {
    $me = chatActor();

    expect(ChatPage::pollSeconds())->toBe(15);
    Livewire::test(ChatPage::class)->assertSeeHtml('wire:poll.visible.15s="pollForChanges"');
    Livewire::test(UnreadBadge::class)->assertSeeHtml('wire:poll.visible.60s="pollForChanges"');

    // One setting moves the chat page with the punch feeds.
    config()->set('copems.poll_feed', 20);
    expect(ChatPage::pollSeconds())->toBe(20)
        ->and(LiveFeed::pollSeconds())->toBe(20);

    config()->set('copems.realtime.enabled', true);
    expect(ChatPage::pollSeconds())->toBe(60);
    Livewire::test(UnreadBadge::class)->assertSeeHtml('wire:poll.visible.60s="pollForChanges"');
    expect($me->exists)->toBeTrue();
});

it('shows the time again after 10 quiet minutes, puts no name over a reply, and tucks the next message in under it', function () {
    $andrea = chatUser('Andrea Lim');
    $jose = chatUser('Jose Rivera');
    $me = chatActor();
    $id = chatStart($me, [$andrea, $jose], 'Ops');
    $first = chatSay($andrea, $id, 'First');
    chatSay($andrea, $id, 'Right after');
    $this->travel(11)->minutes();
    chatSay($jose, $id, 'Much later', $first);
    chatSay($andrea, $id, 'Seen it');

    $thread = app(ChatInbox::class)->thread(chatAccess($me), $id);
    $rows = ChatThread::rows($thread->messages ?? [], CarbonImmutable::now());
    $stamps = array_values(array_filter(array_map(static fn (array $row): ?string => $row['stamp'], $rows)));
    $afterReply = array_map(static fn (array $row): bool => $row['afterReply'], $rows);

    // The start, then the pause. Nothing between two quick messages.
    expect($stamps)->toHaveCount(2)
        // Only the message right under the reply sits closer.
        ->and(array_keys(array_filter($afterReply)))->toBe([count($rows) - 1]);

    // A group event keeps its time out of sight until hovered.
    Livewire::test(ChatPage::class)
        ->call('openConversation', $id)
        ->assertSee('Jose replied to Andrea Lim')
        ->assertDontSeeHtml('<p class="mb-1 px-3 text-xs font-medium text-muted-foreground">Jose Rivera</p>')
        ->assertSeeHtml('x-anchor.right.offset.8="$refs.line"')
        ->assertDontSee('created the group ·');
});
