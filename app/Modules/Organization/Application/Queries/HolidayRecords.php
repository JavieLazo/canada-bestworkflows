<?php

declare(strict_types=1);

namespace App\Modules\Organization\Application\Queries;

use App\Models\Holiday;
use App\Modules\Organization\Contracts\Views\HolidayView;
use App\Platform\ReadModels\QueryPage;
use App\Platform\ReadModels\Snapshot;
use App\Support\Access;
use Illuminate\Pagination\LengthAwarePaginator;

/** Holiday editor reads retain the existing SQL filtering, ordering and page clamp. */
final class HolidayRecords
{
    public function find(Access $actor, string $id): ?HolidayView
    {
        abort_if($actor->userId === null, 403);
        $row = Holiday::query()->find($id);

        return $row === null ? null : Snapshot::of($row, HolidayView::class);
    }

    /** @return LengthAwarePaginator<int, HolidayView> */
    public function page(Access $actor, string $type, string $search, string $sort, string $direction, int $page, int $perPage): LengthAwarePaginator
    {
        abort_if($actor->userId === null, 403);
        $query = Holiday::query();
        if ($type !== 'ALL') {
            $query->where('type', $type);
        }
        if (trim($search) !== '') {
            $like = '%'.addcslashes(trim($search), '%_\\').'%';
            $query->where(static fn ($q) => $q->where('code', 'like', $like)->orWhere('name', 'like', $like));
        }
        if ($sort === 'type') {
            $dir = $direction === 'desc' ? 'desc' : 'asc';
            $query->orderBy('type', $dir)->orderBy('name', $dir);
        } else {
            // 'id' sorts numerically — see App\Support\CodeSort.
            QueryPage::sort($query, $sort, $direction, ['id' => 'code', 'name' => 'name', 'date' => 'date'], ['id']);
        }

        return QueryPage::get($query, $page, $perPage, HolidayView::class);
    }
}
