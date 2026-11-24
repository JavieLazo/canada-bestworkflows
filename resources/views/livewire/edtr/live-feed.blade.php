{{--
    Punches as they land — the port of copems/src/components/LiveFeed.tsx.
    See App\Livewire\Edtr\LiveFeed. The punch feeds share COPEMS_POLL_FEED's
    cadence, with a slower polling backstop when realtime is enabled.

    The tick calls pollForChanges (WithChangeStamp), not $refresh: it reads one
    scoped COUNT/MAX and only redraws these 300 rows when a punch actually
    landed. `.visible` pauses it outright while the card is off-screen — on a
    narrow viewport this feed sits below the punch station, and a table nobody
    can see is not worth a round trip.

    h-0 + min-h-full: an explicit height stops this card from driving the grid
    row, so the row is sized by the punch card and min-height then fills it —
    the two cards end up level.
--}}
@use('App\Livewire\Edtr\Support\Hris')
@use('App\Domain\Attendance\Minutes')
@use('App\Modules\Timekeeping\Domain\Roster\LocationKind')
@use('App\Platform\Files\PortraitUrl')

<section
    wire:poll.visible.{{ $pollSeconds }}s="pollForChanges"
    class="hris-card flex flex-col overflow-hidden {{ $compact ? '' : 'xl:h-0 xl:min-h-full' }}"
>
    <header class="flex shrink-0 flex-wrap items-start justify-between gap-3 border-b border-border px-5 py-4">
        <div>
            <h2 class="text-lg font-bold">{{ $title }}</h2>
            <p class="text-sm text-muted-foreground">Every punch as it lands, newest first.</p>
        </div>
        @unless ($compact)
            <x-kit.export-button :href="route('edtr.feed.export')" />
        @endunless
    </header>

    <div class="hris-table-wrap min-h-0 flex-1 {{ $compact ? 'overflow-y-visible' : 'overflow-y-auto' }}">
        <table class="hris-table {{ $compact ? 'table-fixed' : '' }}">
            <thead>
                <tr>
                    @unless ($compact)
                        <th>Employee</th>
                    @endunless
                    <th @if ($compact) class="w-[34%]" @endif>Timestamp</th>
                    <th @if ($compact) class="hidden w-[27%] sm:table-cell" @endif>Site</th>
                    <th @if ($compact) class="w-[15%]" @endif>Type</th>
                    @unless ($compact)
                        <th>Source</th>
                    @endunless
                    <th @if ($compact) class="w-[24%]" @endif>Distance</th>
                    @unless ($compact)
                        <th>Captured Location</th>
                    @endunless
                </tr>
            </thead>
            <tbody>
                @if ($total === 0)
                    <tr wire:key="feed-empty">
                        <x-kit.empty-state :colspan="$compact ? 4 : 7" title="No punches recorded yet." />
                    </tr>
                @endif
                @foreach ($rows as $l)
                    @php($employee = $l->employee)
                    @php($name = Hris::employeeName($employee))
                    <tr wire:key="feed-{{ $l->id }}">
                        @unless ($compact)
                            <td class="whitespace-nowrap">
                                <div class="flex items-center gap-3">
                                    <span class="relative flex h-9 w-9 shrink-0 overflow-hidden rounded-full bg-muted">
                                        @if ($employee?->photo_path)
                                            <img
                                                src="{{ PortraitUrl::for($employee->photo_path) }}"
                                                alt="{{ $name }}"
                                                class="aspect-square h-full w-full object-cover"
                                            />
                                        @else
                                            <span class="num flex h-full w-full items-center justify-center text-xs font-semibold">
                                                {{ $employee ? \App\Support\EmployeeName::initials($employee) : '—' }}
                                            </span>
                                        @endif
                                    </span>
                                    <div class="min-w-0">
                                        <p class="truncate font-medium">
                                            {{-- A name opens that employee's Daily Time Record. EdtrPage
                                                 takes the id only where its picker would, so a self-only
                                                 viewer still lands on their own record. --}}
                                            @if ($employee !== null && $linksToDtr)
                                                <a href="{{ route('edtr.index', ['tab' => 'dtr', 'employee' => $employee->id]) }}" class="hover:underline" wire:navigate>{{ $name }}</a>
                                            @else
                                                {{ $name }}
                                            @endif
                                        </p>
                                        <p class="truncate text-xs text-muted-foreground">{{ $employee?->position ?? '—' }}</p>
                                    </div>
                                </div>
                            </td>
                        @endunless
                        {{-- When the punch landed, in local time: the day, the time under it. --}}
                        @php($punchedAt = $l->logged_at->setTimezone(\App\Support\LocalClock::zone()))
                        <td class="{{ $compact ? 'num align-top break-words' : 'num whitespace-nowrap' }}">
                            <p>{{ $punchedAt->format('M d, Y') }}</p>
                            <p class="text-xs text-muted-foreground">{{ $punchedAt->format('g:i A') }}</p>
                        </td>
                        <td class="{{ $compact ? 'hidden break-words sm:table-cell' : 'max-w-[16rem] break-words' }}">
                            {{-- Recorded sites use LocationKind::label: "Work from home",
                                 the site, or an em dash. The kind is STORED on the punch,
                                 so a later roster edit never relabels history. --}}
                            {{ LocationKind::label($l->location_kind, $l->site?->name) }}
                        </td>
                        <td class="whitespace-nowrap">
                            <span class="{{ $l->log_type === 'TIME_IN' ? 'pill pill-success' : 'pill pill-muted' }}">
                                {{ str_replace('_', ' ', $l->log_type) }}
                            </span>
                        </td>
                        @unless ($compact)
                            <td class="whitespace-nowrap text-muted-foreground">{{ str_replace('_', ' ', $l->source) }}</td>
                        @endunless
                        <td class="{{ $compact ? 'align-top break-words' : 'whitespace-nowrap' }}">
                            {{--
                                A punch outside the fence is refused at the
                                station, so every stored one is in bounds and a
                                verdict here would always read "Valid". What is
                                still worth showing is how far from the pin it
                                was — blank where there was nothing to measure
                                against: a geofence-exempt site, a Work from
                                home day (its own kind, labelled in the Site
                                column), or an ONSITE row with no site at all
                                (a correction for an employee with no worksite).
                            --}}
                            <span class="num text-muted-foreground">
                                {{ $l->distance_meters === null ? '—' : ((int) Minutes::round((float) $l->distance_meters)).' m' }}
                            </span>
                        </td>
                        @unless ($compact)
                            <td class="max-w-[16rem] break-words">
                                @if ($l->hand_entered)
                                    {{-- Match the DTR dialog: a kiosk fix on a manual punch
                                         is not the employee's captured location. --}}
                                    <span>Manually Entered</span>
                                @elseif ($l->latitude !== null && $l->longitude !== null)
                                    <a
                                        href="https://www.google.com/maps?q={{ $l->latitude }},{{ $l->longitude }}"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline"
                                        aria-label="Map of {{ $name }}'s {{ Hris::fmtDateTime(\App\Domain\Attendance\AttendanceLog::isoFromUtc($l->logged_at)) }} punch, in Google Maps"
                                    >
                                        Map
                                        <flux:icon.arrow-top-right-on-square class="size-3" />
                                    </a>
                                @else
                                    <span class="text-muted-foreground">—</span>
                                @endif
                            </td>
                        @endunless
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <x-kit.pagination :paginator="$rows" noun="punches" class="shrink-0" />
</section>
