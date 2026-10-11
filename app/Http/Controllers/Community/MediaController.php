<?php

declare(strict_types=1);

namespace App\Http\Controllers\Community;

use App\Http\Controllers\Controller;
use App\Modules\Social\Application\Queries\CommunityFeed;
use App\Support\Access;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * What Community keeps on the disk, streamed from outside the web root:
 * photos on posts and comments (inline, or as a download with
 * `?download=1`), files (always a download, under their own name), and
 * group photos. CommunityFeed decides who may see each one, so a photo or
 * file in a private group is as private as the post it's on.
 */
final class MediaController extends Controller
{
    public function photo(string $photo): StreamedResponse
    {
        return app(CommunityFeed::class)->photo(app(Access::class), $photo, request()->boolean('download'));
    }

    public function file(string $file): StreamedResponse
    {
        return app(CommunityFeed::class)->file(app(Access::class), $file);
    }

    public function groupPhoto(string $group): StreamedResponse
    {
        return app(CommunityFeed::class)->groupPhoto(app(Access::class), $group);
    }
}
