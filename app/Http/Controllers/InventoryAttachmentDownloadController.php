<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Modules\Inventory\Application\Queries\InventoryAttachments;
use App\Support\Access;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Delegates private evidence downloads to the owner before opening stored bytes. */
class InventoryAttachmentDownloadController extends Controller
{
    public function __invoke(Request $request, string $id): StreamedResponse
    {
        return app(InventoryAttachments::class)->download(app(Access::class), $id, $request->route('own') === '1');
    }
}
