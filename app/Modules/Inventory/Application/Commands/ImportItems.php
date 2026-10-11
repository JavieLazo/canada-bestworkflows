<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Commands;

use App\Domain\Enums\ModuleCode;
use App\Models\InventoryCategory;
use App\Models\InventoryCountLine;
use App\Models\InventoryDisposal;
use App\Models\InventoryIncident;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\InventoryReceiptLine;
use App\Modules\Inventory\Application\InventoryWrite;
use App\Platform\Transfer\ImportLayout;
use App\Platform\Transfer\ImportOutcome;
use App\Platform\Transfer\ImportRow;
use App\Platform\Transfer\TableImport;
use App\Support\Access;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Imports item metadata through normal validation, never custody or historical evidence. */
final class ImportItems implements TableImport
{
    public const array COLUMNS = ['Item code', 'Item', 'Serial number', 'Acquired on', 'Cost', 'Category', 'Location', 'Remarks'];

    public function layout(Access $actor, string $context): ImportLayout
    {
        return new ImportLayout('inventory items', 'Item code', self::COLUMNS, [
            'Item code is required, must be unique and accepts up to 40 characters. Format this column as Text to preserve leading zeroes.',
            'Only unused stock with no receipt, movement, incident, disposal or physical-count history can be updated.',
            'Category and Location must match existing catalog names; blank means no category or location. Dates use YYYY-MM-DD and costs must be non-negative.',
            'Custody, employees, MR numbers, condition and disposal are read-only. This import never issues, transfers, returns or deletes items.',
            'Review every row before committing. Any invalid row prevents the entire file from being saved.',
        ], 'Enter the item code on every row. An existing code updates eligible stock; a new code adds an item. To change an existing code, edit the item in Inventory.');
    }

    public function allows(Access $actor, string $context): bool
    {
        return $actor->isSignedIn() && $actor->mayReadAllData(ModuleCode::INVENTORY);
    }

    public function row(Access $actor, string $context, ImportRow $row): ImportOutcome
    {
        abort_unless($this->allows($actor, $context), 403);

        return DB::transaction(function () use ($actor, $row): ImportOutcome {
            InventoryWrite::lock();
            $code = trim($row->identifier('Item code'));
            if ($code === '' || mb_strlen($code) > 40) {
                throw new RuntimeException('Enter an item code of at most 40 characters on every row.');
            }
            $item = InventoryItem::query()->where('code', $code)->first();
            if ($item !== null) {
                if ($item->status !== 'IN_STOCK' || $item->employee_id !== null || $item->current_receipt_id !== null) {
                    throw new RuntimeException('Only unused stock can be updated by CSV.');
                }
                foreach ([InventoryReceiptLine::class, InventoryMovement::class, InventoryIncident::class, InventoryCountLine::class, InventoryDisposal::class] as $history) {
                    if ($history::query()->where('item_id', $item->id)->exists()) {
                        throw new RuntimeException('This item has recorded history. Edit its details from Inventory instead.');
                    }
                }
            }
            $data = [
                'code' => $code,
                'name' => trim($row->text('Item')), 'serial_no' => trim($row->identifier('Serial number')) ?: null,
                'acquired_on' => $row->date('Acquired on') ?? '', 'cost' => $row->money('Cost'),
                'category_id' => self::catalog('category', $row->text('Category')),
                'location_id' => self::catalog('location', $row->text('Location')),
                'remarks' => trim($row->text('Remarks')),
            ];
            if ($item !== null && $data['code'] === $item->code && $data['name'] === $item->name && $data['serial_no'] === $item->serial_no
                && $data['acquired_on'] === $item->acquired_on->format('Y-m-d') && $data['cost'] !== '' && Money::round($data['cost']) === $item->cost
                && $data['category_id'] === $item->category_id && $data['location_id'] === $item->location_id
                && $data['remarks'] === (string) $item->remarks) {
                return ImportOutcome::UNCHANGED;
            }
            app(SaveItem::class)->handle($actor, $item?->id, $data);

            return $item === null ? ImportOutcome::CREATED : ImportOutcome::UPDATED;
        });
    }

    private static function catalog(string $kind, string $name): ?string
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }
        $model = $kind === 'category' ? InventoryCategory::class : InventoryLocation::class;
        $id = $model::query()->where('name', $name)->value('id');
        if (! is_string($id)) {
            throw new RuntimeException("Unknown {$kind}: {$name}. Add it in Categories & locations first.");
        }

        return $id;
    }
}
