<?php

declare(strict_types=1);

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Modules\Social\Application\Queries\ChatInbox;
use App\Support\Access;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * What Chat keeps on the disk, streamed from outside the web root: photos in
 * messages (inline, or as a download with `?download=1`), files (always a
 * download, under their own name), and group chat photos. Only for people in
 * the conversation; anyone else gets "not found".
 */
final class MediaController extends Controller
{
    public function photo(string $photo): StreamedResponse
    {
        return app(ChatInbox::class)->photo(app(Access::class), $photo, request()->boolean('download'));
    }

    public function file(string $file): StreamedResponse
    {
        return app(ChatInbox::class)->file(app(Access::class), $file);
    }

    public function conversationPhoto(string $conversation): StreamedResponse
    {
        return app(ChatInbox::class)->conversationPhoto(app(Access::class), $conversation);
    }
}
