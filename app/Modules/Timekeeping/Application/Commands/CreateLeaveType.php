<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application\Commands;

use App\Models\LeaveType;
use App\Modules\Timekeeping\Application\Catalogs\LeaveTypes;
use App\Support\Access;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * ADDS A LEAVE TYPE WHOLE, in one step: its name and description (the
 * Leave Settings catalog), its settings that apply at once (what may be
 * charged to it, cash-out, year end) and — when one is given — its rule,
 * in force from today. Nobody has to add the name on one screen and come
 * back for the rest on another.
 *
 * One transaction: a rule that is refused leaves no half-made leave type
 * behind. Each part is written by the command that already owns it and
 * authorises for itself — adding to the catalog needs all-data RECORDS,
 * the settings and the rule whoever manages the leave credit settings — so
 * this command adds no rule of its own. With no rule the type is the
 * employee's from the first day and earns nothing, and no version is
 * written: a first version is what puts the leave rules in force.
 */
final class CreateLeaveType
{
    public function __construct(
        private readonly LeaveTypes $catalog,
        private readonly SaveLeaveTypeCredits $settings,
        private readonly SaveLeaveRule $rules,
    ) {}

    /**
     * @param  array<string, mixed>  $settings  LeaveTypeCredits input
     * @param  array<string, mixed>|null  $rule  LeaveTypeRule params, or null to add the type with no rule
     * @return array{type_id: string, rule_id: ?string}
     */
    public function handle(Access $access, string $name, string $description, array $settings, ?array $rule): array
    {
        return DB::transaction(function () use ($access, $name, $description, $settings, $rule): array {
            $this->catalog->save($access, null, $name, $description);
            $typeId = LeaveType::query()->where('name', trim($name))->value('id');
            if (! is_string($typeId) || $typeId === '') {
                throw new RuntimeException('The leave type could not be added.');
            }
            $this->settings->handle($access, $typeId, $settings);

            return ['type_id' => $typeId, 'rule_id' => $rule === null ? null : $this->rules->handle($access, $typeId, $rule)];
        });
    }
}
