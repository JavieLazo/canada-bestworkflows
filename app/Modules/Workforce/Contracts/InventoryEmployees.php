<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Contracts;

use App\Support\Access;

/** Employee identity and placement for property custody, independent of login accounts. */
interface InventoryEmployees
{
    /**
     * A locked read requires an existing transaction and the structure mutex
     * before any employee rows. Missing or out-of-scope ids are omitted.
     *
     * @param  list<string>  $ids
     * @return array<string, array{id: string, name: string, position: ?string, department: ?string, unit: ?string, is_active: bool}>
     */
    public function identities(Access $actor, array $ids, bool $lock = false): array;

    /** Current visible identity changes invalidate Inventory's live holder labels. */
    public function changeStamp(Access $actor): string;
}
