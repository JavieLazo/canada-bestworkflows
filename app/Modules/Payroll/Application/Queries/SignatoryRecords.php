<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application\Queries;

use App\Models\PayrollSignatory;
use App\Modules\Payroll\Contracts\Views\SignatoryView;
use App\Modules\Payroll\Domain\Signatories\SignatorySlot;
use App\Support\Access;
use Illuminate\Support\Facades\Schema;

/**
 * The signatories a payroll document prints, one view per slot in print
 * order, a slot nobody has set reading as blanks. Readable by any signed-in
 * account — an employee's own payslip on My Portal prints them too — and
 * written only through SaveSignatory, which checks who manages Payroll.
 *
 * Files reach the host before the cron runs their migration, so while the
 * table is absent every slot reads blank and the page says so.
 */
final class SignatoryRecords
{
    public static function ready(): bool
    {
        return once(static fn (): bool => Schema::hasTable('payroll_signatories'));
    }

    /** @return list<SignatoryView> */
    public function all(Access $actor): array
    {
        abort_if($actor->userId === null, 403);
        $rows = self::ready()
            ? PayrollSignatory::query()->get()->keyBy('slot')
            : collect();

        return array_map(static function (string $slot) use ($rows): SignatoryView {
            $row = $rows->get($slot);
            if ($row === null) {
                return new SignatoryView($slot, SignatorySlot::label($slot), '', '');
            }

            return new SignatoryView($slot, SignatorySlot::label($slot), (string) ($row->name ?? ''), (string) ($row->designation ?? ''));
        }, SignatorySlot::all());
    }
}
