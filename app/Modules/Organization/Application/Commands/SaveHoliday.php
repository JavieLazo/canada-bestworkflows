<?php

declare(strict_types=1);

namespace App\Modules\Organization\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Models\Holiday;
use App\Modules\Organization\Application\HolidayDraft;
use App\Support\Access;
use App\Support\AuditService;
use App\Support\CodeSequence;

/** Owns validation, persistence and audit for the existing reference editor. */
final class SaveHoliday
{
    /** holidays-page.tsx:44-47. */
    public const array SCHEDULES = ['ANNUAL' => 'Every year', 'ONE_TIME' => 'One time'];

    /** holidays-page.tsx:49-52. */
    public const array TYPES = ['REGULAR' => 'Regular Holiday', 'SPECIAL_NON_WORKING' => 'Special Non-Working Holiday'];

    public function handle(Access $access, ?string $id, HolidayDraft $input): string
    {
        $name = trim($input->formName);
        if ($name === '') {
            throw new \RuntimeException('Name is required.');
        }
        // An empty date would store "" — which reads back as an invalid date
        // and never matches in holidayOn(), so the holiday would silently
        // never apply.
        if ($input->formDate === '') {
            throw new \RuntimeException('Date is required.');
        }
        $percent = is_numeric($input->formPercent) ? (float) $input->formPercent : NAN;
        if (! is_finite($percent) || $percent < 0) {
            throw new \RuntimeException('Percent must be a positive number.');
        }

        if (mb_strlen($name) > 160) {
            throw new \RuntimeException('name: must be at most 160 characters.');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $input->formDate) !== 1) {
            throw new \RuntimeException('date: must be a real YYYY-MM-DD calendar date.');
        }
        if ($percent > 999) {
            throw new \RuntimeException('percent: must be at most 999.');
        }
        if (! isset(self::SCHEDULES[$input->formSchedule]) || ! isset(self::TYPES[$input->formType])) {
            throw new \RuntimeException('That schedule or type is not recognised.');
        }
        // requireAllDataModule("RECORDS") — re-checked in the action itself.
        if (! $access->mayReadAllData(ModuleCode::RECORDS)) {
            throw new \RuntimeException('Your role cannot manage organization-wide data.');
        }
        $payload = ['name' => $name, 'date' => $input->formDate, 'schedule' => $input->formSchedule, 'type' => $input->formType, 'percent' => $percent];
        if ($id !== null) {
            $row = Holiday::query()->find($id);
            if ($row === null) {
                throw new \RuntimeException('That holiday no longer exists.');
            }
            $row->fill($payload)->save();
            AuditService::record($access, AuditEvent::HOLIDAY_UPDATED, 'holidays', (string) $row->getKey(), "Holiday {$row->code} updated — {$name}");

            return "{$name} updated.";
        } else {
            $code = CodeSequence::next('HOL', Holiday::query()->pluck('code'));
            $row = Holiday::query()->create($payload + ['code' => $code]);
            AuditService::record($access, AuditEvent::HOLIDAY_CREATED, 'holidays', (string) $row->getKey(), "{$name} added as holiday {$code}");

            return "{$name} added.";
        }
    }
}
