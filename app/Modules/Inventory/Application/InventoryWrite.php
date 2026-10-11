<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Domain\Enums\ModuleCode;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\InventoryReceipt;
use App\Models\InventoryReceiptLine;
use App\Modules\Organization\Contracts\OrganizationReferences;
use App\Modules\Workforce\Contracts\InventoryEmployees;
use App\Platform\Database\TransactionMutex;
use App\Support\Access;
use App\Support\CodeSequence;
use App\Support\ManilaClock;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use RuntimeException;

/** Serializes inventory decisions and builds immutable evidence from current, locked facts. */
final class InventoryWrite
{
    public const string LOCK = 'copems:inventory';

    public static function lock(): void
    {
        TransactionMutex::acquire(OrganizationReferences::STRUCTURE_LOCK);
        TransactionMutex::acquire(self::LOCK);
    }

    public static function assertManager(Access $actor): void
    {
        if (! $actor->isSignedIn() || ! $actor->mayReadAllData(ModuleCode::INVENTORY)) {
            throw new RuntimeException('Your role cannot manage inventory.');
        }
    }

    /** @return array{id:string,name:string,position:?string,department:?string,unit:?string,is_active:bool} */
    public static function employee(Access $actor, string $id, bool $active = true): array
    {
        self::assertManager($actor);
        $row = app(InventoryEmployees::class)->identities($actor, [$id], true)[$id] ?? null;
        if ($row === null || ($active && ! $row['is_active'])) {
            throw new RuntimeException('Choose an active employee record.');
        }

        return $row;
    }

    public static function date(string $date, ?string $minimum = null): string
    {
        $parts = explode('-', $date);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1
            || ! checkdate((int) ($parts[1] ?? 0), (int) ($parts[2] ?? 0), (int) $parts[0])
            || $date > ManilaClock::todayKey()) {
            throw new RuntimeException('Use a real calendar date that is not in the future.');
        }
        if ($minimum !== null && $date < $minimum) {
            throw new RuntimeException("The date must be on or after {$minimum}.");
        }

        return $date;
    }

    public static function mrNumber(string $mrNumber): string
    {
        $mrNumber = trim($mrNumber);
        if ($mrNumber === '' || mb_strlen($mrNumber) > 80) {
            throw new RuntimeException('Enter an MR No. of at most 80 characters.');
        }

        return $mrNumber;
    }

    public static function remarks(string $remarks, bool $required = false): string
    {
        $remarks = trim($remarks);
        if (($required && $remarks === '') || mb_strlen($remarks) > 5000) {
            throw new RuntimeException($required ? 'A reason of at most 5,000 characters is required.' : 'Remarks must be at most 5,000 characters.');
        }

        return $remarks;
    }

    /** @param array<mixed> $ids
     * @return Collection<int, InventoryItem>
     */
    public static function items(array $ids): Collection
    {
        if ($ids === [] || count($ids) > 200 || count(array_filter($ids, static fn (mixed $id): bool => is_string($id) && $id !== '')) !== count($ids)) {
            throw new RuntimeException('Choose between 1 and 200 inventory items.');
        }
        $ids = array_values(array_unique($ids));
        $rows = InventoryItem::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get();
        if ($rows->count() !== count($ids)) {
            throw new RuntimeException('An inventory item no longer exists. Refresh the list.');
        }

        return $rows;
    }

    /** @param class-string<InventoryItem|InventoryReceipt> $model */
    public static function nextCode(string $model, string $prefix): string
    {
        return CodeSequence::next($prefix, $model::query()->pluck('code'));
    }

    public static function itemDate(InventoryItem $item, string $date): void
    {
        $latest = InventoryMovement::query()->where('item_id', $item->id)->max('occurred_on');
        self::date($date, max($item->acquired_on->format('Y-m-d'), is_string($latest) ? $latest : ''));
    }

    /** @return array<string, mixed> */
    public static function itemSnapshot(InventoryItem $item): array
    {
        return [
            'id' => $item->id, 'code' => $item->code, 'name' => $item->name,
            'serial_no' => $item->serial_no, 'acquired_on' => $item->acquired_on->format('Y-m-d'),
            'cost' => $item->cost, 'category' => $item->category_id === null ? null : InventoryCategory::query()->whereKey($item->category_id)->value('name'),
            'location' => $item->location_id === null ? null : InventoryLocation::query()->whereKey($item->location_id)->value('name'),
            'condition' => $item->condition, 'remarks' => $item->remarks,
        ];
    }

    /** @param array<string,mixed> $employee
     * @param  array<string,mixed>  $issuer
     */
    public static function receipt(Access $actor, array $employee, array $issuer, string $date, string $mrNumber, string $remarks, ?string $correctedFrom = null): InventoryReceipt
    {
        $mrNumber = self::mrNumber($mrNumber);
        if (InventoryReceipt::query()->where('mr_number', $mrNumber)->exists()) {
            throw new RuntimeException('That MR No. is already in use. Enter a different number.');
        }
        $row = new InventoryReceipt;
        $row->fill([
            'code' => self::nextCode(InventoryReceipt::class, 'MR'), 'mr_number' => $mrNumber,
            'employee_id' => $employee['id'], 'issuer_employee_id' => $issuer['id'],
            'issued_on' => $date, 'office_name' => mb_substr((string) config('copems.inventory.office_name', config('app.name')), 0, 240),
            'employee_snapshot' => $employee, 'issuer_snapshot' => $issuer,
            'remarks' => $remarks, 'status' => 'OPEN', 'corrected_from_id' => $correctedFrom,
        ]);
        $row->recorded_by = $actor->userId === null ? null : (int) $actor->userId;
        $row->recorder_name = mb_substr($actor->fullName, 0, 200);
        try {
            $row->save();
        } catch (UniqueConstraintViolationException) {
            throw new RuntimeException('The receipt number is already in use. Refresh and enter a different MR No.');
        }

        return $row;
    }

    public static function attach(InventoryItem $item, InventoryReceipt $receipt): void
    {
        InventoryReceiptLine::query()->create([
            'receipt_id' => $receipt->id, 'item_id' => $item->id, 'status' => 'ISSUED',
            'item_snapshot' => self::itemSnapshot($item),
        ]);
        $item->fill(['status' => 'ISSUED', 'employee_id' => $receipt->employee_id, 'current_receipt_id' => $receipt->id])->save();
    }

    public static function endLine(InventoryItem $item, string $status, string $date): string
    {
        if ($item->status !== 'ISSUED' || $item->current_receipt_id === null) {
            throw new RuntimeException('Only currently issued items can move or return.');
        }
        $line = InventoryReceiptLine::query()->where('item_id', $item->id)->where('receipt_id', $item->current_receipt_id)->where('status', 'ISSUED')->lockForUpdate()->first();
        if ($line === null) {
            throw new RuntimeException('The current receipt no longer matches this item. Refresh the list.');
        }
        $receipt = InventoryReceipt::query()->findOrFail($item->current_receipt_id);
        self::date($date, $receipt->issued_on->format('Y-m-d'));
        $line->fill(['status' => $status, 'ended_on' => $date])->save();

        return $receipt->id;
    }

    public static function closeReceipt(string $id): void
    {
        if (! InventoryReceiptLine::query()->where('receipt_id', $id)->where('status', 'ISSUED')->exists()) {
            InventoryReceipt::query()->whereKey($id)->where('status', 'OPEN')->update(['status' => 'CLOSED']);
        }
    }

    public static function movement(Access $actor, InventoryItem $item, string $type, string $date, ?string $receiptId, ?string $fromEmployee, ?string $toEmployee, string $remarks): void
    {
        $row = new InventoryMovement;
        $row->fill([
            'item_id' => $item->id, 'receipt_id' => $receiptId, 'event_type' => $type,
            'from_employee_id' => $fromEmployee, 'to_employee_id' => $toEmployee,
            'occurred_on' => $date, 'remarks' => $remarks,
        ]);
        $row->actor_id = $actor->userId === null ? null : (int) $actor->userId;
        $row->actor_name = mb_substr($actor->fullName, 0, 200);
        $row->save();
    }
}
