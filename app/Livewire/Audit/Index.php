<?php

declare(strict_types=1);

namespace App\Livewire\Audit;

use App\Domain\Enums\ModuleCode;
use App\Livewire\Concerns\WithSorting;
use App\Livewire\Concerns\WithTableFilters;
use App\Models\AuditLog;
use App\Support\Access;
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
        $rows = $access->mayReadAllData(ModuleCode::AUDIT)
            ? AuditLog::query()->orderByDesc('created_at')->limit(self::LIMIT)->get()
            : new Collection;

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

        // The filter pipeline (audit-page.tsx:45-55).
        $needle = mb_strtolower(trim($this->search));

        $visible = $rows->filter(function (AuditLog $r) use ($needle): bool {
            if ($this->action !== 'ALL' && $r->action !== $this->action) {
                return false;
            }

            if ($this->entity !== 'ALL' && $r->entity !== $this->entity) {
                return false;
            }

            if ($this->actor !== 'ALL' && self::actorLabel($r) !== $this->actor) {
                return false;
            }

            if ($needle === '') {
                return true;
            }

            // `[action, entity, actor_name, details].some(...)` — the search
            // matches the RAW actor_name, not the "System" fallback
            // (audit-page.tsx:52-54).
            foreach ([$r->action, $r->entity, $r->actor_name, $r->details] as $v) {
                if (str_contains(mb_strtolower($v ?? ''), $needle)) {
                    return true;
                }
            }

            return false;
        })->values();

        // The sort (audit-page.tsx:57-70) — comparator then a whole-list
        // reverse() for descending, exactly as the source did it. Timestamps
        // compare as strings the way ISO strings did through compareText.
        $sorted = $visible->sort(fn (AuditLog $a, AuditLog $b): int => match ($this->sortKey) {
            'actor' => self::compareText($a->actor_name, $b->actor_name),
            'action' => self::compareText($a->action, $b->action),
            'entity' => self::compareText($a->entity, $b->entity),
            default => self::compareText(
                $a->created_at->format('Y-m-d H:i:s.v'),
                $b->created_at->format('Y-m-d H:i:s.v'),
            ),
        })->values();

        if ($this->sortDir === 'desc') {
            $sorted = $sorted->reverse()->values();
        }

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
     * screen sorts, the ordering is the same.
     */
    private static function compareText(?string $a, ?string $b): int
    {
        $left = trim($a ?? '');
        $right = trim($b ?? '');

        if ($left === '' && $right === '') {
            return 0;
        }

        if ($left === '') {
            return 1;
        }

        if ($right === '') {
            return -1;
        }

        return strcasecmp($left, $right);
    }
}
