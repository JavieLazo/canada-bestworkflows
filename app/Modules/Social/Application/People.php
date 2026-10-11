<?php

declare(strict_types=1);

namespace App\Modules\Social\Application;

use App\Domain\Enums\ModuleCode;
use App\Modules\IdentityAccess\Contracts\AccountLabel;
use App\Modules\IdentityAccess\Contracts\AccountNames;
use App\Modules\IdentityAccess\Contracts\ModuleAccounts;
use App\Modules\Social\Contracts\Views\PersonView;
use App\Modules\Workforce\Contracts\ColleagueDirectory;
use App\Platform\Files\PortraitUrl;
use App\Support\Access;

/**
 * Account ids in, people out: the one place Community and Chat turn an author,
 * sender or participant into a name, a position and a portrait.
 *
 * Both halves come through the owning modules' contracts: names from
 * IdentityAccess, and position and portrait from Workforce's narrow
 * ColleagueDirectory. Nothing here reads another module's tables.
 */
final class People
{
    public function __construct(
        private readonly AccountNames $accounts,
        private readonly ColleagueDirectory $colleagues,
        private readonly ModuleAccounts $holders,
        private readonly Privacy $privacy,
    ) {}

    /**
     * Cards for the given accounts, keyed by id. An id that no longer
     * resolves gets a "Former member" card rather than going missing.
     *
     * @param  array<array-key, int>  $ids
     * @return array<int, PersonView>
     */
    public function cards(Access $actor, array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));

        if ($ids === []) {
            return [];
        }

        $labels = $this->accounts->list($actor, $ids);
        $cards = $this->fromLabels($actor, $labels->all());

        foreach ($ids as $id) {
            $cards[$id] ??= PersonView::missing($id);
        }

        return $cards;
    }

    public function card(Access $actor, int $id): PersonView
    {
        return $this->cards($actor, [$id])[$id] ?? PersonView::missing($id);
    }

    /**
     * Everyone who can use the module, the actor included, by name.
     *
     * @return array<int, PersonView>
     */
    public function holders(Access $actor, string $module): array
    {
        return $this->fromLabels($actor, $this->holders->holders($actor, $module)->all());
    }

    /**
     * The names a writer can @mention: everyone who holds the module but the
     * writer, keyed by account id.
     *
     * @return array<int, string>
     */
    public function mentionable(Access $actor, string $module = ModuleCode::COMMUNITY): array
    {
        $names = [];

        foreach ($this->holders->holders($actor, $module) as $label) {
            if ((string) $label->id !== $actor->userId) {
                $names[$label->id] = $label->name;
            }
        }

        return $names;
    }

    /**
     * @param  array<array-key, AccountLabel>  $labels
     * @return array<int, PersonView>
     */
    private function fromLabels(Access $actor, array $labels): array
    {
        $employeeIds = [];

        foreach ($labels as $label) {
            if ($label->employee_id !== null && $label->employee_id !== '') {
                $employeeIds[] = $label->employee_id;
            }
        }

        $colleagues = $this->colleagues->find($actor, array_values(array_unique($employeeIds)));

        // Anyone who switched their job title off on My Profile keeps their
        // name and portrait and loses the line under it.
        $hidden = $this->privacy->hiddenPositions(array_map(static fn (AccountLabel $label): int => $label->id, $labels));
        $cards = [];

        foreach ($labels as $label) {
            $colleague = $label->employee_id !== null ? $colleagues->get($label->employee_id) : null;
            $cards[$label->id] = new PersonView(
                $label->id,
                $label->name,
                in_array($label->id, $hidden, true) ? null : $colleague?->position,
                PortraitUrl::for($colleague?->photo_path),
                $label->is_active,
            );
        }

        return $cards;
    }
}
