<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application\Queries;

use App\Models\LeavePolicyPreset;
use App\Modules\Timekeeping\Application\LeaveSettingsAccess;
use App\Modules\Timekeeping\Contracts\Views\LeavePresetView;
use App\Modules\Timekeeping\Domain\Credits\LeavePolicy;
use App\Platform\ReadModels\Snapshot;
use App\Support\Access;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/** The leave policy presets, by name, for whoever may open Leave Credits settings or prepare a policy. */
final class LeavePresetRecords
{
    public static function ready(): bool
    {
        return once(static fn (): bool => Schema::hasTable('leave_policy_presets'));
    }

    /** @return Collection<int, LeavePresetView> */
    public function list(Access $access): Collection
    {
        if (! LeaveSettingsAccess::mayView($access) || ! self::ready()) {
            return new Collection;
        }
        $names = self::typeNames();

        return LeavePolicyPreset::query()->orderBy('name')->get()
            ->map(static fn (LeavePolicyPreset $row): LeavePresetView => Snapshot::of($row, LeavePresetView::class, ['summary' => LeavePolicy::fromParams($row->params)->describe($names)]));
    }

    public function find(Access $access, string $id): ?LeavePresetView
    {
        if (! LeaveSettingsAccess::mayView($access) || ! self::ready()) {
            return null;
        }
        $row = LeavePolicyPreset::query()->whereKey($id)->first();

        return $row === null ? null : Snapshot::of($row, LeavePresetView::class, ['summary' => LeavePolicy::fromParams($row->params)->describe(self::typeNames())]);
    }

    /** @return array<string, string> token => name */
    public static function typeNames(): array
    {
        $names = [];
        foreach (LeaveTypeCreditRecords::rules() as $token => $entry) {
            $names[$token] = $entry['name'];
        }

        return $names;
    }
}
