{{--
    Audit Trail — the port of audit-page.tsx: one read-only table with a search
    box, three filter selects, four sortable headers and the shared pagination
    footer. There is no dialog, no row action and no write path on this screen.

    Column order, classes and copy transcribed from the source:
      Timestamp (num, nowrap) · Actor (font-medium, "System" fallback) ·
      Action (pill pill-info) · Entity (muted, "—") · Details (muted, "—")
    Empty row: "No entry matches those filters." (audit-page.tsx:168-173).
--}}
@php
    use App\Livewire\Audit\Index as AuditIndex;
    use App\Support\ManilaClock;

    // fmtDateTime (hris.ts:199-207) — en-PH "Aug 31, 2026, 2:05 PM";
    // datetimes are stored UTC and display in Manila.
    $fmtDateTime = static fn ($at): string => $at->setTimezone(ManilaClock::TZ)->format('M d, Y, g:i A');
@endphp

<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">Audit Trail</h1>
            <p class="text-sm text-muted-foreground">
                Append-only. Entries can never be edited or deleted from the app.
            </p>
        </div>
    </div>

    <section class="hris-card overflow-hidden">
        <header class="flex flex-wrap items-center gap-3 border-b border-border px-5 py-4">
            <flux:input
                aria-label="Search entries"
                placeholder="Search action, actor or details"
                class="w-full min-w-[12rem] max-w-xs flex-1"
                wire:model.live="search"
            />

            <x-kit.filter-select label="Filter by action" class="w-[13rem]" wire:model.live="action">
                <flux:select.option value="ALL">All actions</flux:select.option>
                @foreach ($actions as $a)
                    <flux:select.option value="{{ $a }}">{{ $a }}</flux:select.option>
                @endforeach
            </x-kit.filter-select>

            <x-kit.filter-select label="Filter by entity" class="w-[13rem]" wire:model.live="entity">
                <flux:select.option value="ALL">All entities</flux:select.option>
                @foreach ($entities as $e)
                    <flux:select.option value="{{ $e }}">{{ $e }}</flux:select.option>
                @endforeach
            </x-kit.filter-select>

            <x-kit.filter-select label="Filter by actor" class="w-[13rem]" wire:model.live="actor">
                <flux:select.option value="ALL">All actors</flux:select.option>
                @foreach ($actors as $a)
                    <flux:select.option value="{{ $a }}">{{ $a }}</flux:select.option>
                @endforeach
            </x-kit.filter-select>
        </header>

        <div class="hris-table-wrap hris-table-scroll">
            <table class="hris-table">
                <thead>
                    <tr>
                        <x-kit.sort-header field="timestamp" label="Timestamp" />
                        <x-kit.sort-header field="actor" label="Actor" />
                        <x-kit.sort-header field="action" label="Action" />
                        <x-kit.sort-header field="entity" label="Entity" />
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody>
                    @if ($rows->total() === 0)
                        <tr wire:key="empty">
                            <x-kit.empty-state :colspan="5" title="No entry matches those filters." />
                        </tr>
                    @endif
                    @foreach ($rows as $r)
                        <tr wire:key="log-{{ $r->id }}">
                            <td class="num whitespace-nowrap">{{ $fmtDateTime($r->created_at) }}</td>
                            <td class="font-medium">{{ AuditIndex::actorLabel($r) }}</td>
                            <td class="whitespace-nowrap">
                                <span class="pill pill-info">{{ $r->action }}</span>
                            </td>
                            <td class="text-muted-foreground">{{ $r->entity ?? '—' }}</td>
                            <td class="text-muted-foreground">{{ $r->details ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <x-kit.pagination :paginator="$rows" noun="entries" />
    </section>
</div>
