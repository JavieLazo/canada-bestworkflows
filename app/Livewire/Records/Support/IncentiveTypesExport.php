<?php

declare(strict_types=1);

namespace App\Livewire\Records\Support;

use App\Modules\Payroll\Application\Commands\ImportIncentiveTypes;
use App\Modules\Payroll\Domain\Benefits\BenefitRule;
use App\Platform\Records\ReferenceCatalogs;
use App\Platform\Transfer\CsvExport;
use App\Platform\Transfer\TableExport;
use App\Support\Access;
use App\Support\ManilaClock;
use Generator;

/**
 * Incentives & Bonuses as CSV, listed as the table lists them
 * ({@see IncentiveTypeRows}) — the page any RECORDS or PAYROLL holder can
 * read. The same header is the import template ({@see ImportIncentiveTypes}).
 */
final class IncentiveTypesExport implements TableExport
{
    public function export(Access $actor, array $query): CsvExport
    {
        abort_if($actor->userId === null, 403);
        $search = $query['q'] ?? '';
        $sort = in_array($query['sort'] ?? '', ['id', 'name', 'taxable'], true) ? (string) $query['sort'] : 'id';
        $direction = ($query['dir'] ?? '') === 'desc' ? 'desc' : 'asc';

        $rows = static function () use ($actor, $search, $sort, $direction): Generator {
            $statusNames = [];
            foreach (app(ReferenceCatalogs::class)->for('employment')->options($actor) as $status) {
                $statusNames[$status['id']] = $status['name'];
            }
            foreach (IncentiveTypeRows::list($actor, $search, $sort, $direction) as $type) {
                $statuses = is_array($type->statuses ?? null) ? $type->statuses : [];
                yield [
                    $type->code, $type->name,
                    ImportIncentiveTypes::TAX[BenefitText::tax($type)],
                    $type->description ?? '',
                    ImportIncentiveTypes::AMOUNT_TYPES[$type->amount_type ?? BenefitRule::AMOUNT_TYPED] ?? '',
                    is_numeric($type->amount ?? null) ? Hris::moneyText($type->amount) : '',
                    ImportIncentiveTypes::FREQUENCIES[$type->frequency ?? BenefitRule::FREQUENCY_ONCE] ?? '',
                    $type->month ?? '',
                    (string) ($type->min_service_months ?? 0),
                    ImportIncentiveTypes::PRORATIONS[$type->proration ?? BenefitRule::PRORATION_NONE] ?? '',
                    implode('; ', array_map(static fn (mixed $id): string => $statusNames[(string) $id] ?? '', $statuses)),
                    is_numeric($type->cap ?? null) ? Hris::moneyText($type->cap) : '',
                    $type->approval_reference ?? '',
                    ($type->counts_toward_13th ?? false) ? 'Yes' : 'No',
                ];
            }
        };

        return new CsvExport('incentive-types-'.ManilaClock::todayKey().'.csv', ImportIncentiveTypes::COLUMNS, $rows);
    }
}
