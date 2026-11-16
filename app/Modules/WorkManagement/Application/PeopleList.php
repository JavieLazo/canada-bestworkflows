<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application;

use App\Modules\WorkManagement\Contracts\Views\WorkMemberView;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

final class PeopleList
{
    /** @param Collection<int, WorkMemberView> $people
     * @return LengthAwarePaginator<int, WorkMemberView>
     */
    public static function page(Collection $people, string $search = '', string $access = 'ALL', string $sort = 'name', string $direction = 'asc', int $page = 1, int $perPage = 20): LengthAwarePaginator
    {
        $terms = preg_split('/\s+/', mb_strtolower(mb_substr(trim($search), 0, 200)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $rows = $people->filter(static function (WorkMemberView $person) use ($terms, $access): bool {
            if ($access !== 'ALL' && ! ($access === 'ASSIGNED' ? in_array($person->access, ['ASSIGNED', 'PARTICIPANT'], true) : $person->access === $access)) {
                return false;
            }
            $text = mb_strtolower($person->name.' '.$person->access_label.' '.($person->position ?? ''));
            foreach ($terms as $term) {
                if (! str_contains($text, $term)) {
                    return false;
                }
            }

            return true;
        })->sort(static function (WorkMemberView $a, WorkMemberView $b) use ($sort, $direction): int {
            $value = $sort === 'work' ? $a->open_tasks <=> $b->open_tasks : strnatcasecmp($a->name, $b->name);

            return ($direction === 'desc' ? -$value : $value) ?: strcmp((string) $a->id, (string) $b->id);
        })->values();
        $size = max(1, min(100, $perPage));
        $current = max(1, min($page, max(1, (int) ceil($rows->count() / $size))));

        return new LengthAwarePaginator($rows->slice(($current - 1) * $size, $size)->values(), $rows->count(), $size, $current, ['pageName' => 'peoplePage']);
    }
}
