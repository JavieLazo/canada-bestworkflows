<?php

declare(strict_types=1);

namespace App\Platform\Retention;

use App\Models\Notification;

/** Keeps account attribution checks beside the records this owner persists. */
final class AccountReferences implements AccountReferenceSource
{
    public function references(int|string $accountId): bool
    {
        return Notification::query()->where('user_id', $accountId)->exists();
    }
}
