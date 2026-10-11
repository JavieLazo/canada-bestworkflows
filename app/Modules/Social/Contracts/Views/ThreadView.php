<?php

declare(strict_types=1);

namespace App\Modules\Social\Contracts\Views;

/**
 * An open conversation: its newest messages, who is in it, and the read
 * receipt under the viewer's own last message.
 */
final readonly class ThreadView
{
    /**
     * @param  list<MessageView>  $messages  oldest first
     * @param  list<int>|null  $seenBy  who has seen the viewer's last message, by name, or null when the last message is not the viewer's
     * @param  array<int, string>  $names  account id => name, for mentions and reactions
     * @param  list<string>  $photos  every photo still shared in it, newest first
     * @param  list<int>  $withoutChat  people here who cannot open Chat any more, so nobody waits on a reply that cannot come
     * @param  array<int, PersonView>  $people  everyone the thread shows (senders, readers, reactors, mentions), by account id
     * @param  list<FileView>  $files  every file still shared in it, newest first
     * @param  list<int>  $admins  the group chat's admins
     */
    public function __construct(
        public ConversationView $conversation,
        public array $messages,
        public bool $more,
        public ?array $seenBy,
        public array $names,
        public array $photos,
        public array $withoutChat = [],
        public array $people = [],
        public array $files = [],
        public array $admins = [],
    ) {}
}
