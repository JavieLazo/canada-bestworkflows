<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Events\RealtimeChange;
use App\Livewire\Community\CommunityPage;
use App\Livewire\Community\Support\CommunityLabels;
use App\Livewire\Social\PrivacyCard;
use App\Models\AuditLog;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\CommunityAttachment;
use App\Models\CommunityComment;
use App\Models\CommunityCommentReaction;
use App\Models\CommunityGroup;
use App\Models\CommunityGroupMember;
use App\Models\CommunityPost;
use App\Models\CommunityReaction;
use App\Models\CommunityTag;
use App\Models\EmployeeProfile;
use App\Models\Notification;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\SocialPreference;
use App\Models\User;
use App\Modules\IdentityAccess\Application\Commands\ManageAccounts;
use App\Modules\IdentityAccess\Contracts\ModuleAccounts;
use App\Modules\Social\Application\Attachments;
use App\Modules\Social\Application\Commands\AddGroupMembers;
use App\Modules\Social\Application\Commands\ChangeMembership;
use App\Modules\Social\Application\Commands\ChangePrivacy;
use App\Modules\Social\Application\Commands\CommentOnPost;
use App\Modules\Social\Application\Commands\CreateGroup;
use App\Modules\Social\Application\Commands\CreateTag;
use App\Modules\Social\Application\Commands\DecideJoinRequest;
use App\Modules\Social\Application\Commands\DeleteGroup;
use App\Modules\Social\Application\Commands\DeleteTag;
use App\Modules\Social\Application\Commands\EditComment;
use App\Modules\Social\Application\Commands\EditPost;
use App\Modules\Social\Application\Commands\PublishPost;
use App\Modules\Social\Application\Commands\ReactToPost;
use App\Modules\Social\Application\Commands\RemoveComment;
use App\Modules\Social\Application\Commands\RemoveGroupMember;
use App\Modules\Social\Application\Commands\RemovePost;
use App\Modules\Social\Application\Commands\SetGroupRole;
use App\Modules\Social\Application\Commands\UpdateGroup;
use App\Modules\Social\Application\Commands\UpdateTag;
use App\Modules\Social\Application\Privacy;
use App\Modules\Social\Application\Queries\CommunityFeed;
use App\Modules\Social\Application\SocialRules;
use App\Modules\Social\Contracts\FeedFilter;
use App\Modules\Social\Contracts\Views\CelebrationView;
use App\Modules\Social\Contracts\Views\FeedPage;
use App\Modules\Social\Contracts\Views\PersonView;
use App\Modules\Social\Contracts\Views\TagView;
use App\Modules\Social\Domain\Vocabulary;
use App\Modules\Social\Infrastructure\StorageUsage;
use App\Modules\Workforce\Contracts\ColleagueDirectory;
use App\Platform\Files\FileConsistency;
use App\Support\Access;
use App\Support\SocialFormat;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * The Community module: the company-wide feed.
 *
 * The route gates on the module grant like every other screen. Behind it the
 * Social module owns the rules these tests pin: a post goes to Everyone or to
 * one group its author is in; a private group's posts reach its members and
 * nobody else, down to the photos; only members react or reply in a group;
 * mentions are stored as account tokens and tell only people who can read
 * the post; one reaction per person; pinning, or removing someone else's
 * comment, is a moderator's alone and audited; A POST'S DELETE IS ITS
 * AUTHOR'S AND NOBODY ELSE'S, moderators included — someone else's post
 * goes only with its whole group; and an edit is its author's alone too:
 * only the author rewrites a post or comment, and only while they can take
 * part where it was posted, every earlier version of a post stays for
 * anyone who can read it, and a photo or file taken off stays for that
 * history until the post is deleted.
 */

/** @param list<string> $modules ModuleCode constants to grant */
function commRole(array $modules): Role
{
    $role = new Role;
    $role->forceFill(['name' => 'Community '.Str::random(6), 'all_data' => false, 'is_system' => false])->save();

    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }

    return $role;
}

/** @param list<string> $modules ModuleCode constants to grant */
function commUser(array $modules = ['COMMUNITY'], string $name = 'Ramon Aquino', ?string $employeeId = null): User
{
    $employeeId ??= commEmployee(Str::before($name, ' '), Str::after($name, ' '), '')->id;

    return User::factory()->create([
        'role_id' => commRole($modules)->getKey(),
        'employee_id' => $employeeId,
        'is_active' => true,
    ]);
}

/**
 * Signs in as a new account. Access is rebuilt once per request, so a test
 * that switches accounts drops the one already built.
 *
 * @param  list<string>  $modules  ModuleCode constants to grant
 */
function commActor(array $modules = ['COMMUNITY'], string $name = 'Kim Reyes', ?string $employeeId = null): User
{
    $user = commUser($modules, $name, $employeeId);
    commSignIn($user);

    return $user;
}

function commSignIn(User $user): void
{
    test()->actingAs($user);
    app()->forgetInstance(Access::class);
}

function commAccess(User $user): Access
{
    return Access::forUser($user->fresh() ?? $user);
}

/**
 * @param  list<User>  $members
 * @param  list<User>  $requested  asked to join, not yet admitted
 * @param  User|null  $owner  one of the members, who runs the group
 * @param  list<User>  $moderators  members who help run it
 */
function commGroup(string $name, bool $private = false, array $members = [], array $requested = [], ?User $owner = null, array $moderators = []): CommunityGroup
{
    $group = new CommunityGroup;
    $group->forceFill(['name' => $name, 'description' => 'About '.$name, 'is_private' => $private, 'created_by' => $owner?->getKey()])->save();

    $role = static fn (User $user): string => match (true) {
        $owner !== null && $user->is($owner) => 'OWNER',
        in_array($user->getKey(), array_map(static fn (User $m) => $m->getKey(), $moderators), true) => 'MODERATOR',
        default => 'MEMBER',
    };

    foreach ([...array_map(static fn (User $u): array => [$u, 'MEMBER'], $members), ...array_map(static fn (User $u): array => [$u, 'REQUESTED'], $requested)] as [$user, $status]) {
        $row = new CommunityGroupMember;
        $row->forceFill(['group_id' => $group->getKey(), 'user_id' => $user->getKey(), 'status' => $status, 'role' => $status === 'MEMBER' ? $role($user) : 'MEMBER'])->save();
    }

    return $group;
}

/** The main feed as someone sees it, with nothing filtered. */
function commFeed(User $viewer, ?FeedFilter $filter = null): FeedPage
{
    return app(CommunityFeed::class)->feed(commAccess($viewer), null, $filter ?? new FeedFilter, 10);
}

/** Something to attach: a real photo, or a real PDF. */
function commPdf(string $name = 'agenda.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n");
}

function commPost(User $author, string $body, ?CommunityGroup $group = null, ?string $tag = null, bool $pinned = false, int $minutesAgo = 5): CommunityPost
{
    $at = CarbonImmutable::now()->subMinutes($minutesAgo);
    $post = new CommunityPost;
    $post->forceFill([
        'author_id' => $author->getKey(),
        'group_id' => $group?->getKey(),
        'tag' => $tag,
        'body' => $body,
        'pinned_at' => $pinned ? $at : null,
        'pinned_by' => $pinned ? $author->getKey() : null,
        'created_at' => $at,
    ])->save();

    return $post;
}

/**
 * Broadcasting pointed at Pusher with throwaway keys, and the channel
 * callbacks loaded onto that driver: RealtimeBroadcastTest's setup, for the
 * one test here that asks /broadcasting/auth. Authorising a private channel
 * is local, so nothing leaves the process.
 */
function commUsePusher(): void
{
    config()->set('broadcasting.default', 'pusher');
    config()->set('broadcasting.connections.pusher.key', 'test-key');
    config()->set('broadcasting.connections.pusher.secret', 'test-secret');
    config()->set('broadcasting.connections.pusher.app_id', 'test-app-id');
    config()->set('broadcasting.connections.pusher.options.cluster', 'ap1');
    config()->set('copems.realtime.enabled', true);

    require base_path('routes/channels.php');
}

function commEmployee(string $first, string $last, string $position, string $hired = '2024-01-01', ?string $born = null): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => 'COM-'.random_int(10000, 99999),
        'first_name' => $first,
        'last_name' => $last,
        'position' => $position,
        'employment_status' => 'Regular',
        'date_hired' => $hired,
        'birth_date' => $born,
        'is_active' => true,
    ])->save();

    return $employee;
}

/*
|--------------------------------------------------------------------------
| Route gating
|--------------------------------------------------------------------------
*/

it('redirects a guest to login', function () {
    $this->get('/community')->assertRedirect(route('login'));
});

it('redirects an account whose role lacks COMMUNITY to its landing page', function () {
    commActor(['PORTAL']);

    $this->get('/community')->assertRedirect('/portal');
});

it('renders the feed, pins first, for a COMMUNITY holder', function () {
    $author = commUser(name: 'Maria Santos');
    commPost($author, 'Welcome to Community', tag: 'ANNOUNCEMENT', pinned: true, minutesAgo: 60);
    commPost($author, 'Lunch is on me today', minutesAgo: 1);
    commActor();

    $this->get('/community')
        ->assertOk()
        ->assertSeeInOrder(['Pinned', 'Welcome to Community', 'Latest', 'Lunch is on me today'])
        ->assertSee('Your Groups');
});

it('shows Community in the sidebar only to a role that holds it', function () {
    commActor(['PORTAL', 'COMMUNITY']);
    $this->get('/portal')->assertSee('href="'.url('/community').'"', false);

    commActor(['PORTAL']);
    $this->get('/portal')->assertDontSee('href="'.url('/community').'"', false);
});

it('names the author by account and shows their job title from their employee record', function () {
    $employee = commEmployee('Ramon', 'Aquino', 'Driver');
    $author = commUser(name: 'Ramon Aquino', employeeId: (string) $employee->getKey());
    commPost($author, 'New carinderia on Valero St.');
    commActor();

    Livewire::test(CommunityPage::class)
        ->assertSee('Ramon Aquino')
        ->assertSee('Driver')
        ->assertSee('New carinderia on Valero St.');
});

/*
|--------------------------------------------------------------------------
| Posting
|--------------------------------------------------------------------------
*/

it('publishes a post, stores typed mentions as tokens, and tells the person named', function () {
    $andrea = commUser(name: 'Andrea Lim');
    $me = commActor();

    Livewire::test(CommunityPage::class)
        ->set('draft', 'Thanks @Andrea Lim for the tumbler tip!')
        ->set('tag', 'KUDOS')
        ->call('publish')
        ->assertSet('draft', '')
        ->assertSeeText('Thanks @Andrea Lim for the tumbler tip!');

    $post = CommunityPost::query()->sole();
    expect($post->body)->toBe('Thanks @['.$andrea->getKey().'] for the tumbler tip!')
        ->and($post->author_id)->toBe((int) $me->getKey())
        ->and($post->group_id)->toBeNull()
        ->and($post->tag)->toBe('KUDOS');

    $bell = Notification::query()->where('user_id', $andrea->getKey())->sole();
    expect($bell->kind)->toBe('COMMUNITY_MENTION')
        ->and($bell->title)->toBe('Kim Reyes mentioned you in a post')
        ->and($bell->body)->toBe('Thanks @Andrea Lim for the tumbler tip!')
        ->and($bell->href)->toBe('/community?post='.$post->getKey());
    expect(Notification::query()->where('user_id', $me->getKey())->exists())->toBeFalse();
});

it('leaves a name as plain text when that person cannot use Community', function () {
    $outsider = commUser(['PORTAL'], name: 'Liza Domingo');
    commActor();

    Livewire::test(CommunityPage::class)
        ->set('draft', 'Ask @Liza Domingo about it.')
        ->call('publish');

    expect(CommunityPost::query()->sole()->body)->toBe('Ask @Liza Domingo about it.')
        ->and(Notification::query()->where('user_id', $outsider->getKey())->exists())->toBeFalse();
});

it('refuses an empty post and a tag that is not on the list', function () {
    commActor();

    Livewire::test(CommunityPage::class)
        ->set('draft', '   ')
        ->call('publish')
        ->set('draft', 'Hello')
        ->set('tag', 'RUMOUR')
        ->call('publish');

    expect(CommunityPost::query()->count())->toBe(0);
});

it('refuses a post to a group the author is not in, instead of posting it elsewhere', function () {
    $group = commGroup('Book Club', private: true);
    commActor();

    Livewire::test(CommunityPage::class)
        ->set('audience', (string) $group->getKey())
        ->set('draft', 'Can I join?')
        ->call('publish')
        ->assertSet('draft', 'Can I join?');

    expect(CommunityPost::query()->count())->toBe(0);
});

it('posts to a group its author is in', function () {
    $me = commActor();
    $group = commGroup('Lunch Club', members: [$me]);

    Livewire::test(CommunityPage::class)
        ->call('openGroup', (string) $group->getKey())
        ->assertSet('audience', (string) $group->getKey())
        ->set('draft', 'Who is buying today?')
        ->call('publish');

    expect(CommunityPost::query()->sole()->group_id)->toBe($group->getKey());
});

it('tells a person named in a private group post only if they are a member', function () {
    $member = commUser(name: 'Jose Rivera');
    $stranger = commUser(name: 'Grace Mendoza');
    $me = commActor();
    $group = commGroup('Book Club', private: true, members: [$me, $member]);

    app(PublishPost::class)->handle(commAccess($me), (string) $group->getKey(), null, 'Reading list from @Jose Rivera and @Grace Mendoza');

    expect(Notification::query()->where('user_id', $member->getKey())->where('kind', 'COMMUNITY_MENTION')->exists())->toBeTrue()
        ->and(Notification::query()->where('user_id', $stranger->getKey())->exists())->toBeFalse();
});

it('lets an author edit their own post, marks it Edited, and tells only the people the edit newly mentions', function () {
    $andrea = commUser(name: 'Andrea Lim');
    $jose = commUser(name: 'Jose Rivera');
    $me = commActor();
    $id = app(PublishPost::class)->handle(commAccess($me), null, null, 'Thanks @Andrea Lim!');

    // Saving it unchanged closes the box without marking it.
    Livewire::test(CommunityPage::class)
        ->set('edits.'.$id, 'Thanks @Andrea Lim!')
        ->call('saveEdit', $id, '')
        ->assertDispatched('community-edited', id: $id)
        ->assertDontSeeText('Edited');
    expect(CommunityPost::query()->find($id)?->edited_at)->toBeNull();

    Livewire::test(CommunityPage::class)
        ->set('edits.'.$id, 'Thanks @Andrea Lim and @Jose Rivera!')
        ->call('saveEdit', $id, '')
        ->assertSeeText('Thanks @Andrea Lim and @Jose Rivera!')
        ->assertSeeText('Edited');

    $post = CommunityPost::query()->find($id);
    expect($post?->body)->toBe('Thanks @['.$andrea->getKey().'] and @['.$jose->getKey().']!')
        ->and($post?->edited_at)->not->toBeNull()
        ->and(Notification::query()->where('user_id', $andrea->getKey())->count())->toBe(1)
        ->and(Notification::query()->where('user_id', $jose->getKey())->value('title'))->toBe('Kim Reyes mentioned you in a post');
});

it('keeps every earlier version of an edited post, for anyone who can see the post', function () {
    $andrea = commUser(name: 'Andrea Lim');
    $me = commActor();
    $edit = app(EditPost::class);
    $id = app(PublishPost::class)->handle(commAccess($me), null, null, 'Lunch at noon, @Andrea Lim');
    $posted = CommunityPost::query()->find($id)?->created_at?->format('Y-m-d H:i:s.v');

    $this->travel(5)->minutes();
    $edit->handle(commAccess($me), $id, 'Lunch at one', null);
    $edited = CommunityPost::query()->find($id)?->edited_at?->format('Y-m-d H:i:s.v');
    $this->travel(5)->minutes();
    $edit->handle(commAccess($me), $id, 'Lunch at one, in the pantry', null);
    // Saving it unchanged adds nothing.
    $edit->handle(commAccess($me), $id, 'Lunch at one, in the pantry', null);

    // Andrea sees them too: newest first, each with when it went up, with the name only the first one mentions.
    $history = app(CommunityFeed::class)->history(commAccess($andrea), $id);
    expect(array_column($history['versions'] ?? [], 'body'))->toBe(['Lunch at one, in the pantry', 'Lunch at one', 'Lunch at noon, @['.$andrea->getKey().']'])
        ->and($history['versions'][1]['at'] ?? null)->toBe($edited)
        ->and($history['versions'][2]['at'] ?? null)->toBe($posted)
        ->and($history['names'] ?? [])->toBe([$andrea->getKey() => 'Andrea Lim']);

    // "Edited" on the card opens them.
    commSignIn($andrea);
    Livewire::test(CommunityPage::class)
        ->assertDontSeeText('That post isn\'t here anymore.')
        ->call('openHistory', $id)
        ->assertSet('historyDialog', true)
        ->assertSeeText('Lunch at noon, @Andrea Lim');

    // Someone who can't see a post can't see its history either.
    $club = commGroup('Book Club', private: true, members: [$me]);
    $private = app(PublishPost::class)->handle(commAccess($me), (string) $club->getKey(), null, 'Members only');
    $edit->handle(commAccess($me), $private, 'Members only, chapter 3', null);
    expect(app(CommunityFeed::class)->history(commAccess($andrea), $private))->toBeNull()
        ->and(app(CommunityFeed::class)->history(commAccess($me), $private)['versions'] ?? [])->toHaveCount(2);
});

it('lets an author change a post\'s tag, photos and files, and keeps what came off in its history until the post goes', function () {
    Storage::fake('local');
    $andrea = commUser(name: 'Andrea Lim');
    $me = commActor();
    $edit = app(EditPost::class);
    $id = app(PublishPost::class)->handle(commAccess($me), null, 'KUDOS', 'Site visit', [
        UploadedFile::fake()->image('gate.png', 40, 40),
        UploadedFile::fake()->image('crew.png', 40, 40),
    ]);
    [$gate, $crew] = array_map('strval', CommunityAttachment::query()->where('post_id', $id)->orderBy('position')->pluck('id')->all());
    $gatePath = (string) CommunityAttachment::query()->whereKey($gate)->value('path');

    // From the card: a new tag, the gate photo off (an id from elsewhere is ignored), and an agenda on.
    Livewire::test(CommunityPage::class)
        ->set('edits.'.$id, 'Site visit')
        ->set('picked.e-'.$id, [commPdf()])
        ->call('saveEdit', $id, 'QUESTION', [$gate, 'not-on-this-post'])
        ->assertDispatched('community-edited', id: $id)
        ->assertSet('staged', []);

    $view = commFeed($andrea)->posts[0];
    expect($view->tag)->toBe('QUESTION')
        ->and($view->photos)->toBe([$crew])
        ->and(array_map(static fn ($file): string => $file->name, $view->files))->toBe(['agenda.pdf']);

    // The history shows both versions whole, and the photo that came off still opens.
    $history = app(CommunityFeed::class)->history(commAccess($andrea), $id);
    expect($history['versions'][0]['tag'] ?? null)->toBe('QUESTION')
        ->and($history['versions'][0]['photos'] ?? null)->toBe([$crew])
        ->and(count($history['versions'][0]['files'] ?? []))->toBe(1)
        ->and($history['versions'][1]['tag'] ?? null)->toBe('KUDOS')
        ->and($history['versions'][1]['photos'] ?? null)->toBe([$gate, $crew])
        ->and($history['versions'][1]['files'] ?? null)->toBe([])
        ->and(app(CommunityFeed::class)->photo(commAccess($andrea), $gate)->getStatusCode())->toBe(200)
        ->and(Storage::disk('local')->exists($gatePath))->toBeTrue();

    // Saving it as it is changes nothing; a tag that isn't listed, or an eleventh photo or file, is refused.
    $edit->handle(commAccess($me), $id, 'Site visit', 'QUESTION');
    expect(CommunityPost::query()->find($id)?->earlier_versions)->toHaveCount(1);
    $rows = CommunityAttachment::query()->count();
    $files = Storage::disk('local')->allFiles();
    expect(fn () => $edit->handle(commAccess($me), $id, 'Site visit', 'NOPE'))
        ->toThrow(RuntimeException::class, 'Pick one of the listed tags.');
    $nine = array_map(static fn (int $i): UploadedFile => UploadedFile::fake()->image("p{$i}.png", 10, 10), range(1, 9));
    expect(fn () => $edit->handle(commAccess($me), $id, 'Site visit', 'QUESTION', [], $nine))
        ->toThrow(RuntimeException::class, 'A post holds up to 10 photos and files.');
    $post = CommunityPost::query()->find($id);
    expect($post?->tag)->toBe('QUESTION')
        ->and($post?->earlier_versions)->toHaveCount(1)
        ->and(CommunityAttachment::query()->count())->toBe($rows)
        ->and(Storage::disk('local')->allFiles())->toBe($files);

    // Deleting the post takes every file with it, the one that came off too.
    app(RemovePost::class)->handle(commAccess($me), $id);
    expect(Storage::disk('local')->exists($gatePath))->toBeFalse();
});

it('brings back a photo the post had before instead of storing it twice', function () {
    Storage::fake('local');
    $me = commActor();
    $edit = app(EditPost::class);
    $first = UploadedFile::fake()->image('court.png', 40, 40);
    $bytes = (string) file_get_contents((string) $first->getRealPath());
    $id = app(PublishPost::class)->handle(commAccess($me), null, null, 'Court day', [$first]);
    $court = (string) CommunityAttachment::query()->where('post_id', $id)->value('id');

    // Taken off, then the same photo picked again, under another name.
    $edit->handle(commAccess($me), $id, 'Court day', null, [$court]);
    $edit->handle(commAccess($me), $id, 'Court day', null, [], [UploadedFile::fake()->createWithContent('court-again.png', $bytes)]);

    expect(CommunityAttachment::query()->where('post_id', $id)->pluck('id')->all())->toBe([$court])
        ->and(CommunityAttachment::query()->whereKey($court)->value('removed_at'))->toBeNull()
        ->and(commFeed($me)->posts[0]->photos)->toBe([$court])
        ->and(Storage::disk('local')->allFiles())->toHaveCount(1);

    // Every version that has it shows that one file.
    $history = app(CommunityFeed::class)->history(commAccess($me), $id);
    expect(array_column($history['versions'] ?? [], 'photos'))->toBe([[$court], [], [$court]]);
});

it('numbers a post\'s photos and files from 0 in the order they show, however many edits swap them', function () {
    Storage::fake('local');
    $me = commActor();
    $edit = app(EditPost::class);
    $photo = UploadedFile::fake()->image('day-0.png', 20, 20);
    $bytes = (string) file_get_contents((string) $photo->getRealPath());
    $id = app(PublishPost::class)->handle(commAccess($me), null, null, 'Progress photos', [$photo, commPdf()]);
    $first = array_map('strval', CommunityAttachment::query()->where('post_id', $id)->orderBy('position')->pluck('id')->all());

    // Thirty edits, each taking the oldest off and adding a photo of a new size,
    // so none of them comes back as an earlier one; then the first photo again.
    for ($day = 1; $day <= 30; $day++) {
        $oldest = (string) CommunityAttachment::query()->where('post_id', $id)->whereNull('removed_at')->orderBy('position')->value('id');
        $edit->handle(commAccess($me), $id, 'Progress photos', null, [$oldest], [UploadedFile::fake()->image("day-{$day}.png", 20 + $day, 20)]);
    }
    $edit->handle(commAccess($me), $id, 'Progress photos', null, [], [UploadedFile::fake()->createWithContent('day-0-again.png', $bytes)]);

    $live = CommunityAttachment::query()->where('post_id', $id)->whereNull('removed_at')->orderBy('position')->pluck('position', 'id')->all();
    expect(array_values($live))->toBe([0, 1, 2])
        ->and(array_map('strval', array_keys($live)))->toBe(commFeed($me)->posts[0]->photos)
        ->and(array_key_last($live))->toBe($first[0])
        ->and((int) CommunityAttachment::query()->where('post_id', $id)->max('position'))->toBeLessThanOrEqual(SocialRules::MAX_ATTACHMENTS)
        ->and(CommunityAttachment::query()->where('post_id', $id)->count())->toBe(32);

    // Each earlier version keeps its own order, whatever the numbers became.
    $history = app(CommunityFeed::class)->history(commAccess($me), $id);
    expect($history['versions'] ?? [])->toHaveCount(32)
        ->and($history['versions'][31]['photos'] ?? null)->toBe([$first[0]])
        ->and(array_map(static fn ($file): string => $file->name, $history['versions'][31]['files'] ?? []))->toBe(['agenda.pdf'])
        ->and($history['versions'][0]['photos'] ?? null)->toBe(array_map('strval', array_keys($live)));
});

it('refuses a save that lands on a version other than the one it started from, so no version drops out of the history', function () {
    $me = commActor();
    $edit = app(EditPost::class);
    $id = app(PublishPost::class)->handle(commAccess($me), null, null, 'Lunch at noon');

    // A second tab saves its edit after this save has read the post and
    // before it writes: its first look at the post's attachments comes
    // between the two.
    $saved = false;
    DB::listen(static function (QueryExecuted $query) use (&$saved, $edit, $me, $id): void {
        if (! $saved && str_contains($query->sql, 'community_attachments')) {
            $saved = true;
            $edit->handle(commAccess($me), $id, 'Lunch at one', null);
        }
    });

    expect(fn () => $edit->handle(commAccess($me), $id, 'Lunch at two', null))
        ->toThrow(RuntimeException::class, 'This post changed while you were editing it. Please try again.');

    $post = CommunityPost::query()->find($id);
    expect($saved)->toBeTrue()
        ->and($post?->body)->toBe('Lunch at one')
        ->and(array_column($post->earlier_versions ?? [], 'body'))->toBe(['Lunch at noon']);
});

it('holds the files an edit adds to the per-file cap and to Community and Chat\'s storage ceiling', function () {
    Storage::fake('local');
    $me = commActor();
    $edit = app(EditPost::class);
    $id = app(PublishPost::class)->handle(commAccess($me), null, null, 'Minutes', [commPdf()]);
    $rows = CommunityAttachment::query()->count();
    $files = Storage::disk('local')->allFiles();
    $pdf = commPdf('budget.pdf');
    $size = (int) $pdf->getSize();

    // One byte over the cap.
    $cap = config('copems.uploads.max_file_bytes');
    config()->set('copems.uploads.max_file_bytes', $size - 1);
    expect(fn () => $edit->handle(commAccess($me), $id, 'Minutes', null, [], [$pdf]))
        ->toThrow(RuntimeException::class, 'The limit is');
    config()->set('copems.uploads.max_file_bytes', $cap);

    // Room for everything stored already, and one byte short of this file.
    config()->set('copems.uploads.social_storage_ceiling_bytes', app(StorageUsage::class)->bytes() + $size - 1);
    expect(fn () => $edit->handle(commAccess($me), $id, 'Minutes', null, [], [$pdf]))
        ->toThrow(RuntimeException::class, Attachments::FULL);

    $post = CommunityPost::query()->find($id);
    expect(CommunityAttachment::query()->count())->toBe($rows)
        ->and(Storage::disk('local')->allFiles())->toBe($files)
        ->and($post?->edited_at)->toBeNull()
        ->and($post?->earlier_versions)->toBeNull();
});

it('refuses an edit to someone else\'s post, even from a moderator, and one that would leave a post empty', function () {
    $other = commPost(commUser(name: 'Maria Santos'), 'Not yours');
    $me = commActor(['COMMUNITY', 'USERS']);
    $mine = commPost($me, 'Mine');
    $photoPost = commPost($me, 'Look at this');
    $photo = new CommunityAttachment;
    $photo->forceFill(['post_id' => $photoPost->getKey(), 'kind' => 'PHOTO', 'path' => 'community-photos/look.png', 'mime' => 'image/png', 'size' => 3, 'position' => 0])->save();
    $edit = app(EditPost::class);

    // The card offers Edit on the viewer's own posts and nowhere else.
    $mayEdit = [];
    foreach (commFeed($me)->posts as $view) {
        $mayEdit[$view->body] = $view->canEdit;
    }
    expect($mayEdit)->toEqual(['Not yours' => false, 'Mine' => true, 'Look at this' => true]);

    expect(fn () => $edit->handle(commAccess($me), (string) $other->getKey(), 'Reworded', null))
        ->toThrow(RuntimeException::class, 'You can only edit your own posts.');
    expect(fn () => $edit->handle(commAccess($me), (string) $mine->getKey(), '   ', null))
        ->toThrow(RuntimeException::class, 'Write something, or delete the post instead.');

    // A post with a photo can do without words, the same as when it went up.
    $edit->handle(commAccess($me), (string) $photoPost->getKey(), '', null);
    expect($other->fresh()?->body)->toBe('Not yours')
        ->and($mine->fresh()?->body)->toBe('Mine')
        ->and($photoPost->fresh()?->body)->toBe('');
});

it('lets an author edit their own comment, marks it Edited, and tells only the people the edit newly mentions', function () {
    $andrea = commUser(name: 'Andrea Lim');
    $jose = commUser(name: 'Jose Rivera');
    $post = commPost(commUser(name: 'Maria Santos'), 'Lunch?');
    $me = commActor();
    $id = app(CommentOnPost::class)->handle(commAccess($me), (string) $post->getKey(), null, 'Yes, with @Andrea Lim');

    Livewire::test(CommunityPage::class)
        ->set('edits.'.$id, 'Yes, with @Andrea Lim and @Jose Rivera')
        ->call('saveCommentEdit', $id)
        ->assertDispatched('community-edited', id: $id)
        ->assertSeeText('Yes, with @Andrea Lim and @Jose Rivera')
        ->assertSeeText('Edited');

    $comment = CommunityComment::query()->find($id);
    expect($comment?->body)->toBe('Yes, with @['.$andrea->getKey().'] and @['.$jose->getKey().']')
        ->and($comment?->edited_at)->not->toBeNull()
        ->and(Notification::query()->where('user_id', $andrea->getKey())->count())->toBe(1)
        ->and(Notification::query()->where('user_id', $jose->getKey())->value('title'))->toBe('Kim Reyes mentioned you in a comment');
});

it('refuses an edit to someone else\'s comment, even from a moderator, and one that would leave it empty, and shows an edit to other people', function () {
    $maria = commUser(name: 'Maria Santos');
    $post = commPost($maria, 'Lunch?');
    $theirs = app(CommentOnPost::class)->handle(commAccess($maria), (string) $post->getKey(), null, 'Count me in');
    $me = commActor(['COMMUNITY', 'USERS']);
    $mine = app(CommentOnPost::class)->handle(commAccess($me), (string) $post->getKey(), null, 'Me too');
    $withPhoto = app(CommentOnPost::class)->handle(commAccess($me), (string) $post->getKey(), null, 'Look');
    $photo = new CommunityAttachment;
    $photo->forceFill(['post_id' => $post->getKey(), 'comment_id' => $withPhoto, 'kind' => 'PHOTO', 'path' => 'community-photos/look.png', 'mime' => 'image/png', 'size' => 3, 'position' => 0])->save();
    $edit = app(EditComment::class);

    expect(fn () => $edit->handle(commAccess($me), $theirs, 'Reworded'))
        ->toThrow(RuntimeException::class, 'You can only edit your own comments.');
    expect(fn () => $edit->handle(commAccess($me), $mine, '   '))
        ->toThrow(RuntimeException::class, 'Write something, or delete the comment instead.');
    expect(CommunityComment::query()->find($mine)?->body)->toBe('Me too')
        ->and(CommunityComment::query()->find($mine)?->edited_at)->toBeNull();

    // A comment with a photo can do without words, the same as when it went up.
    $edit->handle(commAccess($me), $withPhoto, '');

    // Comments have no updated_at, so Maria's feed has to notice the edit through edited_at.
    $before = app(CommunityFeed::class)->stamp(commAccess($maria));
    $this->travel(1)->seconds();
    $edit->handle(commAccess($me), $mine, 'Me too, see you there');

    expect(app(CommunityFeed::class)->stamp(commAccess($maria)))->not->toBe($before)
        ->and(CommunityComment::query()->find($theirs)?->body)->toBe('Count me in')
        ->and(CommunityComment::query()->find($withPhoto)?->body)->toBe('');
});

it('refuses an edit to a post or comment out of reach, a deleted comment, and someone else\'s, through the page and straight at the command', function () {
    $maria = commUser(name: 'Maria Santos');
    $me = commActor();
    $club = commGroup('Book Club', private: true, members: [$maria, $me]);
    $hidden = commPost($me, 'Chapter 3 tonight', $club);
    $hiddenComment = app(CommentOnPost::class)->handle(commAccess($me), (string) $hidden->getKey(), null, 'Bring snacks');
    $theirs = commPost($maria, 'Lunch?');
    $gone = app(CommentOnPost::class)->handle(commAccess($me), (string) $theirs->getKey(), null, 'Count me in');
    app(RemoveComment::class)->handle(commAccess($me), $gone);
    $theirComment = app(CommentOnPost::class)->handle(commAccess($maria), (string) $theirs->getKey(), null, 'Me too');
    // Out of the private group, the post and the comment there are still
    // the actor's own, but out of reach.
    app(ChangeMembership::class)->leave(commAccess($me), (string) $club->getKey());
    $told = Notification::query()->count();

    // Through the page, and straight at the command with no page in between.
    Livewire::test(CommunityPage::class)
        ->set('edits.'.$hidden->getKey(), 'Reworded')
        ->call('saveEdit', (string) $hidden->getKey(), '')
        ->set('edits.'.$theirs->getKey(), 'Reworded')
        ->call('saveEdit', (string) $theirs->getKey(), '')
        ->set('edits.'.$hiddenComment, 'Reworded')
        ->call('saveCommentEdit', $hiddenComment)
        ->set('edits.'.$gone, 'Reworded')
        ->call('saveCommentEdit', $gone)
        ->set('edits.'.$theirComment, 'Reworded')
        ->call('saveCommentEdit', $theirComment)
        ->assertNotDispatched('community-edited');
    $post = static fn (CommunityPost $target): Closure => static fn () => app(EditPost::class)->handle(commAccess($me), (string) $target->getKey(), 'Reworded', null);
    $comment = static fn (string $target): Closure => static fn () => app(EditComment::class)->handle(commAccess($me), $target, 'Reworded');
    expect($post($hidden))->toThrow(RuntimeException::class, 'That post was not found.')
        ->and($post($theirs))->toThrow(RuntimeException::class, 'You can only edit your own posts.')
        // A comment that's gone and one on a post out of reach answer alike.
        ->and($comment($gone))->toThrow(RuntimeException::class, 'That comment was not found.')
        ->and($comment($hiddenComment))->toThrow(RuntimeException::class, 'That comment was not found.')
        ->and($comment($theirComment))->toThrow(RuntimeException::class, 'You can only edit your own comments.');

    foreach ([$hidden, $theirs] as $target) {
        $fresh = CommunityPost::query()->find((string) $target->getKey());
        expect($fresh?->body)->toBe($target->body)
            ->and($fresh?->edited_at)->toBeNull()
            ->and($fresh?->earlier_versions)->toBeNull();
    }
    expect(CommunityComment::query()->whereKey($gone)->exists())->toBeFalse()
        ->and(CommunityComment::query()->find($hiddenComment)?->body)->toBe('Bring snacks')
        ->and(CommunityComment::query()->find($theirComment)?->body)->toBe('Me too')
        ->and(CommunityComment::query()->whereNotNull('edited_at')->count())->toBe(0)
        ->and(Notification::query()->count())->toBe($told);
});

it('refuses an edit from an author who is no longer in a public group, offers them no Edit there, and lets them edit again once back', function () {
    $owner = commUser(name: 'Maria Santos');
    $me = commActor();
    $club = commGroup('Lunch Club', members: [$owner, $me], owner: $owner);
    $post = commPost($me, 'Lunch at noon', $club);
    $comment = app(CommentOnPost::class)->handle(commAccess($me), (string) $post->getKey(), null, 'Bring snacks');
    app(RemoveGroupMember::class)->handle(commAccess($owner), (string) $club->getKey(), (int) $me->getKey());
    $told = Notification::query()->count();

    // The group is public, so the post is still in reach and still theirs,
    // but rewording it is taking part, like commenting.
    $views = app(CommunityFeed::class)->feed(commAccess($me), (string) $club->getKey(), new FeedFilter, 10)->posts;
    expect(array_map(static fn ($view): array => [$view->body, $view->canEdit, $view->canDelete], $views))
        ->toEqual([['Lunch at noon', false, true]]);

    Livewire::test(CommunityPage::class)
        ->call('openGroup', (string) $club->getKey())
        ->assertSee('Lunch at noon')
        ->assertDontSee('Edit Post')
        ->assertDontSee('Edit Comment')
        ->set('edits.'.$post->getKey(), 'Lunch at one')
        ->call('saveEdit', (string) $post->getKey(), '')
        ->set('edits.'.$comment, 'Bring drinks')
        ->call('saveCommentEdit', $comment)
        ->assertNotDispatched('community-edited');
    expect(static fn () => app(EditPost::class)->handle(commAccess($me), (string) $post->getKey(), 'Lunch at one', null))
        ->toThrow(RuntimeException::class, 'Join the group to edit your posts in it.')
        ->and(static fn () => app(EditComment::class)->handle(commAccess($me), $comment, 'Bring drinks'))
        ->toThrow(RuntimeException::class, 'Join the group to edit your comments in it.');

    $fresh = CommunityPost::query()->find((string) $post->getKey());
    expect($fresh?->body)->toBe('Lunch at noon')
        ->and($fresh?->edited_at)->toBeNull()
        ->and($fresh?->earlier_versions)->toBeNull()
        ->and(CommunityComment::query()->find($comment)?->body)->toBe('Bring snacks')
        ->and(CommunityComment::query()->whereNotNull('edited_at')->count())->toBe(0)
        ->and(Notification::query()->count())->toBe($told);

    // Back in the group, both are theirs to edit again.
    app(ChangeMembership::class)->join(commAccess($me), (string) $club->getKey());
    app(EditPost::class)->handle(commAccess($me), (string) $post->getKey(), 'Lunch at one', null);
    app(EditComment::class)->handle(commAccess($me), $comment, 'Bring drinks');

    expect(CommunityPost::query()->find((string) $post->getKey())?->body)->toBe('Lunch at one')
        ->and(CommunityComment::query()->find($comment)?->body)->toBe('Bring drinks');
});

it('closes every redraw marker it opens, so a body that gains a mention can redraw in place', function () {
    $andrea = commUser(name: 'Andrea Lim');
    $me = commActor();
    $post = commPost($me, 'Thanks @['.$andrea->getKey().'] and @everyone!');
    app(CommentOnPost::class)->handle(commAccess($me), (string) $post->getKey(), null, 'And @Andrea Lim again');

    // Livewire redraws a block by finding the end marker that matches its
    // start. One missing end, and the first edit that adds a mention breaks
    // the card halfway through redrawing it.
    preg_match('/<article\s+id="'.$post->getKey().'".*?<\/article>/s', Livewire::test(CommunityPage::class)->html(), $card);
    expect(substr_count($card[0], '<!--[if BLOCK]><![endif]-->'))->toBeGreaterThan(0)
        ->toBe(substr_count($card[0], '<!--[if ENDBLOCK]><![endif]-->'));
});

/*
|--------------------------------------------------------------------------
| Who sees what
|--------------------------------------------------------------------------
*/

it('shows Everyone and the viewer\'s own groups on the main feed, and other groups only when opened', function () {
    $author = commUser(name: 'Andrea Lim');
    $me = commActor();
    $mine = commGroup('Lunch Club', members: [$me, $author]);
    $theirs = commGroup('Saturday Hoops', members: [$author]);
    commPost($author, 'To everyone');
    commPost($author, 'To lunch club', $mine);
    commPost($author, 'To hoops', $theirs);

    Livewire::test(CommunityPage::class)
        ->assertSee('To everyone')
        ->assertSee('To lunch club')
        ->assertDontSee('To hoops')
        ->call('openGroup', (string) $theirs->getKey())
        ->assertSee('To hoops')
        ->assertSee('Join Saturday Hoops to react and comment.');
});

it('keeps a private group\'s posts from everyone outside it, even with the post id', function () {
    $author = commUser(name: 'Liza Domingo');
    $group = commGroup('Book Club', private: true, members: [$author]);
    $post = commPost($author, 'Secret reading list', $group);
    $me = commActor();

    Livewire::test(CommunityPage::class)
        ->assertDontSee('Secret reading list')
        ->call('openGroup', (string) $group->getKey())
        ->assertSee('This group is private.')
        ->assertDontSee('Secret reading list');

    $this->get('/community?post='.$post->getKey())
        ->assertOk()
        ->assertDontSee('Secret reading list');

    expect(fn () => app(ReactToPost::class)->handle(commAccess($me), (string) $post->getKey(), 'LIKE'))
        ->toThrow(RuntimeException::class, 'That post was not found.');
});

it('opens the post a notification links to above the feed', function () {
    $author = commUser(name: 'Andrea Lim');
    $post = commPost($author, 'The linked post', minutesAgo: 600);
    commActor();

    $this->get('/community?post='.$post->getKey())
        ->assertOk()
        ->assertSeeInOrder(['Back to the Feed', 'The linked post']);
});

it('finds posts by their text, their author, or a person they mention', function () {
    $grace = commUser(name: 'Grace Mendoza');
    $author = commUser(name: 'Andrea Lim');
    commPost($author, 'Generators checked');
    commPost($grace, 'Coffee is ready');
    commPost($author, 'Shout-out to @['.$grace->getKey().'] for the double shift');
    commActor();

    Livewire::test(CommunityPage::class)
        ->set('search', 'generator')
        ->assertSee('Generators checked')
        ->assertDontSee('Coffee is ready')
        ->set('search', 'grace')
        ->assertSee('Coffee is ready')
        ->assertSee('for the double shift')
        ->assertDontSee('Generators checked');
});

it('filters by tag, and puts pins back in date order while a filter is on', function () {
    $author = commUser(name: 'Maria Santos');
    commPost($author, 'Pinned announcement', tag: 'ANNOUNCEMENT', pinned: true, minutesAgo: 90);
    commPost($author, 'Fresh announcement', tag: 'ANNOUNCEMENT', minutesAgo: 10);
    commPost($author, 'Just chatting', tag: 'SOCIAL', minutesAgo: 5);
    commActor();

    $page = Livewire::test(CommunityPage::class)
        ->set('filterTag', 'ANNOUNCEMENT')
        ->assertDontSee('Just chatting')
        ->assertSee('Tag: Announcement');
    $page->assertSeeInOrder(['Fresh announcement', 'Pinned announcement']);

    $page->call('dropFilter', 'tag')
        ->assertSee('Just chatting')
        // A tag that doesn't exist filters nothing, rather than hiding everything.
        ->set('filterTag', 'RUMOUR')
        ->assertSee('Just chatting');
});

it('pages ten posts at a time, and sorts by the most discussed', function () {
    $author = commUser(name: 'Maria Santos');
    foreach (range(1, 12) as $n) {
        commPost($author, "Post number {$n}.", minutesAgo: 100 - $n);
    }
    $oldest = CommunityPost::query()->where('body', 'Post number 1.')->sole();
    $comment = new CommunityComment;
    $comment->forceFill(['post_id' => $oldest->getKey(), 'author_id' => $author->getKey(), 'body' => 'First!'])->save();
    commActor();

    Livewire::test(CommunityPage::class)
        ->assertSee('Post number 12.')
        ->assertSee('Post number 3.')
        ->assertDontSee('Post number 2.')
        ->assertSee('Show More Posts')
        ->call('showMore')
        ->assertSee('Post number 1.')
        ->assertDontSee('Show More Posts');

    Livewire::test(CommunityPage::class)
        ->set('sort', 'discussed')
        ->assertSeeInOrder(['Post number 1.', 'Post number 12.']);
});

/*
|--------------------------------------------------------------------------
| Reacting, commenting, liking
|--------------------------------------------------------------------------
*/

it('keeps one reaction per person: a second click takes it back, another replaces it', function () {
    $author = commUser(name: 'Andrea Lim');
    $post = commPost($author, 'Hello');
    $me = commActor();
    $id = (string) $post->getKey();

    $page = Livewire::test(CommunityPage::class)->call('react', $id, 'LIKE');
    expect(CommunityReaction::query()->where('post_id', $id)->where('user_id', $me->getKey())->value('type'))->toBe('LIKE');

    $page->call('react', $id, 'LOVE');
    expect(CommunityReaction::query()->where('post_id', $id)->count())->toBe(1)
        ->and(CommunityReaction::query()->where('post_id', $id)->value('type'))->toBe('LOVE');

    $page->call('react', $id, 'LOVE');
    expect(CommunityReaction::query()->where('post_id', $id)->count())->toBe(0);

    $page->call('react', $id, 'ANGRY');
    expect(CommunityReaction::query()->where('post_id', $id)->count())->toBe(0);
});

it('lets only members react and comment in a group', function () {
    $author = commUser(name: 'Nathaniel Cruz');
    $group = commGroup('Saturday Hoops', members: [$author]);
    $post = commPost($author, 'Court is booked', $group);
    commActor();
    $id = (string) $post->getKey();

    Livewire::test(CommunityPage::class)
        ->call('react', $id, 'LIKE')
        ->set("drafts.{$id}", 'Count me in')
        ->call('comment', $id);

    expect(CommunityReaction::query()->count())->toBe(0)
        ->and(CommunityComment::query()->count())->toBe(0);
});

it('threads a reply under its comment and tells the post and comment authors once each', function () {
    $author = commUser(name: 'Maria Santos');
    $commenter = commUser(name: 'Jose Rivera');
    $post = commPost($author, 'Town hall on Friday');
    $first = new CommunityComment;
    $first->forceFill(['post_id' => $post->getKey(), 'author_id' => $commenter->getKey(), 'body' => 'Will it be recorded?'])->save();
    commActor();
    $postId = (string) $post->getKey();
    $commentId = (string) $first->getKey();

    Livewire::test(CommunityPage::class)
        ->set("drafts.{$commentId}", 'Yes, by Monday.')
        ->call('reply', $postId, $commentId)
        ->assertSet("drafts.{$commentId}", '')
        ->assertSee('Yes, by Monday.');

    $reply = CommunityComment::query()->where('body', 'Yes, by Monday.')->sole();
    expect($reply->parent_id)->toBe($commentId);

    expect(Notification::query()->where('user_id', $author->getKey())->pluck('kind')->all())->toBe(['COMMUNITY_COMMENT'])
        ->and(Notification::query()->where('user_id', $commenter->getKey())->pluck('kind')->all())->toBe(['COMMUNITY_REPLY']);
});

it('nests replies three levels deep, folds them by default, and puts a reply at the deepest level beside it', function () {
    $author = commUser(name: 'Maria Santos');
    $post = commPost($author, 'Hello');
    $top = new CommunityComment;
    $top->forceFill(['post_id' => $post->getKey(), 'author_id' => $author->getKey(), 'body' => 'Top'])->save();
    $reply = new CommunityComment;
    $reply->forceFill(['post_id' => $post->getKey(), 'parent_id' => $top->getKey(), 'author_id' => $author->getKey(), 'body' => 'Reply'])->save();
    $me = commActor();

    Livewire::test(CommunityPage::class)
        ->set('drafts.'.$reply->getKey(), 'Reply to the reply')
        ->call('reply', (string) $post->getKey(), (string) $reply->getKey())
        // Folded until asked for: the count shows, the replies wait behind it.
        ->assertSee('2 replies');

    $third = CommunityComment::query()->where('body', 'Reply to the reply')->sole();
    expect($third->parent_id)->toBe($reply->getKey());

    $thread = commFeed($me)->posts[0]->comments[0];
    expect($thread->depth)->toBe(0)
        ->and($thread->replyTotal())->toBe(2)
        ->and($thread->replies[0]->replies[0]->body)->toBe('Reply to the reply')
        ->and($thread->replies[0]->replies[0]->depth)->toBe(2);

    // Replying to the deepest comment works: the reply sits beside it, under
    // the same parent, and whoever wrote it is still told.
    $deepAuthor = commUser(name: 'Jose Rivera');
    $third->forceFill(['author_id' => $deepAuthor->getKey()])->save();
    app(CommentOnPost::class)->handle(commAccess($me), (string) $post->getKey(), (string) $third->getKey(), '@Jose Rivera same here');
    $fourth = CommunityComment::query()->where('body', '@['.$deepAuthor->getKey().'] same here')->sole();
    expect($fourth->parent_id)->toBe($reply->getKey())
        ->and(CommunityComment::query()->count())->toBe(4)
        ->and(Notification::query()->where('user_id', $deepAuthor->getKey())->pluck('kind')->all())->toBe(['COMMUNITY_REPLY']);
});

it('never tells the author about their own comment', function () {
    $me = commActor();
    $post = commPost($me, 'My own post');

    Livewire::test(CommunityPage::class)
        ->set('drafts.'.$post->getKey(), 'Adding a note')
        ->call('comment', (string) $post->getKey());

    expect(CommunityComment::query()->count())->toBe(1)
        ->and(Notification::query()->count())->toBe(0);
});

it('takes any of the six reactions on a comment, and lists who reacted', function () {
    $author = commUser(name: 'Maria Santos');
    $post = commPost($author, 'Hello');
    $comment = new CommunityComment;
    $comment->forceFill(['post_id' => $post->getKey(), 'author_id' => $author->getKey(), 'body' => 'Nice'])->save();
    $me = commActor(name: 'Kim Reyes');
    $id = (string) $comment->getKey();

    $page = Livewire::test(CommunityPage::class)->call('reactToComment', $id, 'LOVE');
    expect(CommunityCommentReaction::query()->sole()->type)->toBe('LOVE');

    // The same one again takes it back; a different one replaces it.
    $page->call('reactToComment', $id, 'LOVE');
    expect(CommunityCommentReaction::query()->count())->toBe(0);
    $page->call('reactToComment', $id, 'LIKE')->call('reactToComment', $id, 'FUNNY');
    expect(CommunityCommentReaction::query()->sole()->type)->toBe('FUNNY');

    $view = commFeed($me)->posts[0]->comments[0];
    expect($view->reactions)->toBe(['FUNNY' => [(int) $me->getKey()]])
        ->and($view->reaction)->toBe('FUNNY');

    $page->assertSee('1 reaction. Show who reacted')->assertSee('Kim Reyes');
});

it('lists who reacted to a post, with the reaction each one picked', function () {
    $author = commUser(name: 'Maria Santos');
    $post = commPost($author, 'Town hall on Friday');
    $andrea = commUser(name: 'Andrea Lim');
    $me = commActor(name: 'Kim Reyes');

    app(ReactToPost::class)->handle(commAccess($andrea), (string) $post->getKey(), 'CELEBRATE');
    app(ReactToPost::class)->handle(commAccess($me), (string) $post->getKey(), 'LIKE');

    $view = commFeed($me)->posts[0];
    expect($view->reactions)->toBe(['CELEBRATE' => [(int) $andrea->getKey()], 'LIKE' => [(int) $me->getKey()]])
        ->and($view->reactionCounts())->toBe(['CELEBRATE' => 1, 'LIKE' => 1])
        ->and($view->mine)->toBe('LIKE');

    Livewire::test(CommunityPage::class)
        ->assertSee('2 reactions. Show who reacted')
        ->assertSee('You and 1 other')
        ->assertSee('Andrea Lim');
});

/*
|--------------------------------------------------------------------------
| Moderation
|--------------------------------------------------------------------------
*/

it('refuses a pin from someone who is not a moderator', function () {
    $post = commPost(commUser(name: 'Maria Santos'), 'Pin me');
    commActor();

    Livewire::test(CommunityPage::class)->call('togglePin', (string) $post->getKey(), true);

    expect($post->fresh()?->pinned_at)->toBeNull()
        ->and(AuditLog::query()->count())->toBe(0);
});

it('lets a moderator pin a post to Everyone, audited, but not a group post or a fourth pin', function () {
    $author = commUser(name: 'Maria Santos');
    $post = commPost($author, 'Pin me');
    $moderator = commActor(['COMMUNITY', 'USERS'], 'Kim Reyes');
    $group = commGroup('Lunch Club', members: [$author, $moderator]);
    $groupPost = commPost($author, 'Group news', $group);

    Livewire::test(CommunityPage::class)->call('togglePin', (string) $post->getKey(), true);

    expect($post->fresh()?->pinned_at)->not->toBeNull()
        ->and($post->fresh()?->pinned_by)->toBe((int) $moderator->getKey());
    $audit = AuditLog::query()->where('action', 'COMMUNITY_POST_PINNED')->sole();
    expect($audit->entity_id)->toBe((string) $post->getKey())
        ->and($audit->details)->toBe('Pinned a post by Maria Santos');

    Livewire::test(CommunityPage::class)->call('togglePin', (string) $groupPost->getKey(), true);
    expect($groupPost->fresh()?->pinned_at)->toBeNull();

    commPost($author, 'Second', pinned: true);
    commPost($author, 'Third', pinned: true);
    $fourth = commPost($author, 'Fourth');
    Livewire::test(CommunityPage::class)->call('togglePin', (string) $fourth->getKey(), true);
    expect($fourth->fresh()?->pinned_at)->toBeNull();

    Livewire::test(CommunityPage::class)->call('togglePin', (string) $post->getKey(), false);
    expect($post->fresh()?->pinned_at)->toBeNull()
        ->and(AuditLog::query()->where('action', 'COMMUNITY_POST_UNPINNED')->exists())->toBeTrue();
});

it('lets an author delete their own post with its thread and photos, unaudited, but not someone else\'s', function () {
    Storage::fake('local');
    $maria = commUser(name: 'Maria Santos');
    $other = commPost($maria, 'Not yours');
    $me = commActor();
    $mine = commPost($me, 'Mine to delete');
    Storage::disk('local')->put('community-photos/mine.png', 'png');
    $photo = new CommunityAttachment;
    $photo->forceFill(['post_id' => $mine->getKey(), 'kind' => 'PHOTO', 'path' => 'community-photos/mine.png', 'mime' => 'image/png', 'size' => 3, 'position' => 0])->save();
    $comment = new CommunityComment;
    $comment->forceFill(['post_id' => $mine->getKey(), 'author_id' => $maria->getKey(), 'body' => 'Thread'])->save();

    $mayDelete = [];
    foreach (commFeed($me)->posts as $view) {
        $mayDelete[$view->body] = $view->canDelete;
    }
    expect($mayDelete)->toEqual(['Not yours' => false, 'Mine to delete' => true]);

    Livewire::test(CommunityPage::class)
        ->call('deletePost', (string) $other->getKey())
        ->call('deletePost', (string) $mine->getKey());

    expect(CommunityPost::query()->pluck('body')->all())->toBe(['Not yours'])
        ->and(CommunityComment::query()->count())->toBe(0)
        ->and(CommunityAttachment::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
    Storage::disk('local')->assertMissing('community-photos/mine.png');
});

it('refuses a moderator someone else\'s post, whatever their role holds: it stays with its thread and photos', function () {
    Storage::fake('local');
    $author = commUser(name: 'Ramon Aquino');
    $post = commPost($author, 'Off-topic');
    Storage::disk('local')->put('community-photos/off-topic.png', 'png');
    $photo = new CommunityAttachment;
    $photo->forceFill(['post_id' => $post->getKey(), 'kind' => 'PHOTO', 'path' => 'community-photos/off-topic.png', 'mime' => 'image/png', 'size' => 3, 'position' => 0])->save();
    $comment = new CommunityComment;
    $comment->forceFill(['post_id' => $post->getKey(), 'author_id' => $author->getKey(), 'body' => 'Thread'])->save();
    $pinned = commPost($author, 'Pinned notice', pinned: true);
    $hidden = commPost($author, 'Book talk', commGroup('Book Club', private: true, members: [$author]));
    // The widest role there is: a moderator (COMMUNITY + USERS) with all_data.
    $moderator = commActor(['COMMUNITY', 'USERS']);
    Role::query()->whereKey($moderator->role_id)->update(['all_data' => true]);
    commSignIn($moderator);
    expect(commAccess($moderator)->mayReadAllData(ModuleCode::COMMUNITY))->toBeTrue()
        ->and(app(Access::class)->mayReadAllData(ModuleCode::COMMUNITY))->toBeTrue();
    $own = commPost($moderator, 'From the moderator');

    // The card still offers the pin, and the delete only on the moderator's own post.
    $feed = commFeed($moderator);
    $offered = [];
    foreach ([...$feed->pinned, ...$feed->posts] as $view) {
        $offered[$view->body] = [$view->canPin, $view->canDelete];
    }
    expect($offered)->toEqual(['Pinned notice' => [true, false], 'Off-topic' => [true, false], 'From the moderator' => [true, true]]);

    // Through the page, and straight at the command with no page in between.
    Livewire::test(CommunityPage::class)
        ->call('deletePost', (string) $post->getKey())
        ->call('deletePost', (string) $pinned->getKey())
        ->call('deletePost', (string) $hidden->getKey());
    $remove = static fn (CommunityPost $target): Closure => static fn () => app(RemovePost::class)->handle(commAccess($moderator), (string) $target->getKey());
    expect($remove($post))->toThrow(RuntimeException::class, 'You can only delete your own posts.')
        ->and($remove($pinned))->toThrow(RuntimeException::class, 'You can only delete your own posts.')
        // A post out of reach answers like one that isn't there.
        ->and($remove($hidden))->toThrow(RuntimeException::class, 'That post was not found.');

    expect(CommunityPost::query()->count())->toBe(4)
        ->and(CommunityComment::query()->count())->toBe(1)
        ->and(CommunityAttachment::query()->count())->toBe(1)
        ->and(AuditLog::query()->count())->toBe(0);
    Storage::disk('local')->assertExists('community-photos/off-topic.png');

    // Their own goes like anyone's: deleted, and not audited.
    Livewire::test(CommunityPage::class)->call('deletePost', (string) $own->getKey());

    expect(CommunityPost::query()->whereKey($own->getKey())->exists())->toBeFalse()
        ->and(CommunityPost::query()->count())->toBe(3)
        ->and(AuditLog::query()->count())->toBe(0);
});

it('refuses a group\'s owner a member\'s post there, and offers them no delete on it', function () {
    $maria = commUser(name: 'Maria Santos');
    $owner = commActor(['COMMUNITY', 'USERS']);
    $post = commPost($maria, 'Group news', commGroup('Lunch Club', members: [$owner, $maria], owner: $owner));

    $offered = [];
    foreach (commFeed($owner)->posts as $view) {
        $offered[$view->body] = [$view->canPin, $view->canDelete];
    }
    expect($offered)->toEqual(['Group news' => [false, false]]);

    Livewire::test(CommunityPage::class)->call('deletePost', (string) $post->getKey());
    expect(static fn () => app(RemovePost::class)->handle(commAccess($owner), (string) $post->getKey()))
        ->toThrow(RuntimeException::class, 'You can only delete your own posts.');

    expect(CommunityPost::query()->whereKey($post->getKey())->exists())->toBeTrue()
        ->and(AuditLog::query()->count())->toBe(0);
});

it('counts authorship by account: the same person\'s second account and a post with no author are refused', function () {
    $maria = commUser(name: 'Maria Santos');
    $post = commPost($maria, 'From my first account');
    $orphan = commPost($maria, 'No author now');
    $orphan->forceFill(['author_id' => null])->save();
    // One employee, two accounts: the same person, but not the account that posted.
    $second = commActor(['COMMUNITY', 'USERS'], 'Maria Santos', (string) $maria->employee_id);

    $mayDelete = [];
    foreach (commFeed($second)->posts as $view) {
        $mayDelete[$view->body] = $view->canDelete;
    }
    expect($mayDelete)->toEqual(['From my first account' => false, 'No author now' => false]);

    Livewire::test(CommunityPage::class)
        ->call('deletePost', (string) $post->getKey())
        ->call('deletePost', (string) $orphan->getKey());

    expect(CommunityPost::query()->count())->toBe(2)
        ->and(AuditLog::query()->count())->toBe(0);
});

it('draws Delete post on the viewer\'s own post only, so a moderator sees the pin without it', function () {
    $maria = commUser(name: 'Maria Santos');
    commPost($maria, 'Not yours');
    $moderator = commActor(['COMMUNITY', 'USERS']);

    Livewire::test(CommunityPage::class)
        ->assertSee('Pin to the Top of the Feed')
        ->assertDontSee('Delete Post')
        ->assertDontSee('Remove this post for everyone?');

    // Both items on the moderator's own card, and still only the pin on Maria's.
    commPost($moderator, 'From the moderator');
    $html = Livewire::test(CommunityPage::class)->html();
    expect(substr_count($html, 'Pin to the Top of the Feed'))->toBe(2)
        ->and(substr_count($html, 'Delete this post?'))->toBe(1);

    commSignIn($maria);

    Livewire::test(CommunityPage::class)
        ->assertSee('Delete Post')
        ->assertDontSee('Pin to the Top of the Feed');
});

it('lets an author delete their own comment with every reply under it and their files, unaudited', function () {
    Storage::fake('local');
    $maria = commUser(name: 'Maria Santos');
    $post = commPost($maria, 'Town hall on Friday');
    $me = commActor();
    $comment = static function (User $author, string $body, ?CommunityComment $parent = null) use ($post): CommunityComment {
        $row = new CommunityComment;
        $row->forceFill(['post_id' => $post->getKey(), 'parent_id' => $parent?->getKey(), 'author_id' => $author->getKey(), 'body' => $body])->save();

        return $row;
    };
    $theirs = $comment($maria, 'Not yours');
    $mine = $comment($me, 'Mine to delete');
    $reply = $comment($maria, 'A reply', $mine);
    $comment($me, 'Deeper', $reply);
    Storage::disk('local')->put('community-files/notes.pdf', '%PDF');
    $file = new CommunityAttachment;
    $file->forceFill(['post_id' => $post->getKey(), 'comment_id' => $reply->getKey(), 'kind' => 'FILE', 'path' => 'community-files/notes.pdf', 'name' => 'notes.pdf', 'mime' => 'application/pdf', 'size' => 4, 'position' => 0])->save();

    $comments = commFeed($me)->posts[0]->comments;
    expect(array_map(static fn ($c) => [$c->body, $c->canDelete], $comments))->toBe([['Not yours', false], ['Mine to delete', true]]);

    Livewire::test(CommunityPage::class)
        ->call('deleteComment', (string) $theirs->getKey())
        ->call('deleteComment', (string) $mine->getKey());

    expect(CommunityComment::query()->pluck('body')->all())->toBe(['Not yours'])
        ->and(CommunityAttachment::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
    Storage::disk('local')->assertMissing('community-files/notes.pdf');
});

it('lets a moderator remove someone else\'s comment, audited, but not one on a post they can\'t read', function () {
    $ramon = commUser(name: 'Ramon Aquino');
    $open = commPost($ramon, 'Lunch?');
    $hidden = commPost($ramon, 'Book talk', commGroup('Book Club', private: true, members: [$ramon]));
    $comment = static function (CommunityPost $post, string $body) use ($ramon): CommunityComment {
        $row = new CommunityComment;
        $row->forceFill(['post_id' => $post->getKey(), 'author_id' => $ramon->getKey(), 'body' => $body])->save();

        return $row;
    };
    $offTopic = $comment($open, 'Off-topic');
    $private = $comment($hidden, 'Only for the club');
    commActor(['COMMUNITY', 'USERS']);

    Livewire::test(CommunityPage::class)
        ->call('deleteComment', (string) $private->getKey())
        ->call('deleteComment', (string) $offTopic->getKey());

    expect(CommunityComment::query()->pluck('body')->all())->toBe(['Only for the club'])
        ->and(AuditLog::query()->where('action', 'COMMUNITY_COMMENT_REMOVED')->value('details'))->toBe('Comment by Ramon Aquino removed from Community');
});

/*
|--------------------------------------------------------------------------
| Groups
|--------------------------------------------------------------------------
*/

it('joins a public group at once, but only files a request for a private one', function () {
    $public = commGroup('Lunch Club');
    $private = commGroup('Book Club', private: true);
    $me = commActor();

    Livewire::test(CommunityPage::class)
        ->call('join', (string) $public->getKey())
        ->call('join', (string) $private->getKey());

    expect(CommunityGroupMember::query()->where('user_id', $me->getKey())->pluck('status', 'group_id')->all())->toBe([
        $public->getKey() => 'MEMBER',
        $private->getKey() => 'REQUESTED',
    ]);

    // A request is not membership: the posts stay out of reach.
    $post = commPost(commUser(name: 'Liza Domingo'), 'Members only', $private);
    Livewire::test(CommunityPage::class)
        ->call('openGroup', (string) $private->getKey())
        ->assertSee('This group is private.')
        ->assertDontSee('Members only');
    expect(app(CommunityFeed::class)->focus(commAccess($me), (string) $post->getKey()))->toBeNull();
});

it('leaves a group, or withdraws a request to join one', function () {
    $me = commActor();
    $public = commGroup('Lunch Club', members: [$me]);
    $private = commGroup('Book Club', private: true, requested: [$me]);

    Livewire::test(CommunityPage::class)
        ->call('leave', (string) $public->getKey())
        ->call('leave', (string) $private->getKey());

    expect(CommunityGroupMember::query()->where('user_id', $me->getKey())->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Photos
|--------------------------------------------------------------------------
*/

it('stores a post photo privately and serves it only to someone who can read the post', function () {
    Storage::fake('local');
    $me = commActor();
    $group = commGroup('Book Club', private: true, members: [$me]);

    $id = app(PublishPost::class)->handle(commAccess($me), (string) $group->getKey(), null, '', [UploadedFile::fake()->image('cover.png', 40, 40)]);

    $photo = CommunityAttachment::query()->where('post_id', $id)->sole();
    expect($photo->path)->toStartWith('community-photos/')
        ->and($photo->kind)->toBe('PHOTO')
        ->and($photo->mime)->toBe('image/png');
    Storage::disk('local')->assertExists($photo->path);

    $shown = $this->get(route('community.photos', ['photo' => $photo->getKey()]))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    // Inline, so the feed can show it (a deliberate exception to the uploads
    // guide), with the guide's private five-minute cache.
    expect((string) $shown->headers->get('Content-Disposition'))->toStartWith('inline')
        ->and((string) $shown->headers->get('Cache-Control'))->toContain('private')->toContain('max-age=300');

    // The viewer's Download button asks for the same photo as an attachment.
    expect($this->get(route('community.photos', ['photo' => $photo->getKey(), 'download' => 1]))->assertOk()->headers->get('Content-Disposition'))
        ->toStartWith('attachment;')
        ->toContain('photo-');

    commActor();
    $this->get(route('community.photos', ['photo' => $photo->getKey()]))->assertNotFound();

    commActor(['PORTAL']);
    $this->get(route('community.photos', ['photo' => $photo->getKey()]))->assertRedirect('/portal');
});

it('refuses a file that only claims to be a photo', function () {
    Storage::fake('local');
    $me = commActor();
    $fake = UploadedFile::fake()->createWithContent('cat.png', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

    expect(fn () => app(PublishPost::class)->handle(commAccess($me), null, null, 'Look', [$fake]))
        ->toThrow(RuntimeException::class, Attachments::REFUSED);
    expect(CommunityPost::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Coming up, and the change stamp
|--------------------------------------------------------------------------
*/

it('lists work anniversaries in the next 30 days and drafts a greeting that mentions the account', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-21 02:00:00', 'UTC'));
    $employee = commEmployee('Andrea', 'Lim', 'Site Supervisor', '2023-09-25');
    commEmployee('Paolo', 'Bautista', 'Security Officer', '2024-12-20');
    $andrea = commUser(name: 'Andrea Lim', employeeId: (string) $employee->getKey());
    // A work anniversary shows only for someone who switched it on.
    commSignIn($andrea);
    app(ChangePrivacy::class)->handle(commAccess($andrea), showPosition: true, showBirthday: false, showAnniversary: true);
    commActor();

    $page = Livewire::test(CommunityPage::class)
        ->assertSee('Coming Up')
        ->assertSee('3 years with us')
        ->assertDontSee('Paolo Bautista')
        ->call('greet', 0)
        ->assertSet('tag', 'SOCIAL')
        ->assertSet('draft', "Happy 3 years with the company, @Andrea Lim! \u{1F389}");

    $page->call('publish');

    expect(CommunityPost::query()->sole()->body)->toBe("Happy 3 years with the company, @[{$andrea->getKey()}]! \u{1F389}");
});

it('moves the change stamp when a post is added', function () {
    $me = commActor();
    $before = app(CommunityFeed::class)->stamp(commAccess($me));

    commPost(commUser(name: 'Maria Santos'), 'Something new');

    expect(app(CommunityFeed::class)->stamp(commAccess($me)))->not->toBe($before);
});

it('keeps the reactions and tag colours the screens draw in step with the Social module\'s lists', function () {
    // The module owns the lists (and the database CHECKs repeat them); the
    // screens only add how each one looks.
    expect(array_keys(SocialFormat::REACTIONS))->toBe(Vocabulary::REACTIONS)
        ->and(array_keys(CommunityLabels::TONES))->toBe(Vocabulary::TAG_TONES);
});

it('keeps an account with Community or Chat history from being deleted', function (string $trace) {
    $target = commUser(['COMMUNITY', 'CHAT'], 'Former Staff');
    // The social history must protect deletion independently of an employee link.
    $target->forceFill(['is_active' => false, 'employee_id' => null])->save();

    if ($trace === 'post') {
        commPost($target, 'An old post');
    } elseif ($trace === 'comment') {
        $comment = new CommunityComment;
        $comment->forceFill(['post_id' => commPost(commUser(name: 'Maria Santos'), 'Hello')->getKey(), 'author_id' => $target->getKey(), 'body' => 'An old comment'])->save();
    } else {
        $conversation = new ChatConversation;
        $conversation->forceFill(['kind' => 'GROUP', 'title' => 'Old group'])->save();
        $message = new ChatMessage;
        $message->forceFill(['conversation_id' => $conversation->getKey(), 'sender_id' => $target->getKey(), 'kind' => 'TEXT', 'body' => 'An old message'])->save();
    }

    $role = new Role;
    $role->forceFill(['name' => 'Account admins', 'all_data' => true, 'is_system' => false])->save();
    $grant = new RoleModuleGrant;
    $grant->forceFill(['role_id' => $role->getKey(), 'module' => 'USERS'])->save();
    $admin = User::factory()->create(['role_id' => $role->getKey()]);

    // Deleting would blank the author on everything they wrote; like every
    // other module's history, it keeps the account deactivated instead.
    expect(fn () => app(ManageAccounts::class)->delete(commAccess($admin), (string) $target->getKey()))
        ->toThrow(RuntimeException::class, 'must remain deactivated');
    expect(User::query()->whereKey($target->getKey())->exists())->toBeTrue();
})->with(['post', 'comment', 'message']);

/*
|--------------------------------------------------------------------------
| Wiring: contracts, realtime, and the shared components
|--------------------------------------------------------------------------
| These live here rather than in ModuleBoundariesTest, RealtimeBroadcastTest
| and KitTest, so those files stay as they are.
*/

it('binds the two contracts Community and Chat read other modules through', function () {
    foreach ([ModuleAccounts::class, ColleagueDirectory::class] as $contract) {
        expect(app()->bound($contract))->toBeTrue();
        expect(array_values(class_implements(app($contract)) ?: []))->toContain($contract);
    }
});

it('lets a COMMUNITY holder onto the community channel, and nobody else', function () {
    commUsePusher();
    $join = fn (User $user): int => $this->actingAs($user)
        ->postJson('/broadcasting/auth', ['channel_name' => 'private-community', 'socket_id' => '1234.5678'])
        ->status();

    // One channel for the whole feed: it only knocks, and each page re-reads
    // through its own rules, so holding it reveals no private group's posts.
    expect($join(commUser()))->toBe(200)
        ->and($join(commUser(['CHAT', 'PORTAL'])))->toBe(403);
});

it('announces a new post on the community channel, and listens there', function () {
    Event::fake([RealtimeChange::class]);
    $this->withoutDefer();
    config()->set('copems.realtime.enabled', true);
    commActor();

    $page = Livewire::test(CommunityPage::class);
    expect($page->effects['listeners'] ?? [])->toContain('echo-private:community,.changed');

    $page->set('draft', 'Court is booked for Saturday.')->call('publish');

    Event::assertDispatched(RealtimeChange::class, static fn (RealtimeChange $event): bool => array_map(static fn ($channel): string => $channel->name, $event->broadcastOn()) === ['private-community']);
});

it('renders the Community and Chat components: initials, mentions as text, the picture viewer', function () {
    // First and last word, the sidebar's rule: a second given name is not the surname.
    expect(Blade::render('<x-social.avatar name="Maria Dela Cruz Santos" />'))->toContain('MS')->not->toContain('<img');
    expect(Blade::render('<x-social.avatar name="Maria Santos" photo="/storage/p.jpg" />'))->toContain('src="/storage/p.jpg"');
    expect(Blade::render('<x-social.avatar name="Lunch Club" icon="user-group" />'))->toContain('data-flux-icon');

    // Mentions come back as names, and a body is text, never markup.
    $text = Blade::render('<x-social.text :body="$body" :names="$names" />', [
        'body' => "Thanks @[liza] <b>and</b> @[gone]\nsee you",
        'names' => ['liza' => 'Liza Domingo'],
    ]);
    expect($text)
        ->toContain('@Liza Domingo')
        ->toContain('@Someone')
        ->toContain('&lt;b&gt;and&lt;/b&gt;')
        ->not->toContain('<b>')
        ->not->toContain('@[liza]');

    // A group's name, and @everyone, read the same way. A group can open.
    $groups = Blade::render('<x-social.text :body="$body" :names="[]" :groups="$groups" open-group />', [
        'body' => 'Ask @[g:club] or @[everyone], or @[g:gone]',
        'groups' => ['club' => 'Book Club'],
    ]);
    expect($groups)
        ->toContain('@Book Club')
        ->toContain("openGroup('club')")
        ->toContain('@everyone')
        ->toContain('@A group');

    expect(Blade::render('<x-social.lightbox />'))->toContain('x-on:lightbox.window')->toContain('Photo viewer')->toContain('Zoom in')->toContain('Download');
});

it('holds the Community and Chat components to the app\'s styling rules', function () {
    // ConventionsTest checks these on the app's own views, but components/social
    // is outside the folders it scans, so the same three rules run here.
    $files = glob(resource_path('views/components/social/*.blade.php')) ?: [];
    expect(count($files))->toBeGreaterThanOrEqual(3);

    foreach ($files as $path) {
        $source = (string) preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents($path));

        expect($source)
            ->not->toMatch('/flux:table\b/')
            ->not->toMatch('/\bdark:/')
            ->not->toMatch('/\b(bg|text|border|ring|divide|from|to)-(zinc|gray|slate|neutral|stone|red|green|blue|amber|emerald|yellow|orange|sky|indigo|violet|rose)-\d{2,3}\b/');
    }
});

/*
|--------------------------------------------------------------------------
| What people show about themselves
|--------------------------------------------------------------------------
*/

it('drops a job title everywhere once its owner switches it off', function () {
    $employee = commEmployee('Maria', 'Santos', 'Payroll Officer');
    $maria = commUser(name: 'Maria Santos', employeeId: (string) $employee->getKey());
    commPost($maria, 'Payroll cut-off is on Friday.');

    $me = commActor();
    $before = commFeed($me);
    expect($before->posts[0]->author->position)->toBe('Payroll Officer');

    commSignIn($maria);
    app(ChangePrivacy::class)->handle(commAccess($maria), showPosition: false, showBirthday: false);

    commSignIn($me);
    $after = commFeed($me);

    // The name and the photo stay. Only the line under the name goes.
    expect($after->posts[0]->author->position)->toBeNull()
        ->and($after->posts[0]->author->name)->toBe('Maria Santos');
});

it('keeps a birthday out of Coming up until its owner turns it on, and never shows the year', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-21 02:00:00', 'UTC'));
    $employee = commEmployee('Andrea', 'Lim', 'Site Supervisor', '2023-09-25', '1995-09-30');
    $andrea = commUser(name: 'Andrea Lim', employeeId: (string) $employee->getKey());
    $me = commActor();

    $kinds = static fn (): array => array_map(
        static fn (CelebrationView $c): string => $c->kind,
        app(CommunityFeed::class)->celebrations(commAccess($me), CarbonImmutable::parse('2026-09-21', 'UTC')),
    );

    // Neither shows until Andrea says so.
    expect($kinds())->toBe([]);

    commSignIn($andrea);
    app(ChangePrivacy::class)->handle(commAccess($andrea), showPosition: true, showBirthday: true, showAnniversary: true);

    commSignIn($me);
    $celebrations = app(CommunityFeed::class)->celebrations(commAccess($me), CarbonImmutable::parse('2026-09-21', 'UTC'));

    expect($kinds())->toBe([CelebrationView::ANNIVERSARY, CelebrationView::BIRTHDAY]);

    $birthday = $celebrations[1];
    expect($birthday->years)->toBeNull()
        ->and($birthday->date->format('Y-m-d'))->toBe('2026-09-30');

    Livewire::test(CommunityPage::class)
        ->assertSee('Birthday')
        ->assertDontSee('1995')
        ->call('greet', 1)
        ->assertSet('draft', "Happy birthday, @Andrea Lim! \u{1F389}");
});

it('lets each person keep their own answer, and refuses one to a role without either module', function () {
    $me = commActor(['COMMUNITY', 'CHAT']);

    Livewire::test(PrivacyCard::class)
        ->assertSet('showPosition', true)
        ->assertSet('showBirthday', false)
        ->set('showBirthday', true)
        ->call('save')
        ->assertSet('showBirthday', true);

    expect(app(Privacy::class)->of(commAccess($me))->showBirthday)->toBeTrue()
        ->and(SocialPreference::query()->where('user_id', $me->getKey())->count())->toBe(1);

    // Saving the same answers again writes nothing at all. An old timestamp
    // makes that visible: a write would move it to now.
    SocialPreference::query()->where('user_id', $me->getKey())->update(['updated_at' => '2020-01-01 00:00:00']);
    Livewire::test(PrivacyCard::class)->call('save');
    expect(SocialPreference::query()->where('user_id', $me->getKey())->sole()->updated_at?->format('Y-m-d'))->toBe('2020-01-01');

    $outsider = commActor(['PORTAL'], 'Out Sider');
    expect(fn () => app(ChangePrivacy::class)->handle(commAccess($outsider), showPosition: false, showBirthday: true))
        ->toThrow(RuntimeException::class);

    // And the card itself draws nothing for them.
    Livewire::test(PrivacyCard::class)->assertDontSee('Show My Job Title');
});

it('lists leftover Community and Chat photos and files in the file check, and leaves the ones in use alone', function () {
    Storage::fake('local');
    // The check also walks the public disk's portraits. Faked, it reads none
    // of the portraits a developer's own local storage happens to hold.
    Storage::fake('public');
    $disk = Storage::disk('local');

    // A photo still on a post is known, so it never shows up as a leftover.
    $photo = new CommunityAttachment;
    $photo->forceFill(['post_id' => commPost(commUser(name: 'Maria Santos'), 'With a photo')->getKey(), 'kind' => 'PHOTO', 'path' => 'community-photos/kept.jpg', 'mime' => 'image/jpeg', 'size' => 10, 'position' => 0])->save();
    // So is a group's photo.
    commGroup('Lunch Club')->forceFill(['photo_path' => 'community-photos/group.jpg', 'photo_size' => 10])->save();

    $paths = ['community-photos/kept.jpg', 'community-photos/group.jpg', 'community-photos/left.jpg', 'community-files/left.pdf', 'chat-photos/left.jpg', 'chat-files/left.pdf', 'chat-photos/uploading.jpg'];
    foreach ($paths as $path) {
        $disk->put($path, 'bytes');
    }
    // Two days old, past the grace period. The upload in flight stays fresh.
    foreach (array_slice($paths, 0, 6) as $path) {
        touch($disk->path($path), time() - 172800);
    }

    expect(app(FileConsistency::class)->inspect()['unreferenced'])->toBe([
        ['disk' => 'local', 'path' => 'community-photos/left.jpg'],
        ['disk' => 'local', 'path' => 'community-files/left.pdf'],
        ['disk' => 'local', 'path' => 'chat-photos/left.jpg'],
        ['disk' => 'local', 'path' => 'chat-files/left.pdf'],
    ]);

    // It only reports. Nothing gets deleted.
    $disk->assertExists($paths);
});

/*
|--------------------------------------------------------------------------
| Managing groups and tags
|--------------------------------------------------------------------------
*/

it('lets a moderator create and delete groups, owning the ones they make, and nobody else create or delete them', function () {
    Storage::fake('local');
    $moderator = commActor(['COMMUNITY', 'USERS'], 'Maria Santos');

    Livewire::test(CommunityPage::class)
        ->assertSee('New Group')
        ->call('newGroup')
        ->set('groupForm', ['name' => 'Safety Committee', 'about' => 'Drills and reminders', 'private' => true])
        ->call('saveGroup')
        ->assertSet('groupDialog', false)
        ->assertSee('Safety Committee');

    $group = CommunityGroup::query()->where('name', 'Safety Committee')->sole();
    $owner = CommunityGroupMember::query()->where('group_id', $group->getKey())->sole();
    expect($group->is_private)->toBeTrue()
        ->and($group->created_by)->toBe((int) $moderator->getKey())
        ->and($owner->status)->toBe('MEMBER')
        ->and($owner->role)->toBe('OWNER')
        ->and(AuditLog::query()->where('action', 'COMMUNITY_GROUP_CREATED')->count())->toBe(1);

    // Names are unique whatever the case.
    expect(fn () => app(CreateGroup::class)->handle(commAccess($moderator), 'safety committee', '', false))
        ->toThrow(RuntimeException::class, "There's already a group called safety committee.");

    // Opening it up lets in whoever was waiting.
    $grace = commUser(name: 'Grace Mendoza');
    $request = new CommunityGroupMember;
    $request->forceFill(['group_id' => $group->getKey(), 'user_id' => $grace->getKey(), 'status' => 'REQUESTED'])->save();
    app(UpdateGroup::class)->handle(commAccess($moderator), (string) $group->getKey(), 'Safety Committee', 'Everyone welcome', false);
    expect($request->fresh()?->status)->toBe('MEMBER')
        ->and($group->fresh()?->is_private)->toBeFalse()
        ->and(Notification::query()->where('user_id', $grace->getKey())->where('kind', 'COMMUNITY_JOIN')->count())->toBe(1);

    // Deleting takes its posts, and their photo files, with it.
    $post = commPost($moderator, 'Fire drill on Monday', $group);
    Storage::disk('local')->put('community-photos/drill.jpg', 'photo');
    $photo = new CommunityAttachment;
    $photo->forceFill(['post_id' => $post->getKey(), 'kind' => 'PHOTO', 'path' => 'community-photos/drill.jpg', 'mime' => 'image/jpeg', 'size' => 5, 'position' => 0])->save();

    app(DeleteGroup::class)->handle(commAccess($moderator), (string) $group->getKey());
    expect(CommunityGroup::query()->count())->toBe(0)
        ->and(CommunityPost::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'COMMUNITY_GROUP_DELETED')->count())->toBe(1);
    Storage::disk('local')->assertMissing('community-photos/drill.jpg');

    $someone = commActor(name: 'Paolo Bautista');
    Livewire::test(CommunityPage::class)->assertDontSee('New Group')->assertDontSee('Manage Tags');
    expect(fn () => app(CreateGroup::class)->handle(commAccess($someone), 'My own group', '', false))
        ->toThrow(RuntimeException::class, 'Only moderators can create groups.');
});

it('lets a group\'s owner and moderators answer join requests, and nobody else', function () {
    $liza = commUser(name: 'Liza Domingo');
    $grace = commUser(name: 'Grace Mendoza');
    $ramon = commUser(name: 'Ramon Aquino');
    $club = commGroup('Book Club', private: true, members: [$liza], requested: [$grace, $ramon], owner: $liza);

    commSignIn($liza);
    Livewire::test(CommunityPage::class)
        ->call('openGroup', (string) $club->getKey())
        ->assertSeeText('2 waiting to join')
        ->call('showTab', 'members')
        ->assertSee('Waiting to Join')
        ->assertSee('Grace Mendoza')
        ->call('decideRequest', (int) $grace->getKey(), true)
        ->call('decideRequest', (int) $ramon->getKey(), false);

    expect(CommunityGroupMember::query()->where('user_id', $grace->getKey())->sole()->status)->toBe('MEMBER')
        ->and(CommunityGroupMember::query()->where('user_id', $ramon->getKey())->exists())->toBeFalse()
        ->and(Notification::query()->where('user_id', $grace->getKey())->where('kind', 'COMMUNITY_JOIN')->count())->toBe(1)
        ->and(Notification::query()->where('user_id', $ramon->getKey())->count())->toBe(0);

    // The owner can edit their group, but deleting one is for moderators.
    app(UpdateGroup::class)->handle(commAccess($liza), (string) $club->getKey(), 'Book Club', 'Two books a month', true);
    expect(fn () => app(DeleteGroup::class)->handle(commAccess($liza), (string) $club->getKey()))
        ->toThrow(RuntimeException::class, 'Only moderators can delete groups.');

    $outsider = commActor(name: 'Paolo Bautista');
    $late = commUser(name: 'Jose Rivera');
    $row = new CommunityGroupMember;
    $row->forceFill(['group_id' => $club->getKey(), 'user_id' => $late->getKey(), 'status' => 'REQUESTED'])->save();
    expect(fn () => app(DecideJoinRequest::class)->handle(commAccess($outsider), (string) $club->getKey(), (int) $late->getKey(), true))
        ->toThrow(RuntimeException::class, "Only the group's owner or its moderators can answer requests.");

    // Company-wide moderators don't see or answer another group's requests.
    // They can still take a group down.
    $boss = commActor(['COMMUNITY', 'USERS'], 'Maria Santos');
    expect(fn () => app(DecideJoinRequest::class)->handle(commAccess($boss), (string) $club->getKey(), (int) $late->getKey(), true))
        ->toThrow(RuntimeException::class, "Only the group's owner or its moderators can answer requests.")
        ->and(app(CommunityFeed::class)->joinRequests(commAccess($boss), (string) $club->getKey()))->toBe([])
        ->and(fn () => app(UpdateGroup::class)->handle(commAccess($boss), (string) $club->getKey(), 'Our club now', '', false))
        ->toThrow(RuntimeException::class, "Only the group's owner or its moderators can change it.");
    Livewire::test(CommunityPage::class)
        ->call('openGroup', (string) $club->getKey())
        ->assertDontSeeText('waiting to join')
        ->assertSee('Delete Group');
});

it('lets a moderator add, rename, recolour and remove tags', function () {
    $moderator = commActor(['COMMUNITY', 'USERS'], 'Maria Santos');
    $feed = app(CommunityFeed::class);

    expect(array_map(static fn (TagView $tag): string => $tag->code, $feed->tags(commAccess($moderator))))
        ->toBe(['ANNOUNCEMENT', 'KUDOS', 'QUESTION', 'SOCIAL', 'LOST_FOUND']);

    // A new tag gets a code from its name, and posts can carry it straight away.
    expect(app(CreateTag::class)->handle(commAccess($moderator), 'Safety tips', 'info'))->toBe('SAFETY_TIPS');
    expect(fn () => app(CreateTag::class)->handle(commAccess($moderator), 'safety TIPS', 'info'))
        ->toThrow(RuntimeException::class, "There's already a tag called safety TIPS.");
    app(PublishPost::class)->handle(commAccess($moderator), null, 'SAFETY_TIPS', 'Wear your hard hat.', []);
    expect(fn () => app(PublishPost::class)->handle(commAccess($moderator), null, 'NOT_A_TAG', 'Hello', []))
        ->toThrow(RuntimeException::class, 'Pick one of the listed tags.');

    // Renaming keeps the code, so every post follows along.
    $kudos = CommunityTag::query()->where('code', 'KUDOS')->sole();
    commPost($moderator, 'Thanks, team!', tag: 'KUDOS');
    app(UpdateTag::class)->handle(commAccess($moderator), (string) $kudos->getKey(), 'Shout-outs', 'success');
    Livewire::test(CommunityPage::class)->assertSee('Shout-outs');

    // Announcement is an ordinary tag: no tab is made of it.
    $announcement = CommunityTag::query()->where('code', 'ANNOUNCEMENT')->sole();
    app(UpdateTag::class)->handle(commAccess($moderator), (string) $announcement->getKey(), 'News', 'danger');
    expect($announcement->fresh()?->name)->toBe('News')
        ->and($announcement->fresh()?->tone)->toBe('danger');

    // Removing a tag leaves its posts, untagged.
    $question = CommunityTag::query()->where('code', 'QUESTION')->sole();
    $asked = commPost($moderator, 'Where is the stapler?', tag: 'QUESTION');
    app(DeleteTag::class)->handle(commAccess($moderator), (string) $question->getKey());
    expect($asked->fresh()?->tag)->toBeNull()
        ->and(CommunityTag::query()->where('code', 'QUESTION')->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'like', 'COMMUNITY_TAG_%')->count())->toBe(4);

    // The manager is a list with an Edit button each, and a page for one tag.
    Livewire::test(CommunityPage::class)
        ->call('openTags')
        ->assertSee('New Tag')
        ->call('editTag', (string) $kudos->getKey())
        ->assertSet('tagPage', 'form')
        ->assertSet('tagForm.name', 'Shout-outs')
        ->set('tagForm.tone', 'caution')
        ->call('saveTag')
        ->assertSet('tagPage', 'list');
    expect($kudos->fresh()?->tone)->toBe('caution');

    $someone = commActor(name: 'Paolo Bautista');
    expect(fn () => app(CreateTag::class)->handle(commAccess($someone), 'Mine', 'muted'))
        ->toThrow(RuntimeException::class, 'Only moderators can manage tags.');
});

/*
|--------------------------------------------------------------------------
| Search, filters and sorting
|--------------------------------------------------------------------------
*/

it('adds filters up: who posted, where, when, what it has, and whether it mentions me', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-24 04:00:00', 'UTC'));
    $maria = commUser(name: 'Maria Santos');
    $jose = commUser(name: 'Jose Rivera');
    $me = commActor();
    $club = commGroup('Lunch Club', members: [$me, $maria, $jose]);

    $old = commPost($maria, 'Old news from Maria', minutesAgo: 60 * 24 * 10);
    commPost($jose, 'Jose in the lunch club', $club, minutesAgo: 30);
    $mentioned = commPost($maria, 'Thanks @['.$me->getKey().'] for the help', minutesAgo: 20);
    $everyone = commPost($maria, 'Heads up @[everyone], drill at noon', minutesAgo: 15);
    $grouped = commPost($jose, 'Calling @[g:'.$club->getKey().'] for lunch', minutesAgo: 12);
    $asked = commPost($jose, 'Who has the stapler?', minutesAgo: 10);
    $comment = new CommunityComment;
    $comment->forceFill(['post_id' => $asked->getKey(), 'author_id' => $maria->getKey(), 'body' => '@['.$me->getKey().'] had it last'])->save();
    $photo = new CommunityAttachment;
    $photo->forceFill(['post_id' => $old->getKey(), 'kind' => 'PHOTO', 'path' => 'community-photos/x.jpg', 'mime' => 'image/jpeg', 'size' => 1, 'position' => 0])->save();
    $file = new CommunityAttachment;
    $file->forceFill(['post_id' => $asked->getKey(), 'kind' => 'FILE', 'path' => 'community-files/x.pdf', 'name' => 'x.pdf', 'mime' => 'application/pdf', 'size' => 1, 'position' => 0])->save();

    $bodies = static fn (FeedFilter $filter): array => array_map(static fn ($post): string => $post->body, commFeed($me, $filter)->posts);

    expect($bodies(new FeedFilter(authorId: (int) $jose->getKey())))->toHaveCount(3)
        ->and($bodies(new FeedFilter(groupId: 'everyone')))->not->toContain('Jose in the lunch club')
        ->and($bodies(new FeedFilter(groupId: (string) $club->getKey())))->toBe(['Jose in the lunch club'])
        ->and($bodies(new FeedFilter(from: CarbonImmutable::parse('2026-09-10', 'Asia/Manila'), to: CarbonImmutable::parse('2026-09-14', 'Asia/Manila'))))->toBe(['Old news from Maria'])
        ->and($bodies(new FeedFilter(has: 'photos')))->toBe(['Old news from Maria'])
        ->and($bodies(new FeedFilter(has: 'files')))->toBe(['Who has the stapler?'])
        ->and($bodies(new FeedFilter(has: 'both')))->toBe([])
        // Named in the post or in a comment, through @everyone, or through a group I'm in.
        ->and($bodies(new FeedFilter(mentions: true)))->toEqualCanonicalizing([
            'Thanks @['.$me->getKey().'] for the help',
            'Heads up @[everyone], drill at noon',
            'Calling @[g:'.$club->getKey().'] for lunch',
            'Who has the stapler?',
        ])
        // And they add up.
        ->and($bodies(new FeedFilter(authorId: (int) $jose->getKey(), mentions: true)))->toEqualCanonicalizing([
            'Calling @[g:'.$club->getKey().'] for lunch',
            'Who has the stapler?',
        ]);

    expect($mentioned->exists && $everyone->exists && $grouped->exists)->toBeTrue();
});

it('sorts oldest first and by the most reactions', function () {
    $author = commUser(name: 'Maria Santos');
    $fan = commUser(name: 'Jose Rivera');
    commPost($author, 'First post', minutesAgo: 30);
    $popular = commPost($author, 'Popular post', minutesAgo: 20);
    commPost($author, 'Latest post', minutesAgo: 10);
    app(ReactToPost::class)->handle(commAccess($fan), (string) $popular->getKey(), 'LOVE');
    commActor();

    $page = Livewire::test(CommunityPage::class)->set('sort', 'oldest');
    $page->assertSeeInOrder(['First post', 'Popular post', 'Latest post']);
    $page->set('sort', 'reactions')->assertSeeInOrder(['Popular post', 'Latest post', 'First post']);
});

it('keeps the open group, its tab and the filters in the address, so a refresh comes back to them', function () {
    $me = commActor();
    $club = commGroup('Lunch Club', members: [$me]);
    commPost($me, 'Lunch at noon', $club);
    commPost($me, 'Not in the club');

    $this->get('/community?group='.$club->getKey())
        ->assertOk()
        ->assertSee('Share something with Lunch Club')
        ->assertSee('Lunch at noon')
        ->assertDontSee('Not in the club');

    $this->get('/community?group='.$club->getKey().'&tab=members')
        ->assertOk()
        ->assertSee('Members')
        ->assertDontSee('Lunch at noon');

    // A group that doesn't exist is just the main feed.
    $this->get('/community?group=made-up')
        ->assertOk()
        ->assertSee('Not in the club');

    $this->get('/community?q=noon')
        ->assertOk()
        ->assertSee('Lunch at noon')
        ->assertDontSee('Not in the club');
});

/*
|--------------------------------------------------------------------------
| Photos and files on posts and comments
|--------------------------------------------------------------------------
*/

it('takes files on a post and a comment, and serves them only to people who can read the post', function () {
    Storage::fake('local');
    $me = commActor();
    $club = commGroup('Book Club', private: true, members: [$me]);

    $postId = app(PublishPost::class)->handle(commAccess($me), (string) $club->getKey(), null, 'Agenda attached', [commPdf('Book list.pdf')]);
    $file = CommunityAttachment::query()->where('post_id', $postId)->sole();
    expect($file->kind)->toBe('FILE')
        ->and($file->name)->toBe('Book list.pdf')
        ->and($file->mime)->toBe('application/pdf')
        ->and($file->path)->toStartWith('community-files/');

    app(CommentOnPost::class)->handle(commAccess($me), $postId, null, '', [UploadedFile::fake()->image('cover.png', 30, 30), commPdf('Notes.pdf')]);
    $comment = CommunityComment::query()->sole();
    expect(CommunityAttachment::query()->where('comment_id', $comment->getKey())->pluck('kind')->all())->toBe(['PHOTO', 'FILE']);

    $view = app(CommunityFeed::class)->feed(commAccess($me), (string) $club->getKey(), new FeedFilter, 10)->posts[0];
    expect($view->files[0]->name)->toBe('Book list.pdf')
        ->and($view->files[0]->label)->toBe('PDF')
        ->and($view->comments[0]->photos)->toHaveCount(1)
        ->and($view->comments[0]->files[0]->name)->toBe('Notes.pdf');

    $response = $this->get(route('community.files', ['file' => $file->getKey()]))->assertOk();
    expect($response->headers->get('Content-Disposition'))->toContain('attachment')->toContain('Book list.pdf')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');

    // A photo's id isn't a file's, and someone outside the group gets nothing.
    $this->get(route('community.files', ['file' => CommunityAttachment::query()->where('kind', 'PHOTO')->value('id')]))->assertNotFound();
    commActor();
    $this->get(route('community.files', ['file' => $file->getKey()]))->assertNotFound();
});

it('refuses files that are not everyday office files, and more than ten at a time', function () {
    Storage::fake('local');
    $me = commActor();

    $program = UploadedFile::fake()->createWithContent('setup.pdf', "MZ\x90\x00program");
    expect(fn () => app(PublishPost::class)->handle(commAccess($me), null, null, 'Install this', [$program]))
        ->toThrow(RuntimeException::class, Attachments::REFUSED);

    $eleven = array_map(static fn (int $n): UploadedFile => commPdf("page-{$n}.pdf"), range(1, 11));
    expect(fn () => app(PublishPost::class)->handle(commAccess($me), null, null, 'Too many', $eleven))
        ->toThrow(RuntimeException::class, 'Up to 10 photos and files at a time.');

    expect(CommunityPost::query()->count())->toBe(0);
});

it('knows a Word file for one even when the system calls it a zip, but not a zip dressed up as one', function () {
    $zip = new ZipArchive;
    $real = tempnam(sys_get_temp_dir(), 'docx');
    $zip->open($real, ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<Types/>');
    $zip->addFromString('word/document.xml', '<w:document/>');
    $zip->close();

    $fake = tempnam(sys_get_temp_dir(), 'docx');
    $zip->open($fake, ZipArchive::OVERWRITE);
    $zip->addFromString('payload.exe', 'MZ');
    $zip->close();

    expect(Attachments::identify(new UploadedFile($real, 'Minutes.docx', null, null, true)))
        ->toBe(['FILE', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'])
        ->and(Attachments::identify(new UploadedFile($fake, 'Minutes.docx', null, null, true)))->toBeNull()
        ->and(Attachments::identify(UploadedFile::fake()->createWithContent('shifts.csv', "name,shift\nGrace,6 AM\n")))->toBe(['FILE', 'text/csv']);
});

it('shrinks a photo bigger than 2048 px on the server too, and leaves a small one alone', function () {
    Storage::fake('local');
    $me = commActor();

    $id = app(PublishPost::class)->handle(commAccess($me), null, null, '', [
        UploadedFile::fake()->image('wide.jpg', 3000, 1000),
        UploadedFile::fake()->image('small.png', 400, 300),
    ]);

    [$wide, $small] = CommunityAttachment::query()->where('post_id', $id)->orderBy('position')->get()->all();
    $size = static fn (CommunityAttachment $a): array => array_slice((array) getimagesize(Storage::disk('local')->path($a->path)), 0, 2);

    expect($size($wide))->toBe([2048, 683])
        ->and($wide->mime)->toBe('image/jpeg')
        ->and($wide->size)->toBe(Storage::disk('local')->size($wide->path))
        ->and($size($small))->toBe([400, 300])
        ->and($small->mime)->toBe('image/png');
});

/*
|--------------------------------------------------------------------------
| Mentions: people, groups and @everyone
|--------------------------------------------------------------------------
*/

it('lets anyone in a group use @everyone there, but only moderators in the main feed', function () {
    $jose = commUser(name: 'Jose Rivera');
    $liza = commUser(name: 'Liza Domingo');
    $outsider = commUser(name: 'Grace Mendoza');
    $me = commActor();
    $club = commGroup('Lunch Club', members: [$me, $jose, $liza]);

    app(PublishPost::class)->handle(commAccess($me), (string) $club->getKey(), null, 'Lunch at noon, @everyone');
    expect(CommunityPost::query()->latest('created_at')->first()?->body)->toBe('Lunch at noon, @[everyone]')
        ->and(Notification::query()->where('title', 'Kim Reyes mentioned everyone in a post')->pluck('user_id')->map(static fn ($id): int => (int) $id)->sort()->values()->all())
        ->toBe([(int) $jose->getKey(), (int) $liza->getKey()])
        ->and(Notification::query()->where('user_id', $outsider->getKey())->exists())->toBeFalse();

    // In the main feed it's plain text for everyone but a moderator.
    app(PublishPost::class)->handle(commAccess($me), null, null, 'Hello @everyone');
    expect(CommunityPost::query()->where('body', 'Hello @everyone')->exists())->toBeTrue();

    $boss = commActor(['COMMUNITY', 'USERS'], 'Maria Santos');
    app(PublishPost::class)->handle(commAccess($boss), null, null, 'Town hall at 3, @everyone');
    expect(Notification::query()->where('title', 'Maria Santos mentioned everyone in a post')->count())->toBe(4);
});

it('lets anyone mention a public group, and a private one only from inside it', function () {
    $jose = commUser(name: 'Jose Rivera');
    $liza = commUser(name: 'Liza Domingo');
    $me = commActor();
    $public = commGroup('Lunch Club', members: [$jose]);
    $private = commGroup('Book Club', private: true, members: [$liza]);

    app(PublishPost::class)->handle(commAccess($me), null, null, 'Ask @Lunch Club and @Book Club');
    expect(CommunityPost::query()->sole()->body)->toBe('Ask @[g:'.$public->getKey().'] and @Book Club')
        ->and(Notification::query()->where('user_id', $jose->getKey())->value('title'))->toBe('Kim Reyes mentioned Lunch Club in a post')
        ->and(Notification::query()->where('user_id', $liza->getKey())->exists())->toBeFalse();

    // The @ list offers the same: a private group only to its members, and
    // for a private group's posts, only the people who can read them.
    $options = app(CommunityFeed::class)->mentionOptions(commAccess($me), null);
    expect(array_column($options['groups'], 'name'))->toBe(['Lunch Club'])
        ->and($options['everyone'])->toBeFalse();

    // Inside a private group, no groups at all: its posts only reach its own
    // members.
    commSignIn($liza);
    $inside = app(CommunityFeed::class)->mentionOptions(commAccess($liza), (string) $private->getKey());
    expect($inside['groups'])->toBe([])
        ->and(array_column($inside['people'], 'name'))->toBe([])
        ->and($inside['everyone'])->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Running a group: members, moderators, owners, photos
|--------------------------------------------------------------------------
*/

it('lets anyone in a group add people: straight in to a public one, as a request to a private one', function () {
    $owner = commUser(name: 'Liza Domingo');
    $grace = commUser(name: 'Grace Mendoza');
    $ramon = commUser(name: 'Ramon Aquino');
    $me = commActor();
    $public = commGroup('Lunch Club', members: [$me, $owner], owner: $owner);
    $private = commGroup('Book Club', private: true, members: [$me, $owner], owner: $owner);

    Livewire::test(CommunityPage::class)
        ->call('openGroup', (string) $public->getKey())
        ->call('openPeople')
        ->assertSee('Grace Mendoza')
        ->set('pickedPeople', [(string) $grace->getKey()])
        ->call('addPeople')
        ->assertSet('peopleDialog', false);

    expect(CommunityGroupMember::query()->where('group_id', $public->getKey())->where('user_id', $grace->getKey())->sole()->status)->toBe('MEMBER')
        ->and(Notification::query()->where('user_id', $grace->getKey())->value('title'))->toBe('Kim Reyes added you to Lunch Club');

    // Private: a request the owner answers, and the list says who asked.
    $result = app(AddGroupMembers::class)->handle(commAccess($me), (string) $private->getKey(), [(int) $ramon->getKey()]);
    expect($result)->toBe(['added' => 0, 'requested' => 1]);
    $waiting = CommunityGroupMember::query()->where('group_id', $private->getKey())->where('user_id', $ramon->getKey())->sole();
    expect($waiting->status)->toBe('REQUESTED')
        ->and($waiting->added_by)->toBe((int) $me->getKey())
        ->and(Notification::query()->where('user_id', $owner->getKey())->value('kind'))->toBe('COMMUNITY_JOIN_REQUEST')
        ->and(app(CommunityFeed::class)->joinRequests(commAccess($owner), (string) $private->getKey())[0]->addedBy?->name)->toBe('Kim Reyes');

    // The owner adding someone lets them straight in.
    commSignIn($owner);
    app(AddGroupMembers::class)->handle(commAccess($owner), (string) $private->getKey(), [(int) $grace->getKey()]);
    expect(CommunityGroupMember::query()->where('group_id', $private->getKey())->where('user_id', $grace->getKey())->sole()->status)->toBe('MEMBER');

    // Someone outside the group can't add anyone.
    $outsider = commActor(name: 'Paolo Bautista');
    expect(fn () => app(AddGroupMembers::class)->handle(commAccess($outsider), (string) $public->getKey(), [(int) $ramon->getKey()]))
        ->toThrow(RuntimeException::class, 'Only people in the group can add others to it.');
});

it('lets the owner pick moderators and hand the group on, and the people who run it remove members', function () {
    $owner = commUser(name: 'Liza Domingo');
    $jose = commUser(name: 'Jose Rivera');
    $grace = commUser(name: 'Grace Mendoza');
    $ramon = commUser(name: 'Ramon Aquino');
    $club = commGroup('Book Club', private: true, members: [$owner, $jose, $grace, $ramon], owner: $owner);
    $id = (string) $club->getKey();
    $role = static fn (User $u): ?string => CommunityGroupMember::query()->where('group_id', $id)->where('user_id', $u->getKey())->value('role');

    app(SetGroupRole::class)->handle(commAccess($owner), $id, (int) $jose->getKey(), 'MODERATOR');
    expect($role($jose))->toBe('MODERATOR')
        ->and(Notification::query()->where('user_id', $jose->getKey())->value('title'))->toBe('Liza Domingo made you a moderator of Book Club');

    // A moderator removes ordinary members, but not another moderator or the owner,
    // and can't hand out roles.
    app(RemoveGroupMember::class)->handle(commAccess($jose), $id, (int) $ramon->getKey());
    expect($role($ramon))->toBeNull()
        ->and(AuditLog::query()->where('action', 'COMMUNITY_MEMBER_REMOVED')->value('details'))->toBe('Ramon Aquino removed from Book Club');
    expect(fn () => app(RemoveGroupMember::class)->handle(commAccess($jose), $id, (int) $owner->getKey()))
        ->toThrow(RuntimeException::class, "The owner can't be removed.");
    expect(fn () => app(SetGroupRole::class)->handle(commAccess($jose), $id, (int) $grace->getKey(), 'MODERATOR'))
        ->toThrow(RuntimeException::class, "Only the group's owner can change who runs it.");

    // The owner can't walk out while others are still in it, so they hand it on.
    expect(fn () => app(ChangeMembership::class)->leave(commAccess($owner), $id))
        ->toThrow(RuntimeException::class, 'You own this group. Make someone else the owner first');
    app(SetGroupRole::class)->handle(commAccess($owner), $id, (int) $grace->getKey(), 'OWNER');
    expect($role($grace))->toBe('OWNER')
        ->and($role($owner))->toBe('MODERATOR')
        ->and(AuditLog::query()->where('action', 'COMMUNITY_GROUP_ROLE_CHANGED')->count())->toBe(2);
    app(ChangeMembership::class)->leave(commAccess($owner), $id);
    expect($role($owner))->toBeNull();
});

it('gives a group a photo, shows it to anyone in Community, and counts it in the storage budget', function () {
    Storage::fake('local');
    $moderator = commActor(['COMMUNITY', 'USERS'], 'Maria Santos');
    $grace = commUser(name: 'Grace Mendoza');

    $id = app(CreateGroup::class)->handle(commAccess($moderator), 'Safety Committee', '', false, UploadedFile::fake()->image('logo.png', 900, 600), [(int) $grace->getKey()]);
    $group = CommunityGroup::query()->findOrFail($id);

    // A small square, whatever was picked.
    expect(array_slice((array) getimagesize(Storage::disk('local')->path((string) $group->photo_path)), 0, 2))->toBe([256, 256])
        ->and($group->photo_size)->toBeGreaterThan(0)
        ->and(app(StorageUsage::class)->bytes())->toBe($group->photo_size)
        ->and(CommunityGroupMember::query()->where('group_id', $id)->where('user_id', $grace->getKey())->sole()->added_by)->toBe((int) $moderator->getKey());

    commActor();
    $this->get(route('community.groups.photo', ['group' => $id]))->assertOk()->assertHeader('Content-Type', 'image/jpeg');

    // A new photo replaces the old file; removing it leaves the plain icon.
    commSignIn($moderator);
    $old = (string) $group->photo_path;
    app(UpdateGroup::class)->handle(commAccess($moderator), $id, 'Safety Committee', '', false, UploadedFile::fake()->image('new.png', 300, 300));
    Storage::disk('local')->assertMissing($old);
    app(UpdateGroup::class)->handle(commAccess($moderator), $id, 'Safety Committee', '', false, null, true);
    expect($group->fresh()?->photo_path)->toBeNull();
});

it('keeps work anniversaries out of Coming up until their owner switches them on', function () {
    $me = commActor(['COMMUNITY', 'CHAT']);

    Livewire::test(PrivacyCard::class)
        ->assertSet('showAnniversary', false)
        ->assertSee('Show My Work Anniversary')
        ->set('showAnniversary', true)
        ->call('save');

    expect(app(Privacy::class)->of(commAccess($me))->showAnniversary)->toBeTrue()
        ->and(app(Privacy::class)->shownAnniversaries([(int) $me->getKey()]))->toBe([(int) $me->getKey()]);
});

/*
|--------------------------------------------------------------------------
| Dialogs, notifications, mentions and limits
|--------------------------------------------------------------------------
*/

it('asks with the page\'s own dialog, never the browser\'s', function () {
    // No browser alerts in Community or Chat. They feel unfinished next to the
    // app's own dialogs, and wire:confirm also pins a stale question to a
    // button a redraw reuses.
    $views = [
        ...glob(resource_path('views/livewire/community/*.blade.php')) ?: [],
        ...glob(resource_path('views/livewire/community/partials/*.blade.php')) ?: [],
        ...glob(resource_path('views/livewire/chat/*.blade.php')) ?: [],
        ...glob(resource_path('views/livewire/chat/partials/*.blade.php')) ?: [],
        ...glob(resource_path('views/livewire/social/*.blade.php')) ?: [],
        ...glob(resource_path('views/components/social/*.blade.php')) ?: [],
    ];
    $scripts = array_map(static fn (string $name): string => resource_path("js/{$name}.js"), ['attachment-picker', 'mention-picker', 'photo-viewer', 'paste-limit']);
    expect(count($views))->toBeGreaterThan(20);

    foreach ($views as $path) {
        $source = (string) preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents($path));
        expect($source)->not->toContain('wire:confirm', $path)->not->toMatch('/\b(alert|confirm|prompt)\(/', $path);
    }
    foreach ($scripts as $path) {
        expect((string) file_get_contents($path))->not->toMatch('/\b(alert|confirm|prompt)\(/', $path);
    }

    expect(Blade::render('<x-social.confirm />'))->toContain('x-on:social-confirm.window')->toContain('wire:ignore');
});

it('tells each person the most specific thing about a comment, and links to the comment', function () {
    $andrea = commUser(name: 'Andrea Lim');
    $grace = commUser(name: 'Grace Mendoza');
    $jose = commUser(name: 'Jose Rivera');
    $post = commPost($andrea, 'Storm shout-out');
    $hers = new CommunityComment;
    $hers->forceFill(['post_id' => $post->getKey(), 'author_id' => $andrea->getKey(), 'body' => 'Thank you all!'])->save();
    $graces = new CommunityComment;
    $graces->forceFill(['post_id' => $post->getKey(), 'author_id' => $grace->getKey(), 'body' => 'Stay safe'])->save();
    $me = commActor(name: 'Ramon Aquino');
    $postId = (string) $post->getKey();
    $say = static function (?CommunityComment $answering, string $body) use ($me, $postId): string {
        Notification::query()->delete();

        return app(CommentOnPost::class)->handle(commAccess($me), $postId, $answering?->getKey(), $body);
    };
    $told = static fn (User $user): array => Notification::query()->where('user_id', $user->getKey())->get(['kind', 'title'])->map(static fn (Notification $n): string => $n->kind.': '.$n->title)->all();

    // A reply to Andrea's comment on her own post, naming her.
    $id = $say($hers, '@Andrea Lim thank you too');
    expect($told($andrea))->toBe(['COMMUNITY_REPLY: Ramon Aquino mentioned you in a reply to your comment'])
        ->and(Notification::query()->where('user_id', $andrea->getKey())->value('href'))->toBe('/community?post='.$postId.'&comment='.$id);

    $say($hers, 'Same here');
    expect($told($andrea))->toBe(['COMMUNITY_REPLY: Ramon Aquino replied to your comment']);

    $say($graces, 'You too');
    expect($told($grace))->toBe(['COMMUNITY_REPLY: Ramon Aquino replied to your comment'])
        ->and($told($andrea))->toBe(['COMMUNITY_COMMENT: Ramon Aquino replied to a comment on your post']);

    $say(null, '@Andrea Lim great post');
    expect($told($andrea))->toBe(['COMMUNITY_COMMENT: Ramon Aquino mentioned you in a comment on your post']);

    $say(null, 'Nice one, @Jose Rivera');
    expect($told($andrea))->toBe(['COMMUNITY_COMMENT: Ramon Aquino commented on your post'])
        ->and($told($jose))->toBe(['COMMUNITY_MENTION: Ramon Aquino mentioned you in a comment']);

    $say($graces, '@Jose Rivera see this');
    expect($told($jose))->toBe(['COMMUNITY_MENTION: Ramon Aquino mentioned you in a reply']);
});

it('opens a notification\'s comment with the thread unfolded down to it, and highlights it', function () {
    $andrea = commUser(name: 'Andrea Lim');
    $post = commPost($andrea, 'Storm shout-out');
    $top = new CommunityComment;
    $top->forceFill(['post_id' => $post->getKey(), 'author_id' => $andrea->getKey(), 'body' => 'Top comment'])->save();
    $reply = new CommunityComment;
    $reply->forceFill(['post_id' => $post->getKey(), 'parent_id' => $top->getKey(), 'author_id' => $andrea->getKey(), 'body' => 'The reply to find'])->save();
    commActor();

    Livewire::withQueryParams(['post' => $post->getKey(), 'comment' => $reply->getKey()]);
    Livewire::test(CommunityPage::class)
        ->assertSet('focusCommentId', $reply->getKey())
        ->assertSee('The reply to find')
        ->assertSeeHtml('id="comment-'.$reply->getKey().'"')
        ->assertSeeHtml('before:bg-primary-soft')
        // The comment above it starts unfolded, so the reply is in view.
        ->assertSeeHtml('x-data="{ open: true, replying: false, hover: false, editing: false }"')
        ->call('closeFocus')
        ->assertSet('focusCommentId', null);

    // A post on its own, from a mention in it, sits in a soft frame instead.
    Livewire::withQueryParams(['post' => $post->getKey()]);
    Livewire::test(CommunityPage::class)
        ->assertSet('focusCommentId', null)
        ->assertDontSeeHtml('before:bg-primary-soft')
        ->assertSeeHtml('bg-primary-soft p-1.5');
});

it('offers and makes no group mentions inside a private group, from the list or typed', function () {
    $maria = commUser(name: 'Maria Santos');
    $me = commActor();
    $lunch = commGroup('Lunch Club', members: [$me, $maria]);
    $book = commGroup('Book Club', private: true, members: [$me]);
    $feed = app(CommunityFeed::class);

    expect($feed->mentionOptions(commAccess($me), (string) $book->getKey())['groups'])->toBe([])
        ->and(array_column($feed->mentionOptions(commAccess($me), null)['groups'], 'name'))->toBe(['Book Club', 'Lunch Club'])
        ->and(array_column($feed->mentionOptions(commAccess($me), (string) $lunch->getKey())['groups'], 'name'))->toBe(['Book Club', 'Lunch Club']);

    $inside = app(PublishPost::class)->handle(commAccess($me), (string) $book->getKey(), null, 'Ask @Lunch Club too');
    app(CommentOnPost::class)->handle(commAccess($me), $inside, null, 'And @Lunch Club again');
    expect(CommunityPost::query()->find($inside)?->body)->toBe('Ask @Lunch Club too')
        ->and(CommunityComment::query()->value('body'))->toBe('And @Lunch Club again')
        ->and(Notification::query()->where('user_id', $maria->getKey())->count())->toBe(0);

    // Outside a private group it works as usual.
    $outside = app(PublishPost::class)->handle(commAccess($me), (string) $lunch->getKey(), null, 'Hi @Book Club');
    expect(CommunityPost::query()->find($outside)?->body)->toBe('Hi @[g:'.$book->getKey().']');
});

it('keeps a hand-typed mention token as plain text, so nobody reaches everyone that way', function () {
    $andrea = commUser(name: 'Andrea Lim');
    commUser(name: 'Jose Rivera');
    $me = commActor();

    $id = app(PublishPost::class)->handle(commAccess($me), null, null, 'Hello @[everyone] and @['.$andrea->getKey().']');

    $body = (string) CommunityPost::query()->find($id)?->body;
    expect(Notification::query()->count())->toBe(0)
        ->and($body)->toBe("Hello @\u{2060}[everyone] and @\u{2060}[".$andrea->getKey().']')
        ->and(Blade::render('<x-social.text :body="$body" :names="[]" />', ['body' => $body]))->toContain('[everyone]')->not->toContain('@Someone');
});

it('refuses a post over 5,000 characters and a comment over 2,000 on the server, whatever the box allows', function () {
    $me = commActor();
    $post = commPost($me, 'Short');

    expect(fn () => app(PublishPost::class)->handle(commAccess($me), null, null, str_repeat('a', SocialRules::MAX_POST + 1)))
        ->toThrow(RuntimeException::class, 'A post holds up to 5,000 characters.');
    expect(fn () => app(CommentOnPost::class)->handle(commAccess($me), (string) $post->getKey(), null, str_repeat('a', SocialRules::MAX_COMMENT + 1)))
        ->toThrow(RuntimeException::class, 'A comment holds up to 2,000 characters.');

    // Right at the limit is fine.
    app(PublishPost::class)->handle(commAccess($me), null, null, str_repeat('a', SocialRules::MAX_POST));
    expect(CommunityPost::query()->count())->toBe(2)->and(CommunityComment::query()->count())->toBe(0);
});

it('refuses a zip however it arrives: picked on the page, or sent straight to the server', function () {
    Storage::fake('local');
    $me = commActor();
    $path = (string) tempnam(sys_get_temp_dir(), 'zip');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);
    $zip->addFromString('notes.txt', 'hello');
    $zip->close();

    Livewire::test(CommunityPage::class)
        ->set('picked.post', [UploadedFile::fake()->createWithContent('photos.zip', (string) file_get_contents($path))])
        ->assertSet('staged', [])
        ->assertDispatched('toast-show', fn (string $name, array $params): bool => ($params['slots']['text'] ?? null) === Attachments::REFUSED);

    expect(fn () => app(PublishPost::class)->handle(commAccess($me), null, null, 'Photos', [new UploadedFile($path, 'photos.zip', 'application/zip', null, true)]))
        ->toThrow(RuntimeException::class, Attachments::REFUSED);
    expect(CommunityPost::query()->count())->toBe(0);
});

it('lists the viewer as You, first, and lays out a comment\'s photos like a post\'s', function () {
    $people = [
        1 => new PersonView(1, 'Ana Cruz', null, null),
        2 => new PersonView(2, 'Kim Reyes', null, null),
    ];
    $list = Blade::render('<x-social.who :reactions="$reactions" :people="$people" :me="2">3</x-social.who>', [
        'reactions' => ['LIKE' => [1], 'LOVE' => [2]],
        'people' => $people,
    ]);
    expect($list)->toContain('>You<')->not->toContain('Kim Reyes')
        ->and(strpos($list, '>You<'))->toBeLessThan((int) strpos($list, 'Ana Cruz'));

    // Ten photos on a comment: four tiles, the last one saying how many more.
    $urls = array_map(static fn (int $n): string => "/p/{$n}.jpg", range(1, 10));
    $grid = Blade::render('<x-social.photo-grid :urls="$urls" compact />', ['urls' => $urls]);
    expect(substr_count($grid, '<img'))->toBe(4)
        ->and($grid)->toContain('+6')->toContain('max-w-xs');
});
