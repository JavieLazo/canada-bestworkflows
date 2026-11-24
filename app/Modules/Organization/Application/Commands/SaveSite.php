<?php

declare(strict_types=1);

namespace App\Modules\Organization\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Models\WorkSite;
use App\Modules\Organization\Application\SiteDraft;
use App\Support\Access;
use App\Support\AuditService;

/**
 * Owns validation, persistence and audit for the existing reference editor.
 *
 * `SiteDraft::$formExempt` is the stored `geofence_exempt`: true means a
 * punch at the site is accepted from anywhere, so its coordinates and radius
 * are not asked for; false means the site checks location and needs both.
 */
final class SaveSite
{
    public function handle(Access $access, ?string $id, SiteDraft $input): string
    {
        if (trim($input->formName) === '') {
            throw new \RuntimeException('Site name is required.');
        }
        if (! $input->formExempt) {
            // Blank boxes are checked before the numbers are: `Number("")` is 0,
            // not NaN, so an empty pair would otherwise save a site at 0, 0.
            if (trim($input->formLatitude) === '' || trim($input->formLongitude) === '') {
                throw new \RuntimeException('A site that checks location needs both coordinates.');
            }
            $la = is_numeric($input->formLatitude) ? (float) $input->formLatitude : NAN;
            $lo = is_numeric($input->formLongitude) ? (float) $input->formLongitude : NAN;
            $radius = is_numeric($input->formRadius) ? (float) $input->formRadius : NAN;
            if (! is_finite($la) || $la < -90 || $la > 90) {
                throw new \RuntimeException('Latitude must be between -90 and 90.');
            }
            if (! is_finite($lo) || $lo < -180 || $lo > 180) {
                throw new \RuntimeException('Longitude must be between -180 and 180.');
            }
            if ($la === 0.0 && $lo === 0.0) {
                throw new \RuntimeException("0, 0 is not a place. Capture the site's location or type its coordinates.");
            }
            if (! is_finite($radius) || floor($radius) !== $radius || $radius <= 0) {
                throw new \RuntimeException('Radius must be a whole number of metres, greater than 0.');
            }
        }
        if (mb_strlen(trim($input->formName)) > 160) {
            throw new \RuntimeException('name: must be at most 160 characters.');
        }
        // requireAllDataModule("RECORDS") — re-checked in the action itself.
        if (! $access->mayReadAllData(ModuleCode::RECORDS)) {
            throw new \RuntimeException('Your role cannot manage organization-wide data.');
        }
        // Number(form.latitude) — an empty box on an exempt site saves as 0.
        $payload = ['name' => trim($input->formName), 'latitude' => is_numeric($input->formLatitude) ? (float) $input->formLatitude : 0.0, 'longitude' => is_numeric($input->formLongitude) ? (float) $input->formLongitude : 0.0, 'radius_meters' => is_numeric($input->formRadius) ? (int) $input->formRadius : 0, 'geofence_exempt' => $input->formExempt];
        $name = $payload['name'];
        if ($id !== null) {
            $site = WorkSite::query()->find($id);
            if ($site === null) {
                throw new \RuntimeException('That work site no longer exists.');
            }
            $site->fill($payload)->save();
            AuditService::record($access, AuditEvent::SITE_UPDATED, 'work_sites', (string) $site->getKey(), "{$name} updated");

            return 'Work site updated.';
        } else {
            $site = WorkSite::query()->create($payload);
            AuditService::record($access, AuditEvent::SITE_CREATED, 'work_sites', (string) $site->getKey(), "{$name} added");

            return "{$name} added.";
        }
    }
}
