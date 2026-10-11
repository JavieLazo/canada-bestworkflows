<?php

declare(strict_types=1);

use App\Http\Controllers\Community\MediaController;
use App\Livewire\Community\CommunityPage;
use Illuminate\Support\Facades\Route;

/*
 * Community: the company-wide feed (config/modules.php: code COMMUNITY,
 * path /community).
 *
 * A module rather than an ungated page like /notifications: every seeded role
 * holds it, but an administrator can still take it away from a role in User
 * Management, the same as any other screen.
 *
 * The media routes stream what Community keeps on the private disk: photos
 * and files on posts and comments, and group photos. The module gate keeps
 * everyone without Community out; CommunityFeed then narrows to posts the
 * caller may read, so a private group's photos and files stay inside it.
 *
 * `?post=<id>` opens one post above the feed; notifications link there.
 * `?group=<id>` opens a group, and the feed's filters ride in the address
 * too, so a refresh keeps them.
 */
Route::middleware(['web', 'auth', 'module:COMMUNITY'])->group(static function (): void {
    Route::get('/community', CommunityPage::class)->name('community.index');
    Route::get('/community/photos/{photo}', [MediaController::class, 'photo'])->name('community.photos');
    Route::get('/community/files/{file}', [MediaController::class, 'file'])->name('community.files');
    Route::get('/community/groups/{group}/photo', [MediaController::class, 'groupPhoto'])->name('community.groups.photo');
});
