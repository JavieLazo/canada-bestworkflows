<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Modules\Inventory\Application\Queries\InventoryRecords;
use App\Support\Access;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/** Prints only the authorized receipt's frozen issuance evidence. */
class InventoryReceiptPrintController extends Controller
{
    public function __invoke(Request $request, string $id): View
    {
        $receipt = app(InventoryRecords::class)->receipt(app(Access::class), $id, $request->route('own') === '1');
        abort_if($receipt === null, 404);

        return view('livewire.inventory.print', ['receipt' => $receipt]);
    }
}
