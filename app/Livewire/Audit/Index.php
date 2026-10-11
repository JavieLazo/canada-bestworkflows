<?php

declare(strict_types=1);

namespace App\Livewire\Audit;

use App\Livewire\Audit\Support\AuditRows;
use App\Livewire\Concerns\WithSorting;
use App\Livewire\Concerns\WithTableFilters;
use App\Platform\Audit\AuditRecords;
use App\Platform\ReadModels\AuditLogView as AuditLog;
use App\Support\Access;
use App\Support\TextSort;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Audit Trail — the port of audit-page.tsx.
 *
 * Read-only: this component has no write action at all, which is the point of
 * the screen ("Append-only. Entries can never be edited or deleted from the
 * app."). The one data source is the port of `GET /api/audit?limit=500`
 * (src/app/api/audit/route.ts): the latest 500 entries, newest first — and the
 * route's `requireAllDataModule("AUDIT")` gate is reproduced in {@see render()}:
 * an actor holding the AUDIT module WITHOUT the all-data scope got a 403 from
 * the API and an empty table from the page (`data: rows = []`,
 * audit-page.tsx:19), so here the same actor reaches the page and reads zero
 * rows. Never widen that to a bare module check.
 *
 * Filtering, sorting and pagination then run over that loaded list in PHP,
 * mirroring the source's client-side `useMemo` pipeline (audit-page.tsx:45-71)
 * including its quirks: `sort()` then a whole-list `reverse()` for descending
 * (which puts blanks FIRST on desc), and the newest-first default
 * ("an audit trail is read from the most recent entry back", audit-page.tsx:25).
 */
#[Layout('layouts.app')]
#[Title('Audit Trail')]
class Index extends Component
{
    use WithSorting;
    use WithTableFilters;

    /**
     * The API route's `limit` default — the page never asks for more
     * (useAccounts.ts:30-32, audit/route.ts:19).
     */
    public const int LIMIT = 500;

    /** Action filter (audit-page.tsx:22). Plain filter state, deliberately unlocked. */
    public string $action = 'ALL';

    /** Entity filter (audit-page.tsx:23). */
    public string $entity = 'ALL';

    /** Actor filter — matched against `actor_name || "System"` (audit-page.tsx:24, 50). */
    public string $actor = 'ALL';

    public function mount(): void
    {
        // useSort<SortKey>("timestamp", "desc") — audit-page.tsx:26.
        $this->sortKey = 'timestamp';
        $this->sortDir = 'desc';
    }

    /** @return list<string> */
    protected function sortableKeys(): array
    {
        return ['timestamp', 'actor', 'action', 'entity'];
    }

    // The three selects reset to page 1 (audit-page.tsx:102, 119, 136);
    // the search box does not — the clamp handles a shrunken list.
    public function updatedAction(): void
    {
        $this->resetPage();
    }

    public function updatedEntity(): void
    {
        $this->resetPage();
    }

    public function updatedActor(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $access = app(Access::class);

        // GET /api/audit — `requireAllDataModule("AUDIT")` then the latest 500,
        // newest first (audit/route.ts:13-21). Without the scope the reference
        // page rendered its table over an empty list; so does this one.
        /** @var Collection<int, AuditLog> $rows */
        $rows = app(AuditRecords::class)->latest($access, self::LIMIT);

        // The three filter option lists (audit-page.tsx:28-43): distinct
        // values off the loaded rows, each sorted with localeCompare.
        $actions = $rows->map(static fn (AuditLog $r): string => $r->action)
            ->unique()->values()->all();
        usort($actions, static fn (string $a, string $b): int => strcasecmp($a, $b));

        $entities = $rows->map(static fn (AuditLog $r): ?string => $r->entity)
            ->filter(static fn (?string $e): bool => $e !== null && $e !== '')
            ->unique()->values()->all();
        usort($entities, static fn (string $a, string $b): int => strcasecmp($a, $b));

        $actors = $rows->map(static fn (AuditLog $r): string => self::actorLabel($r))
            ->unique()->values()->all();
        usort($actors, static fn (string $a, string $b): int => strcasecmp($a, $b));

        // The filter pipeline and sort (audit-page.tsx:45-71): one copy,
        // shared with the CSV export.
        $sorted = AuditRows::arrange($rows, $this->search, $this->action, $this->entity, $this->actor, $this->sortKey, $this->sortDir);

        return view('livewire.audit.index', [
            'rows' => $this->paginateClamped($sorted),
            'actions' => $actions,
            'entities' => $entities,
            'actors' => $actors,
        ]);
    }

    /** `r.actor_name || "System"` (audit-page.tsx:41, 50, 178). */
    public static function actorLabel(AuditLog $row): string
    {
        $name = $row->actor_name ?? '';

        return $name !== '' ? $name : 'System';
    }

    /**
     * Port of `compareText` (useSort.ts:33-40): blanks always sort last in an
     * ascending pass. `strcasecmp` stands in for `localeCompare` — for the
     * uppercase event literals, table names and English actor names this
     * screen sorts, the ordering is the same. Shared through
     * App\Support\TextSort; this wrapper keeps the call sites unchanged.
     */
    public static function compareText(?string $a, ?string $b): int
    {
        // One comparator for every sorted list — the eight module copies drifted
        // (a case-only tiebreak in My Tasks, ICU in Employee Records). This stays a
        // wrapper so no call site or Blade import changes; TextSortTest pins it.
        return TextSort::compare($a, $b);
    }
}
