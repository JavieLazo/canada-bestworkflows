{{--
    "My punches" — LiveFeed's compact form (copems/src/components/LiveFeed.tsx),
    rendered from App\Livewire\Portal\MyPunches over the caller's OWN rows only.

    Compact drops the Employee and Source columns and pins column widths, so
    the feed keeps to the portal card beside the punch station. wire:poll here
    shares the kiosk feed's cadence (COPEMS_POLL_FEED, default 15s), and the
    same pollForChanges target (WithChangeStamp): a tick reads one COUNT/MAX
    over THIS employee's punches and redraws only when one of theirs lands.
    `.visible` pauses it while the card is scrolled out of view.
--}}
@use('App\Domain\Attendance\Minutes')
@use('App\Modules\Timekeeping\Domain\Roster\LocationKind')

<section wire:poll.visible.{{ $pollSeconds }}s="pollForChanges" class="hris-card flex flex-col overflow-hidden">
    <header class="flex shrink-0 flex-wrap items-start justify-between gap-3 border-b border-border px-5 py-4">
        <div>
            <h2 class="text-lg font-bold">{{ $title }}</h2>
            <p class="text-sm text-muted-foreground">Every punch as it lands, newest first.</p>
        </div>
    </header>

    <div class="hris-table-wrap hris-table-scroll min-h-0 flex-1">
        <table class="hris-table table-fixed">
            <thead>
                <tr>
                    <th class="w-[34%]">Timestamp</th>
                    <th class="hidden w-[27%] sm:table-cell">Site</th>
                    <th class="w-[15%]">Type</th>
                    <th class="w-[24%]">Distance</th>
                </tr>
            </thead>
            <tbody>
                @if ($total === 0)
                    <tr wire:key="my-punches-empty">
                        <x-kit.empty-state :colspan="4" title="No punches recorded yet." />
                    </tr>
                @endif
                @foreach ($rows as $l)
                    <tr wire:key="my-punch-{{ $l->id }}">
                        {{-- When the punch landed, in local time: the day, the time under it. --}}
                        @php($punchedAt = $l->logged_at->setTimezone(\App\Support\LocalClock::zone()))
                        <td class="num align-top break-words">
                            <p>{{ $punchedAt->format('M d, Y') }}</p>
                            <p class="text-xs text-muted-foreground">{{ $punchedAt->format('g:i A') }}</p>
                        </td>
                        {{-- "Work from home", the site, or an em dash — LocationKind::label,
                             the live feed's spelling. The kind is stored on the punch. --}}
                        <td class="hidden break-words sm:table-cell">{{ LocationKind::label($l->location_kind, $l->site?->name) }}</td>
                        <td class="whitespace-nowrap">
                            <span class="{{ $l->log_type === 'TIME_IN' ? 'pill pill-success' : 'pill pill-muted' }}">
                                {{ str_replace('_', ' ', $l->log_type) }}
                            </span>
                        </td>
                        <td class="align-top break-words">
                            {{--
                                A punch outside the fence is refused at the
                                station, so every stored one is in bounds. What
                                is still worth showing is how far from the pin
                                it was — blank where there was nothing to
                                measure against: a geofence-exempt site, a Work
                                from home day (its own kind, named in the Site
                                column), or an ONSITE row with no site at all.
                            --}}
                            <span class="num text-muted-foreground">
                                {{ $l->distance_meters === null ? '—' : ((int) Minutes::round((float) $l->distance_meters)).' m' }}
                            </span>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <x-kit.pagination :paginator="$rows" noun="punches" class="shrink-0" />
</section>
