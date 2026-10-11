<?php

declare(strict_types=1);

namespace App\Modules\Social\Application;

use App\Models\SocialPreference;
use App\Modules\Social\Contracts\Views\PrivacyView;
use App\Support\Access;

/**
 * What people chose to show about themselves, read in bulk.
 *
 * Only someone who changed a switch has a row, so every lookup asks for the
 * exceptions and treats everyone else as the default: job title shown,
 * birthday and work anniversary hidden. A feed of 10 posts costs one small
 * query, not one per person.
 */
final class Privacy
{
    /**
     * Of the accounts given, the ones hiding their job title.
     *
     * @param  array<array-key, int>  $accountIds
     * @return list<int>
     */
    public function hiddenPositions(array $accountIds): array
    {
        return $this->matching($accountIds, 'show_position', false);
    }

    /**
     * Of the accounts given, the ones showing their birthday.
     *
     * @param  array<array-key, int>  $accountIds
     * @return list<int>
     */
    public function shownBirthdays(array $accountIds): array
    {
        return $this->matching($accountIds, 'show_birthday', true);
    }

    /**
     * Of the accounts given, the ones showing their work anniversary.
     *
     * @param  array<array-key, int>  $accountIds
     * @return list<int>
     */
    public function shownAnniversaries(array $accountIds): array
    {
        return $this->matching($accountIds, 'show_anniversary', true);
    }

    /** The actor's own switches, for the card on My Profile. */
    public function of(Access $actor): PrivacyView
    {
        $me = SocialRules::member($actor);
        $row = SocialPreference::query()->where('user_id', $me)->first();

        return $row === null
            ? new PrivacyView
            : new PrivacyView((bool) $row->show_position, (bool) $row->show_birthday, (bool) $row->show_anniversary);
    }

    /**
     * @param  array<array-key, int>  $accountIds
     * @return list<int>
     */
    private function matching(array $accountIds, string $column, bool $value): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $accountIds), static fn (int $id): bool => $id > 0)));

        if ($ids === []) {
            return [];
        }

        return array_values(array_map('intval', SocialPreference::query()
            ->whereIn('user_id', $ids)
            ->where($column, $value)
            ->pluck('user_id')
            ->all()));
    }
}
