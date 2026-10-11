<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Infrastructure;

use App\Models\PayrollFinalizationSnapshot;
use App\Models\PayrollRun;
use App\Models\PayrollStatutoryTable;
use App\Modules\Payroll\Application\StatutoryTables;
use App\Platform\Retention\AccountReferenceSource;

/** Keeps account attribution checks beside the records this owner persists. */
final class AccountReferences implements AccountReferenceSource
{
    public function references(int|string $accountId): bool
    {
        return PayrollRun::query()->where('finalized_by', $accountId)->exists()
            || PayrollFinalizationSnapshot::query()->where('finalized_by', $accountId)->exists()
            || (StatutoryTables::ready() && PayrollStatutoryTable::query()->where('created_by', $accountId)->exists());
    }
}
