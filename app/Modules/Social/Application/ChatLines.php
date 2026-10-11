<?php

declare(strict_types=1);

namespace App\Modules\Social\Application;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Modules\Social\Domain\Vocabulary;

/**
 * The lines the app writes into a group chat when something changes, like
 * "Maria added Jose" or "Liza named the group HR Team", so everyone in it
 * can see what happened and when.
 */
final class ChatLines
{
    private function __construct() {}

    /** Writes one line and moves the chat to the top of everyone's list. Call inside the change's transaction. */
    public static function write(ChatConversation $conversation, string $body): ChatMessage
    {
        $line = new ChatMessage;
        $line->fill(['conversation_id' => $conversation->id, 'kind' => Vocabulary::SYSTEM, 'body' => $body]);
        $line->save();

        $conversation->last_message_at = $line->created_at;
        $conversation->save();

        return $line;
    }

    /**
     * "Jose", "Jose and Liza", "Jose, Liza and Ramon", "Jose, Liza and 3 others".
     *
     * @param  list<string>  $names
     */
    public static function names(array $names): string
    {
        return match (true) {
            count($names) <= 1 => $names[0] ?? 'someone',
            count($names) <= 3 => implode(', ', array_slice($names, 0, -1)).' and '.$names[count($names) - 1],
            default => implode(', ', array_slice($names, 0, 2)).' and '.(count($names) - 2).' others',
        };
    }
}
