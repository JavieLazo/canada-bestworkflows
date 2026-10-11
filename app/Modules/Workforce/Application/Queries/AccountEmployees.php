<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Queries;

use App\Domain\Enums\ModuleCode;
use App\Models\EmployeeProfile;
use App\Modules\Workforce\Contracts\DirectoryEmployee;
use App\Modules\Workforce\Contracts\EmployeeDirectory;
use App\Platform\ReadModels\Snapshot;
use App\Support\Access;
use Illuminate\Support\Collection;

/**
 * Account linking has a separate USERS gate, not HR's private employee profile access.
 *
 * THE CONTACT EMAIL IS THE ONE PERSONAL DETAIL READ HERE, and only for an
 * actor who may provision the account it is offered to.
 */
final class AccountEmployees
{
    /** @return Collection<int, DirectoryEmployee> */
    public function list(Access $actor): Collection
    {
        if (! $actor->hasModule(ModuleCode::USERS)) {
            return collect();
        }

        return EmployeeProfile::query()->orderBy('last_name')
            ->get(['id', 'employee_code', 'employee_id_no', 'first_name', 'last_name', 'suffix', 'position', 'employment_status', 'is_active', 'photo_path', 'work_site_id'])
            ->map(static fn (EmployeeProfile $row): DirectoryEmployee => Snapshot::of($row, DirectoryEmployee::class));
    }

    public function exists(Access $actor, string $id): bool
    {
        return $actor->hasModule(ModuleCode::USERS) && EmployeeProfile::query()->whereKey($id)->exists();
    }

    /**
     * The record's contact email, offered as a new account's starting login
     * email. The two stay separate afterwards: nothing copies a later change
     * of either onto the other.
     *
     * ACTIVE RECORDS ONLY, the rule {@see EmployeeDirectory::canLinkAccount()}
     * applies at the save: no account can be linked to an inactive record,
     * so there is nothing to offer its email to.
     */
    public function contactEmail(Access $actor, string $id): ?string
    {
        if (! $actor->mayReadAllData(ModuleCode::USERS)) {
            return null;
        }

        $email = EmployeeProfile::query()->whereKey($id)->where('is_active', true)->value('email');
        $email = is_string($email) ? trim($email) : '';

        return $email === '' ? null : $email;
    }
}
