<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application\Catalogs;

use App\Models\LeaveAcquisition;
use App\Models\LeaveCashOut;
use App\Models\LeaveCredit;
use App\Models\LeaveCreditEntry;
use App\Models\LeaveEarningPosting;
use App\Models\LeaveRule;
use App\Models\LeaveTravelRequest;
use App\Models\LeaveType;
use App\Modules\Timekeeping\Application\LeaveCashOuts;
use App\Modules\Timekeeping\Application\LeaveRules;
use App\Platform\Records\ReferenceCatalog;
use App\Support\HrisFormat;

/**
 * Owns this catalog's persistence while sharing its name/description editor
 * mechanics. A leave type's TOKEN (its normalised name) is what requests,
 * credits, ledger entries, cash-outs, earning postings, the leave rules
 * and the days employees acquired the type carry, so a delete is refused
 * while any of them still
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
        if (LeaveRules::postingsReady()) {
            $count += LeaveEarningPosting::query()->where('request_type', $token)->count();
        }

        if (LeaveRules::ready()) {
            // The type's own rule versions, the versions of other types
            // that fall back on it, and the days employees acquired it.
            $count += LeaveAcquisition::query()->where('request_type', $token)->count();
            foreach (LeaveRule::query()->get(['scope', 'params']) as $rule) {
                if ($rule->scope === $token || ($rule->params['fallback'] ?? null) === $token) {
                    $count++;
                }
            }
        }

        return $count;
    }
}
