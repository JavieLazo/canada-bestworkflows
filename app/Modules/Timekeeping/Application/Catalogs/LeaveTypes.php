<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application\Catalogs;

use App\Models\EmployeeLeavePolicy;
use App\Models\LeaveCashOut;
use App\Models\LeaveCredit;
use App\Models\LeaveCreditEntry;
use App\Models\LeaveEarningPosting;
use App\Models\LeaveTravelRequest;
use App\Models\LeaveType;
use App\Modules\Timekeeping\Application\LeaveCashOuts;
use App\Modules\Timekeeping\Application\LeavePolicyStore;
use App\Platform\Records\ReferenceCatalog;
use App\Support\HrisFormat;

/**
 * Owns this catalog's persistence while sharing its name/description editor
 * mechanics. A leave type's TOKEN (its normalised name) is what requests,
 * credits, ledger entries, cash-outs, earning postings and the employees'
 * leave policies carry, so a delete is refused while any of them still
 * names it, counted unscoped — a type with credits behind it cannot vanish
 * from the ledger by a menu click.
 */
final class LeaveTypes extends ReferenceCatalog
{
    protected function model(): string
    {
        return LeaveType::class;
    }

    protected function prefix(): string
    {
        return 'LVE';
    }

    protected function noun(): string
    {
        return 'leave type';
    }

    protected function inUse(string $name): int
    {
        $token = HrisFormat::normalizeRequestType($name);
        if ($token === '') {
            return 0;
        }
        // A request filed before the `_LEAVE` spelling settled may carry the
        // bare word; both spellings are this type's.
        $spellings = array_values(array_unique([$token, (string) preg_replace('/_LEAVE$/', '', $token)]));
        $count = LeaveTravelRequest::query()->whereIn('request_type', $spellings)->count()
            + LeaveCredit::query()->where('request_type', $token)->count()
            + LeaveCreditEntry::query()->where('request_type', $token)->count();
        if (LeaveCashOuts::ready()) {
            $count += LeaveCashOut::query()->where('request_type', $token)->count();
        }
        if (LeavePolicyStore::ready()) {
            $count += LeaveEarningPosting::query()->where('request_type', $token)->count();
            foreach (EmployeeLeavePolicy::query()->get(['params']) as $policy) {
                $params = $policy->params;
                $named = array_keys($params['earning'] ?? []);
                $named = [...$named, ...($params['charges']['late'] ?? []), ...($params['charges']['absence'] ?? [])];
                if (in_array($token, array_map('strval', $named), true)) {
                    $count++;
                }
            }
        }

        return $count;
    }
}
