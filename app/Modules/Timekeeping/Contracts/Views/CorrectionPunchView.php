<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Contracts\Views;

use App\Platform\ReadModels\Record;
use Carbon\CarbonImmutable;

/**
 * One recorded punch as the DTR correction dialog lists it: every column of
 * the row, plus the five facts only the owner can add. Detached — no
 * persistence, no lazy relationships.
 *
 * @property-read string $id
 * @property-read string $employee_id
 * @property-read string|null $site_id
 * @property-read string $location_kind ONSITE | WFH, as recorded — a later roster edit never rewrites it
 * @property-read string $log_type
 * @property-read string $source
 * @property-read float|null $latitude
 * @property-read float|null $longitude
 * @property-read float|null $distance_meters
 * @property-read float|null $accuracy_meters
 * @property-read bool $is_geofenced
 * @property-read bool $is_anomaly
 * @property-read string|null $anomaly_note
 * @property-read CarbonImmutable $logged_at UTC, as the column holds it
 * @property-read string|null $site_name
 * @property-read string $slot The Manila HH:mm this row reads as — floored, never rounded (PunchSlots::slotOf)
 * @property-read bool $is_carried A next-day row this date's row owns: its Manila day is after the date (PunchSlots::isNextDay)
 * @property-read bool $in_dtr_slot Whether the DTR row shows it in one of its four slots — a row it owns but shows nowhere is replaced by a save unless entered
 * @property-read bool $hand_entered Entered by hand in AttendanceLog's sense: an admin's MANUAL punch at the station, or a row a saved correction inserted (CORRECTION). The dialog shows "Manually Entered" for these, with no place or map — even when a MANUAL row carries the kiosk's fix. A CORRECTION row has no coordinates at all
 */
final readonly class CorrectionPunchView extends Record {}
