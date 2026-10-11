<?php

declare(strict_types=1);

namespace App\Modules\Organization\Application\Commands;

use App\Domain\Enums\ModuleCode;
use App\Models\WorkSite;
use App\Modules\Organization\Application\SiteDraft;
use App\Platform\Transfer\ImportLayout;
use App\Platform\Transfer\ImportOutcome;
use App\Platform\Transfer\ImportRow;
use App\Platform\Transfer\TableImport;
use App\Support\Access;
use RuntimeException;

/**
 * Site Settings read back from CSV, one row at a time through
 * {@see SaveSite} — its coordinate and radius rules, its all_data + RECORDS
 * gate and its audit row.
 *
 * Sites carry no code, so the export's ID column is the site's own id: kept,
 * the row updates that site (a rename included); blank, it adds one. A site
 * is never deleted from here — DeleteSite's reference checks stay on the
 * screen.
 */
final class ImportSites implements TableImport
{
    /** The columns an import reads — the Site Settings export's own, less Punches. */
    public const array COLUMNS = ['ID', 'Site', 'Latitude', 'Longitude', 'Radius (m)', 'Geofence'];

    private const array GEOFENCE = ['ENFORCED' => 'Enforced', 'EXEMPT' => 'Exempt'];

    public function layout(Access $actor, string $context): ImportLayout
    {
        return new ImportLayout('sites', 'ID', self::COLUMNS, [
            'Geofence is Enforced or Exempt; blank means Enforced. An enforced site needs both coordinates and a radius in whole metres.',
            'A new radius only affects punches made from now on.',
        ]);
    }

    public function allows(Access $actor, string $context): bool
    {
        return $actor->mayReadAllData(ModuleCode::RECORDS);
    }

    public function row(Access $actor, string $context, ImportRow $row): ImportOutcome
    {
        abort_unless($this->allows($actor, $context), 403);
        $id = $row->identifier('ID');
        $site = null;
        if ($id !== '') {
            $site = WorkSite::query()->find($id);
            if ($site === null) {
                throw new RuntimeException("No site has ID {$id}. Leave ID blank to add a new site.");
            }
        }

        $radius = $row->integer('Radius (m)');
        $draft = new SiteDraft(
            $row->text('Site'),
            $row->text('Latitude'),
            $row->text('Longitude'),
            $radius === null ? '' : (string) $radius,
            $row->choice('Geofence', self::GEOFENCE) === 'EXEMPT',
        );

        if ($site !== null && self::unchanged($site, $draft)) {
            return ImportOutcome::UNCHANGED;
        }

        app(SaveSite::class)->handle($actor, $site === null ? null : (string) $site->getKey(), $draft);

        return $site === null ? ImportOutcome::CREATED : ImportOutcome::UPDATED;
    }

    /** Whether SaveSite would write exactly what the site already holds (its payload rules, restated). */
    private static function unchanged(WorkSite $site, SiteDraft $draft): bool
    {
        $number = static fn (string $value): float => is_numeric($value) ? (float) $value : 0.0;

        return $site->name === trim($draft->formName)
            && (bool) $site->geofence_exempt === $draft->formExempt
            && (float) $site->latitude === $number($draft->formLatitude)
            && (float) $site->longitude === $number($draft->formLongitude)
            && (int) $site->radius_meters === (is_numeric($draft->formRadius) ? (int) $draft->formRadius : 0);
    }
}
