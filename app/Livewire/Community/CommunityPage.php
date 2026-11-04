<?php

declare(strict_types=1);

namespace App\Livewire\Community;

use App\Domain\Enums\ModuleCode;
use App\Livewire\Concerns\WithChangeStamp;
use App\Livewire\Concerns\WithStagedAttachments;
use App\Modules\Social\Application\Commands\AddGroupMembers;
use App\Modules\Social\Application\Commands\ChangeMembership;
use App\Modules\Social\Application\Commands\CommentOnPost;
use App\Modules\Social\Application\Commands\CreateGroup;
use App\Modules\Social\Application\Commands\CreateTag;
use App\Modules\Social\Application\Commands\DecideJoinRequest;
use App\Modules\Social\Application\Commands\DeleteGroup;
use App\Modules\Social\Application\Commands\DeleteTag;
use App\Modules\Social\Application\Commands\EditComment;
use App\Modules\Social\Application\Commands\EditPost;
use App\Modules\Social\Application\Commands\PinPost;
use App\Modules\Social\Application\Commands\PublishPost;
use App\Modules\Social\Application\Commands\ReactToComment;
use App\Modules\Social\Application\Commands\ReactToPost;
use App\Modules\Social\Application\Commands\RemoveComment;
use App\Modules\Social\Application\Commands\RemoveGroupMember;
use App\Modules\Social\Application\Commands\RemovePost;
use App\Modules\Social\Application\Commands\SetGroupRole;
use App\Modules\Social\Application\Commands\UpdateGroup;
use App\Modules\Social\Application\Commands\UpdateTag;
use App\Modules\Social\Application\People;
use App\Modules\Social\Application\Queries\CommunityFeed;
use App\Modules\Social\Application\SocialRules;
use App\Modules\Social\Contracts\FeedFilter;
use App\Modules\Social\Contracts\Views\GroupView;
use App\Support\Access;
use App\Support\ManilaClock;
use App\Support\Realtime;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use RuntimeException;

/**
 * Community: the company-wide feed. Posts to Everyone or to one group, with
 * photos and files, six reactions on posts and comments, threads three
 * levels deep, pinned posts, groups with their own people in charge, and
 * what is coming up for the people around you.
 *
 * Every read goes through App\Modules\Social\Application\Queries\CommunityFeed
 * and every write through one of the Social module's commands, which own the
 * rules: who may see a post, who runs a group, how long a post may be. This
 * component holds only what the screen is showing and what is being typed.
 *
 * Where you are lives in the address: the open group and its tab, the
 * search, every filter and the sort. So a refresh, the back button or a
 * shared link all come back to the same view. `?post=<id>` (from a
 * notification) opens that one post above the feed, and `&comment=<id>`
 * highlights the comment the notification was about.
 *
 * The feed redraws when anything in Community changes: at the 60-second tick
 * through WithChangeStamp, or at once when realtime is on.
 */
#[Layout('layouts.app')]
#[Title('Community')]
class CommunityPage extends Component
{
    use WithChangeStamp;
    use WithFileUploads;
    use WithStagedAttachments;

    /**
     * The open group, or '' for the main feed. Plain UI state rather than
     * #[Locked], because it lives in the address: every read and every
     * command checks it against what the viewer may see and do.
     */
    #[Url(as: 'group', history: true, except: '')]
    public string $group = '';

    /** posts | members | media, inside a group. */
    #[Url(as: 'tab', history: true, except: 'posts')]
    public string $tab = 'posts';

    /** The one post a notification linked to. An id, so it is #[Locked]: only mount sets it. */
    #[Locked]
    public ?string $focusId = null;

    /** The comment on that post the notification was about, highlighted. An id, so it is #[Locked]: only mount sets it. */
    #[Locked]
    public ?string $focusCommentId = null;

    // The search, the filters and the sort. All plain UI state, checked in
    // filter() before anything reads them.

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** A tag code, or ''. */
    #[Url(as: 'tag', except: '')]
    public string $filterTag = '';

    /** Where posts were shared: '', `everyone`, or a group id. */
    #[Url(as: 'in', except: '')]
    public string $filterWhere = '';

    /** An author's account id, or 0 for anyone. */
    #[Url(as: 'by', except: 0)]
    public int $filterAuthor = 0;

    /** A Manila calendar day, Y-m-d, or ''. */
    #[Url(as: 'from', except: '')]
    public string $filterFrom = '';

    #[Url(as: 'to', except: '')]
    public string $filterTo = '';

    /** photos | files | both | ''. */
    #[Url(as: 'has', except: '')]
    public string $filterHas = '';

    #[Url(as: 'mentions', except: false)]
    public bool $filterMentions = false;

    /** newest | oldest | discussed | reactions. */
    #[Url(as: 'sort', except: 'newest')]
    public string $sort = 'newest';

    /** How many posts the feed shows; "Show more" adds a page. The query caps it. */
    public int $limit = CommunityFeed::PAGE;

    // The post box.

    public string $draft = '';

    /** `everyone`, or a group id. Plain form state; PublishPost refuses a group the author is not in. */
    public string $audience = 'everyone';

    /** A tag code, or '' for none. Plain form state; PublishPost refuses one not on the list. */
    public string $tag = '';

    /** @var array<string, string> Comment and reply drafts, keyed by the post or comment they answer. */
    public array $drafts = [];

    /** @var array<string, string> Edits to the viewer's own posts and comments, keyed by their id. Each edit box fills its own when it opens. */
    public array $edits = [];

    public bool $historyDialog = false;

    /** The post whose edit history is open. An id, so it is #[Locked]: only openHistory() sets it. */
    #[Locked]
    public ?string $historyPostId = null;

    // Groups: the form, adding people, and the list of every group.

    public bool $groupDialog = false;

    /** The group the form edits, or null for a new one. An id, so it is #[Locked]: only editGroup() sets it. */
    #[Locked]
    public ?string $editingGroupId = null;

    /** @var array<string, mixed> The group form's draft (name, about, private, removePhoto). A browser can send anything, so it's read with care and the commands check it all again. */
    public array $groupForm = ['name' => '', 'about' => '', 'private' => false, 'removePhoto' => false];

    public bool $peopleDialog = false;

    public string $peopleSearch = '';

    /**
     * @var list<int|string> The people ticked in the add-people list, as the
     *                       checkboxes send them. Not #[Locked]: a wire:model
     *                       draft, which the commands re-check against who
     *                       can use Community. Shared by the new-group form.
     */
    public array $pickedPeople = [];

    public bool $groupsDialog = false;

    /** yours | discover. */
    public string $groupsTab = 'yours';

    public string $groupsSearch = '';

    public bool $comingUpDialog = false;

    // Tags, for moderators.

    public bool $tagsDialog = false;

    /** list | form. */
    public string $tagPage = 'list';

    /** The tag the form edits, or null for a new one. An id, so it is #[Locked]: only editTag() sets it. */
    #[Locked]
    public ?string $editingTagId = null;

    /** @var array<string, mixed> The tag form's draft (name, tone). */
    public array $tagForm = ['name' => '', 'tone' => 'muted'];

    public function mount(): void
    {
        $post = request()->query('post');
        $comment = request()->query('comment');
        $this->focusId = is_string($post) && $post !== '' ? $post : null;
        $this->focusCommentId = $this->focusId !== null && is_string($comment) && $comment !== '' ? $comment : null;

        $group = $this->group !== '' ? $this->findGroup($this->group) : null;
        if ($this->group !== '' && $group === null) {
            $this->group = '';
        }
        // Inside a group, a new post goes to that group unless changed.
        $this->audience = $group !== null && $group->joined() ? $group->id : 'everyone';
    }

    // Moving around.

    public function showFeed(): void
    {
        $this->group = '';
        $this->tab = 'posts';
        $this->focusId = null;
        $this->focusCommentId = null;
        $this->audience = 'everyone';
        $this->limit = CommunityFeed::PAGE;
        $this->groupsDialog = false;
    }

    public function openGroup(string $id): void
    {
        $group = $this->findGroup($id);

        if ($group === null) {
            return;
        }

        $this->group = $group->id;
        $this->tab = 'posts';
        $this->focusId = null;
        $this->focusCommentId = null;
        $this->clearFilters();
        $this->groupsDialog = false;
        $this->audience = $group->joined() ? $group->id : 'everyone';
    }

    public function showTab(string $tab): void
    {
        $this->tab = in_array($tab, ['posts', 'members', 'media'], true) ? $tab : 'posts';
    }

    public function closeFocus(): void
    {
        $this->focusId = null;
        $this->focusCommentId = null;
    }

    public function showMore(): void
    {
        $this->limit = min($this->limit + CommunityFeed::PAGE, 200);
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'filterTag', 'filterWhere', 'filterAuthor', 'filterFrom', 'filterTo', 'filterHas', 'filterMentions', 'sort');
        $this->limit = CommunityFeed::PAGE;
    }

    /** Takes one filter off, from its chip. */
    public function dropFilter(string $which): void
    {
        match ($which) {
            'search' => $this->search = '',
            'tag' => $this->filterTag = '',
            'where' => $this->filterWhere = '',
            'author' => $this->filterAuthor = 0,
            'dates' => [$this->filterFrom, $this->filterTo] = ['', ''],
            'has' => $this->filterHas = '',
            'mentions' => $this->filterMentions = false,
            default => null,
        };
        $this->limit = CommunityFeed::PAGE;
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'filterTag', 'filterWhere', 'filterAuthor', 'filterFrom', 'filterTo', 'filterHas', 'filterMentions', 'sort'], true)) {
            $this->limit = CommunityFeed::PAGE;
        }
    }

    // Posting.

    public function publish(): void
    {
        $actor = app(Access::class);
        $groupId = $this->audience === 'everyone' ? null : $this->audience;

        try {
            $id = app(PublishPost::class)->handle($actor, $groupId, $this->tag === '' ? null : $this->tag, $this->draft, $this->stagedFor('post'));
        } catch (QueryException) {
            Flux::toast(text: 'The post could not be saved. Please try again.', variant: 'danger');

            return;
        } catch (RuntimeException $e) {
            Flux::toast(text: $e->getMessage(), variant: 'danger');

            return;
        }

        $this->reset('draft', 'tag');
        $this->clearStaged('post');
        $this->focusId = null;
        $this->focusCommentId = null;

        $where = $groupId === null ? 'Everyone' : ($this->findGroup($groupId)->name ?? 'the group');
        Flux::toast(text: 'Posted to '.$where.'.', variant: 'success');

        // A new post lands under the pins, which can push it below the fold.
        $this->dispatch('community-posted', id: $id);
    }

    /**
     * Saves an edit to the viewer's own post: the text in its box, the tag
     * picked ('' for none), the photos and files taken off, and whatever
     * was added. EditPost checks every part of it again.
     *
     * @param  array<array-key, mixed>  $remove
     */
    public function saveEdit(string $postId, string $tag, array $remove = []): void
    {
        $actor = app(Access::class);
        $body = self::field($this->edits, $postId);
        $remove = array_values(array_filter($remove, 'is_string'));
        $files = $this->stagedFor('e-'.$postId);

        if ($this->attempt(static fn () => app(EditPost::class)->handle($actor, $postId, $body, $tag === '' ? null : $tag, $remove, $files))) {
            unset($this->edits[$postId]);
            $this->clearStaged('e-'.$postId);
            $this->dispatch('community-edited', id: $postId);
        }
    }

    /** Lets go of anything added to an edit that was cancelled. */
    public function cancelEdit(string $postId): void
    {
        $this->clearStaged('e-'.$postId);
    }

    /** Opens a post's earlier versions. CommunityFeed::history() checks the viewer can see the post. */
    public function openHistory(string $postId): void
    {
        $this->historyPostId = $postId;
        $this->historyDialog = true;
    }

    /** Writes the first line of a greeting for whatever is coming up on the rail. */
    public function greet(int $index): void
    {
        $actor = app(Access::class);
        $celebration = app(CommunityFeed::class)->celebrations($actor, $this->today())[$index] ?? null;

        if ($celebration === null) {
            return;
        }

        $this->showFeed();
        $this->comingUpDialog = false;
        $this->tag = $this->tagExists('SOCIAL') ? 'SOCIAL' : '';
        $who = $celebration->mention !== null ? '@'.$celebration->mention : $celebration->name;
        $this->draft = $celebration->isBirthday()
            ? "Happy birthday, {$who}! \u{1F389}"
            : 'Happy '.$celebration->years.' '.($celebration->years === 1 ? 'year' : 'years')." with the company, {$who}! \u{1F389}";

        $this->dispatch('community-compose');
    }

    /**
     * What the @ list offers when writing to this audience: the main feed
     * (null) or a group. Read by the list itself (mention-picker.js), so
     * nothing redraws.
     *
     * @return array{people: list<array{id: int, name: string, sub: string|null, photo: string|null}>, groups: list<array{id: string, name: string}>, everyone: bool}
     */
    #[Renderless]
    public function mentionOptions(?string $audience = null): array
    {
        $groupId = $audience === null || $audience === '' || $audience === 'everyone' ? null : $audience;

        return app(CommunityFeed::class)->mentionOptions(app(Access::class), $groupId);
    }

    // Reacting and replying.

    public function react(string $postId, string $type): void
    {
        $actor = app(Access::class);
        $this->attempt(static fn () => app(ReactToPost::class)->handle($actor, $postId, $type));
    }

    public function comment(string $postId): void
    {
        $actor = app(Access::class);
        $body = $this->drafts[$postId] ?? '';
        $files = $this->stagedFor('c-'.$postId);

        if ($this->attempt(static fn () => app(CommentOnPost::class)->handle($actor, $postId, null, $body, $files))) {
            // Emptied, not unset: an input bound to a key that disappears
            // keeps showing what was typed.
            $this->drafts[$postId] = '';
            $this->clearStaged('c-'.$postId);
        }
    }

    public function reply(string $postId, string $commentId): void
    {
        $actor = app(Access::class);
        $body = $this->drafts[$commentId] ?? '';
        $files = $this->stagedFor('r-'.$commentId);

        if ($this->attempt(static fn () => app(CommentOnPost::class)->handle($actor, $postId, $commentId, $body, $files))) {
            $this->drafts[$commentId] = '';
            $this->clearStaged('r-'.$commentId);
            $this->dispatch('community-replied', id: $commentId);
        }
    }

    public function saveCommentEdit(string $commentId): void
    {
        $actor = app(Access::class);
        $body = self::field($this->edits, $commentId);

        if ($this->attempt(static fn () => app(EditComment::class)->handle($actor, $commentId, $body))) {
            unset($this->edits[$commentId]);
            $this->dispatch('community-edited', id: $commentId);
        }
    }

    public function reactToComment(string $commentId, string $type): void
    {
        $actor = app(Access::class);
        $this->attempt(static fn () => app(ReactToComment::class)->handle($actor, $commentId, $type));
    }

    // Pinning and deleting.

    public function togglePin(string $postId, bool $pin): void
    {
        $actor = app(Access::class);

        if ($this->attempt(static fn () => app(PinPost::class)->handle($actor, $postId, $pin))) {
            Flux::toast(text: $pin ? 'Post pinned to the top of the feed.' : 'Post unpinned.', variant: 'success');
        }
    }

    public function deletePost(string $postId): void
    {
        $actor = app(Access::class);

        if ($this->attempt(static fn () => app(RemovePost::class)->handle($actor, $postId))) {
            if ($this->focusId === $postId) {
                $this->focusId = null;
                $this->focusCommentId = null;
            }
            Flux::toast(text: 'Post deleted.', variant: 'success');
        }
    }

    public function deleteComment(string $commentId): void
    {
        $actor = app(Access::class);

        if ($this->attempt(static fn () => app(RemoveComment::class)->handle($actor, $commentId))) {
            Flux::toast(text: 'Comment deleted.', variant: 'success');
        }
    }

    // Joining and leaving.

    public function join(string $groupId): void
    {
        $actor = app(Access::class);
        $status = null;

        if ($this->attempt(static function () use ($actor, $groupId, &$status): void {
            $status = app(ChangeMembership::class)->join($actor, $groupId);
        })) {
            $name = $this->findGroup($groupId)->name ?? 'the group';
            Flux::toast(
                text: $status === 'REQUESTED' ? "Request to join {$name} sent." : "You joined {$name}.",
                variant: 'success',
            );
            if ($status !== 'REQUESTED' && $this->group === $groupId) {
                $this->audience = $groupId;
            }
        }
    }

    public function leave(string $groupId): void
    {
        $actor = app(Access::class);
        $group = $this->findGroup($groupId);

        if ($this->attempt(static fn () => app(ChangeMembership::class)->leave($actor, $groupId))) {
            if ($this->audience === $groupId) {
                $this->audience = 'everyone';
            }
            Flux::toast(text: $group !== null && $group->requested() ? 'Request withdrawn.' : 'You left '.($group->name ?? 'the group').'.', variant: 'success');
        }
    }

    // Making and changing groups.

    public function newGroup(): void
    {
        $this->editingGroupId = null;
        $this->groupForm = ['name' => '', 'about' => '', 'private' => false, 'removePhoto' => false];
        $this->reset('pickedPeople', 'peopleSearch');
        $this->clearStaged('avatar');
        $this->groupDialog = true;
    }

    /** Opens the form on the group being shown. */
    public function editGroup(): void
    {
        $group = $this->currentGroup();

        if ($group === null || ! $group->canManage) {
            return;
        }

        $this->editingGroupId = $group->id;
        $this->groupForm = ['name' => $group->name, 'about' => (string) $group->description, 'private' => $group->private, 'removePhoto' => false];
        $this->clearStaged('avatar');
        $this->groupDialog = true;
    }

    public function saveGroup(): void
    {
        $actor = app(Access::class);
        $editing = $this->editingGroupId;
        $name = self::field($this->groupForm, 'name');
        $about = self::field($this->groupForm, 'about');
        $private = ($this->groupForm['private'] ?? false) === true;
        $photo = $this->stagedFor('avatar')[0] ?? null;
        $removePhoto = ($this->groupForm['removePhoto'] ?? false) === true;
        $people = array_map('intval', $this->pickedPeople);

        try {
            if ($editing === null) {
                $id = app(CreateGroup::class)->handle($actor, $name, $about, $private, $photo, $people);
            } else {
                app(UpdateGroup::class)->handle($actor, $editing, $name, $about, $private, $photo, $removePhoto);
                $id = $editing;
            }
        } catch (QueryException) {
            Flux::toast(text: 'That could not be saved. Please try again.', variant: 'danger');

            return;
        } catch (RuntimeException $e) {
            Flux::toast(text: $e->getMessage(), variant: 'danger');

            return;
        }

        $this->groupDialog = false;
        $this->editingGroupId = null;
        $this->clearStaged('avatar');
        $this->reset('pickedPeople', 'peopleSearch');
        Flux::toast(text: $editing === null ? 'Group created.' : 'Group saved.', variant: 'success');
        $this->openGroup($id);
    }

    /** Deletes the group being shown, with its posts. Company-wide moderators only. */
    public function deleteGroup(): void
    {
        $actor = app(Access::class);
        $id = $this->group;

        if ($id !== '' && $this->attempt(static fn () => app(DeleteGroup::class)->handle($actor, $id))) {
            Flux::toast(text: 'Group deleted.', variant: 'success');
            $this->showFeed();
        }
    }

    // The people in a group.

    public function openPeople(): void
    {
        $this->reset('pickedPeople', 'peopleSearch');
        $this->peopleDialog = true;
    }

    public function addPeople(): void
    {
        $actor = app(Access::class);
        $id = $this->group;
        $people = array_map('intval', $this->pickedPeople);
        $result = null;

        if ($id === '' || ! $this->attempt(static function () use ($actor, $id, $people, &$result): void {
            $result = app(AddGroupMembers::class)->handle($actor, $id, $people);
        }) || ! is_array($result)) {
            return;
        }

        $this->peopleDialog = false;
        $this->reset('pickedPeople', 'peopleSearch');
        Flux::toast(
            text: $result['requested'] > 0
                ? ($result['requested'] === 1 ? 'Sent to the owner and moderators to let in.' : $result['requested'].' people sent to the owner and moderators to let in.')
                : ($result['added'] === 1 ? 'Added to the group.' : $result['added'].' people added to the group.'),
            variant: 'success',
        );
    }

    public function decideRequest(int $userId, bool $approve): void
    {
        $actor = app(Access::class);
        $id = $this->group;

        if ($id !== '' && $this->attempt(static fn () => app(DecideJoinRequest::class)->handle($actor, $id, $userId, $approve))) {
            Flux::toast(text: $approve ? 'Let in.' : 'Request declined.', variant: 'success');
        }
    }

    public function removeMember(int $userId): void
    {
        $actor = app(Access::class);
        $id = $this->group;

        if ($id !== '' && $this->attempt(static fn () => app(RemoveGroupMember::class)->handle($actor, $id, $userId))) {
            Flux::toast(text: 'Removed from the group.', variant: 'success');
        }
    }

    public function setRole(int $userId, string $role): void
    {
        $actor = app(Access::class);
        $id = $this->group;

        if ($id !== '' && $this->attempt(static fn () => app(SetGroupRole::class)->handle($actor, $id, $userId, $role))) {
            Flux::toast(text: match ($role) {
                'OWNER' => 'The group has a new owner. You stay on as a moderator.',
                'MODERATOR' => 'Made a moderator.',
                default => 'No longer a moderator.',
            }, variant: 'success');
        }
    }

    public function openGroups(string $tab = 'yours'): void
    {
        $this->groupsTab = $tab === 'discover' ? 'discover' : 'yours';
        $this->groupsSearch = '';
        $this->groupsDialog = true;
    }

    // Tags.

    public function openTags(): void
    {
        $this->tagPage = 'list';
        $this->editingTagId = null;
        $this->tagsDialog = true;
    }

    public function newTag(): void
    {
        $this->editingTagId = null;
        $this->tagForm = ['name' => '', 'tone' => 'muted'];
        $this->tagPage = 'form';
    }

    public function editTag(string $tagId): void
    {
        foreach (app(CommunityFeed::class)->tags(app(Access::class)) as $tag) {
            if ($tag->id === $tagId) {
                $this->editingTagId = $tag->id;
                $this->tagForm = ['name' => $tag->name, 'tone' => $tag->tone];
                $this->tagPage = 'form';
            }
        }
    }

    public function backToTags(): void
    {
        $this->tagPage = 'list';
        $this->editingTagId = null;
    }

    public function saveTag(): void
    {
        $actor = app(Access::class);
        $editing = $this->editingTagId;
        $name = self::field($this->tagForm, 'name');
        $tone = self::field($this->tagForm, 'tone');

        $saved = $editing === null
            ? $this->attempt(static fn () => app(CreateTag::class)->handle($actor, $name, $tone))
            : $this->attempt(static fn () => app(UpdateTag::class)->handle($actor, $editing, $name, $tone));

        if ($saved) {
            $this->backToTags();
            Flux::toast(text: $editing === null ? 'Tag added.' : 'Tag saved.', variant: 'success');
        }
    }

    public function deleteTag(string $tagId): void
    {
        $actor = app(Access::class);

        if ($this->attempt(static fn () => app(DeleteTag::class)->handle($actor, $tagId))) {
            $this->backToTags();
            // The post box may have had the removed tag picked.
            if ($this->tag !== '' && ! $this->tagExists($this->tag)) {
                $this->tag = '';
            }
            if ($this->filterTag !== '' && ! $this->tagExists($this->filterTag)) {
                $this->filterTag = '';
            }
            Flux::toast(text: 'Tag removed.', variant: 'success');
        }
    }

    public function render(): View
    {
        $actor = app(Access::class);
        $feed = app(CommunityFeed::class);
        $now = CarbonImmutable::now();

        $groups = $feed->groups($actor);
        $activeGroup = null;
        foreach ($groups as $group) {
            if ($group->id === $this->group) {
                $activeGroup = $group;
            }
        }

        $tab = $activeGroup !== null ? $this->tab : 'posts';
        $filter = $this->filter($groups);
        $focus = $this->focusId !== null ? $feed->focus($actor, $this->focusId) : null;
        $page = $tab === 'posts' ? $feed->feed($actor, $activeGroup?->id, $filter, $this->limit) : null;
        $viewer = $feed->viewer($actor);

        $groupNames = [];
        foreach ($groups as $group) {
            $groupNames[$group->id] = $group->name;
        }

        $tags = $feed->tags($actor);
        $tagsByCode = [];
        foreach ($tags as $tag) {
            $tagsByCode[$tag->code] = $tag;
        }

        // Who can be picked for a group: everyone with Community, less
        // anyone already in the group being added to.
        $directory = [];
        if ($this->groupDialog && $this->editingGroupId === null || $this->peopleDialog) {
            $inGroup = $this->peopleDialog && $activeGroup !== null
                ? array_map(static fn ($member): int => $member->person->id, $feed->members($actor, $activeGroup->id))
                : [];
            $needle = mb_strtolower(trim($this->peopleSearch));
            foreach (app(People::class)->holders($actor, ModuleCode::COMMUNITY) as $person) {
                if ($person->id !== $viewer->id && ! in_array($person->id, $inGroup, true)
                    && ($needle === '' || str_contains(mb_strtolower($person->name.' '.$person->position), $needle))) {
                    $directory[] = $person;
                }
            }
        }

        $joined = array_values(array_filter($groups, static fn (GroupView $g): bool => $g->joined()));
        $others = array_values(array_filter($groups, static fn (GroupView $g): bool => ! $g->joined()));

        $this->markStampRendered();

        return view('livewire.community.community-page', [
            'now' => $now,
            'viewer' => $viewer,
            'page' => $page,
            'focus' => $focus,
            'names' => ($page->names ?? []) + ($focus->names ?? []) + [$viewer->id => $viewer->name],
            'people' => ($page->people ?? []) + ($focus->people ?? []) + [$viewer->id => $viewer],
            'activeGroup' => $activeGroup,
            'activeTab' => $tab,
            'members' => $activeGroup !== null && $tab === 'members' ? $feed->members($actor, $activeGroup->id) : [],
            'requests' => $activeGroup !== null && $activeGroup->canManage ? $feed->joinRequests($actor, $activeGroup->id) : [],
            'media' => $activeGroup !== null && $tab === 'media' ? $feed->media($actor, $activeGroup->id) : ['photos' => [], 'files' => []],
            'joinedGroups' => $joined,
            'otherGroups' => $others,
            'groupNames' => $groupNames,
            'celebrations' => $feed->celebrations($actor, $this->today()),
            'tags' => $tags,
            'tagsByCode' => $tagsByCode,
            'filter' => $filter,
            'authors' => $this->filterAuthor > 0 || $page !== null ? app(People::class)->holders($actor, ModuleCode::COMMUNITY) : [],
            'directory' => $directory,
            'history' => $this->historyDialog && $this->historyPostId !== null ? $feed->history($actor, $this->historyPostId) : null,
            'canModerate' => SocialRules::moderates($actor),
            'maxAttachments' => SocialRules::MAX_ATTACHMENTS,
            'maxBytes' => $this->stagedFileLimit(),
            'maxBody' => SocialRules::MAX_POST,
            'maxComment' => SocialRules::MAX_COMMENT,
        ]);
    }

    protected function changeStamp(): string
    {
        return app(CommunityFeed::class)->stamp(app(Access::class));
    }

    protected function realtimeChannel(): string
    {
        return Realtime::community();
    }

    // Helpers.

    /**
     * The filters as the feed reads them, each one checked: a tag that
     * exists, a group the viewer can read, a real date, a known sort.
     *
     * @param  list<GroupView>  $groups
     */
    private function filter(array $groups): FeedFilter
    {
        $tag = $this->filterTag !== '' && $this->tagExists($this->filterTag) ? $this->filterTag : null;

        $where = null;
        if ($this->filterWhere === 'everyone') {
            $where = 'everyone';
        } else {
            foreach ($groups as $group) {
                if ($group->id === $this->filterWhere && (! $group->private || $group->joined())) {
                    $where = $group->id;
                }
            }
        }

        return new FeedFilter(
            search: $this->search,
            groupId: $where,
            tag: $tag,
            authorId: $this->filterAuthor > 0 ? $this->filterAuthor : null,
            from: self::day($this->filterFrom),
            to: self::day($this->filterTo),
            has: in_array($this->filterHas, FeedFilter::HAS, true) ? $this->filterHas : '',
            mentions: $this->filterMentions,
            sort: in_array($this->sort, FeedFilter::SORTS, true) ? $this->sort : 'newest',
        );
    }

    /** A typed Y-m-d, as that Manila day, or null when it isn't one. */
    private static function day(string $value): ?CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $day = CarbonImmutable::createFromFormat('!Y-m-d', $value, ManilaClock::TZ);

        return $day !== null && $day->format('Y-m-d') === $value ? $day : null;
    }

    /**
     * Runs one command and turns its refusal into a toast. Returns whether it
     * went through.
     */
    private function attempt(callable $operation): bool
    {
        try {
            $operation();
        } catch (QueryException) {
            Flux::toast(text: 'That could not be saved. Please try again.', variant: 'danger');

            return false;
        } catch (RuntimeException $e) {
            Flux::toast(text: $e->getMessage(), variant: 'danger');

            return false;
        }

        return true;
    }

    /**
     * One text field out of a draft the browser sent, or '' when it isn't text.
     *
     * @param  array<array-key, mixed>  $draft
     */
    private static function field(array $draft, string $key): string
    {
        $value = $draft[$key] ?? '';

        return is_string($value) ? $value : '';
    }

    private function tagExists(string $code): bool
    {
        foreach (app(CommunityFeed::class)->tags(app(Access::class)) as $tag) {
            if ($tag->code === $code) {
                return true;
            }
        }

        return false;
    }

    private function currentGroup(): ?GroupView
    {
        return $this->group !== '' ? $this->findGroup($this->group) : null;
    }

    private function findGroup(string $id): ?GroupView
    {
        foreach (app(CommunityFeed::class)->groups(app(Access::class)) as $group) {
            if ($group->id === $id) {
                return $group;
            }
        }

        return null;
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::now(ManilaClock::TZ)->startOfDay();
    }
}
