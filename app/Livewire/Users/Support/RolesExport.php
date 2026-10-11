<?php

declare(strict_types=1);

namespace App\Livewire\Users\Support;

use App\Modules\IdentityAccess\Application\Queries\GetAccounts;
use App\Platform\Transfer\CsvExport;
use App\Platform\Transfer\TableExport;
use App\Support\Access;
use App\Support\ManilaClock;
use App\Support\ModuleRegistry;
use Generator;

/**
 * User Management's Roles tab as CSV: each role, its employee-data scope,
 * and one Yes/No column per module in the registry's order — the grant
 * matrix as the tab draws it. Export only: grants change one checkbox at a
 * time, audited, with the self-lockout guards.
 */
final class RolesExport implements TableExport
{
    public function export(Access $actor, array $query): CsvExport
    {
        abort_if($actor->userId === null, 403);
        $modules = app(ModuleRegistry::class)->all();

        $rows = static function () use ($actor, $modules): Generator {
            $listing = app(GetAccounts::class)->handle($actor);
            $granted = [];
            foreach ($listing['grants'] as $grant) {
                $granted[$grant->role_id.'|'.$grant->module] = true;
            }
            foreach ($listing['roles'] as $role) {
                $id = (string) $role->getKey();
                $cells = [$role->name, $role->all_data ? 'All employees' : 'Own records'];
                foreach ($modules as $module) {
                    $cells[] = isset($granted[$id.'|'.$module['code']]) ? 'Yes' : 'No';
                }
                yield $cells;
            }
        };

        return new CsvExport(
            'roles-'.ManilaClock::todayKey().'.csv',
            ['Role', 'Scope', ...array_map(static fn (array $module): string => (string) $module['label'], $modules)],
            $rows,
        );
    }
}
