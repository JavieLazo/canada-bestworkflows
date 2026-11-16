{{--
    The kiosk — the port of copems/src/components/PunchStation.tsx. See
    App\Livewire\Edtr\PunchStation for every check behind the buttons.

    Container queries (@container / @2xl: / @xs:) carry over as-is — Tailwind 4
    supports them in core, so the card reflows by its own width exactly as the
    reference did.
--}}
@use('App\Livewire\Edtr\Support\Hris')

{{-- A column, so when a page stretches the card (My Portal fills the screen
     with it and My Punches) the punch buttons sit at its foot and the space
     opens above them; at its own height nothing moves. --}}
<section class="hris-card @container flex flex-col p-6">
    <header class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="text-lg font-bold">Punch Station</h2>
            <p class="text-sm text-muted-foreground">
                Geofence-validated attendance. Your location is read as you punch; Time In is blocked outside the site geofence.
            </p>
        </div>
        @if ($showClock)
            <livewire:shell.live-clock class="text-left @xl:text-right" wire:key="punch-clock" />
        @endif
    </header>

    <div class="mt-6 grid gap-4 @2xl:grid-cols-2">
        <div class="space-y-2">
            <div class="flex min-h-5 items-center">
                <flux:label id="punch-employee-label" for="punch-employee">Employee</flux:label>
            </div>
            @if ($lockedEmployeeId !== null)
                <flux:input id="punch-employee" readonly class:input="num" value="{{ $lockedName }}" />
            @else
                <livewire:kit.employee-picker
                    name="punch-employee"
                    :value="$employeeId"
                    trigger-id="punch-employee"
                    labelled-by="punch-employee-label"
                    wire:key="punch-employee-picker-{{ $employeeId }}"
                />
            @endif
        </div>

        <div class="space-y-2">
            <div class="flex min-h-5 items-center">
                <flux:label for="punch-site">{{ $wfhToday ? 'Location Today' : 'Work Site' }}</flux:label>
            </div>
            {{-- Today's place, resolved by the roster (RosterLocations::forStation):
                 a Work from home day has no site to pick or check; an onsite day
                 pins that weekday's site, or the Assigned Worksite when no row
                 exists — the reference's one-site behaviour. The kiosk select is
                 only offered while no employee's day has resolved. --}}
            @if ($wfhToday)
                <flux:input id="punch-site" readonly value="Work from home" />
            @elseif ($selfService || $todayLocation !== null)
                <flux:input
                    id="punch-site"
                    readonly
                    value="{{ $todaySite !== null ? $todaySite->name : ($todayLocation?->site_id !== null ? 'Assigned worksite unavailable' : 'No worksite assigned yet') }}"
                />
            @elseif ($sites->isEmpty())
                {{-- Nothing to punch against: saying so beats a dropdown that
                     opens onto nothing. --}}
                <flux:input id="punch-site" readonly value="No site set up yet" />
            @else
                <x-kit.select id="punch-site" wire:change="selectSite($event.target.value)" aria-label="Work site">
                    @foreach ($sites as $s)
                        <flux:select.option value="{{ $s->id }}" :selected="$siteId === (string) $s->id">
                            {{ $s->name }}{{ $s->geofence_exempt ? ' (exempt)' : ' · '.$s->radius_meters.' m' }}{{ $s->is_active ? '' : ' · inactive' }}
                        </flux:select.option>
                    @endforeach
                </x-kit.select>
            @endif
        </div>

        @if (! $selfService)
            <div class="space-y-2">
                <flux:label>Punch Source</flux:label>
                <x-kit.select wire:model.live="source" aria-label="Punch source">
                    @foreach (Hris::SOURCES as $s)
                        <flux:select.option value="{{ $s['value'] }}">{{ $s['label'] }}</flux:select.option>
                    @endforeach
                </x-kit.select>
            </div>

            <div class="space-y-2">
                <flux:label>Coordinates</flux:label>
                <div
                    class="flex gap-2"
                    x-data="{
                        locating: false,
                        locate() {
                            if (! navigator.geolocation) {
                                window.Flux?.toast({ text: 'This browser can\'t share a location. Enter coordinates manually.', variant: 'danger' });
                                return;
                            }
                            this.locating = true;
                            navigator.geolocation.getCurrentPosition(
                                (pos) => {
                                    $wire.set('lat', pos.coords.latitude.toFixed(6));
                                    $wire.set('lng', pos.coords.longitude.toFixed(6));
                                    this.locating = false;
                                    window.Flux?.toast({ text: 'Location captured.', variant: 'success' });
                                },
                                (err) => {
                                    this.locating = false;
                                    window.Flux?.toast({ text: 'Location unavailable: ' + err.message + '. Enter coordinates manually.', variant: 'danger' });
                                },
                                { enableHighAccuracy: true, timeout: 10000 },
                            );
                        },
                    }"
                >
                    <flux:input
                        class:input="num"
                        placeholder="latitude"
                        aria-label="Latitude"
                        wire:model.live.debounce.400ms="lat"
                    />
                    <flux:input
                        class:input="num"
                        placeholder="longitude"
                        aria-label="Longitude"
                        wire:model.live.debounce.400ms="lng"
                    />
                    <flux:button
                        type="button"
                        variant="outline"
                        square
                        aria-label="Use my location"
                        x-on:click="locate()"
                    >
                        <flux:icon.loading x-show="locating" class="size-4" />
                        <flux:icon.viewfinder-circle x-show="! locating" class="size-4" />
                    </flux:button>
                </div>
            </div>
        @endif
    </div>

    {{-- The kiosk's live geofence verdict. Self-service shows no location
         card: the Work site field above already names today's place, and
         the punch itself says when it is refused. --}}
    @if (! $selfService)
        <div
            class="mt-5 flex items-center gap-3 rounded-md border px-4 py-3 text-sm {{ $verdict === null
                ? 'border-border bg-muted text-muted-foreground'
                : ($verdict['inBounds']
                    ? 'border-success/30 bg-success-soft text-success'
                    : 'border-danger/30 bg-danger-soft text-danger') }}"
        >
            <flux:icon.map-pin class="size-4 shrink-0" />
            @if ($verdict === null)
                <span>Capture or enter coordinates to preview the verdict — left empty, the browser's fix is used when you punch.</span>
            @elseif ($verdict['wfh'] ?? false)
                <span class="font-medium">Work from home today — no geofence; the punch is accepted from anywhere.</span>
            @elseif ($verdict['exempt'])
                <span class="font-medium">Geofence-exempt site — this punch always passes.</span>
            @else
                <span class="font-medium">
                    {{ $verdict['inBounds'] ? 'In bounds' : 'Out of bounds' }} ·
                    <span class="num">{{ (int) \App\Domain\Attendance\Minutes::round($verdict['distance']) }} m</span> from {{ $site->name }}
                    (radius <span class="num">{{ $site->radius_meters }} m</span>){{ ! $verdict['inBounds'] ? ' · Time In blocked' : '' }}
                </span>
            @endif
        </div>
    @endif

    {{-- No shift on the date a Time In would land on: RecordPunch refuses the
         Time In, so its button is off, and Time Out with it unless a Time In
         is open for it to close (noShiftCanClose). No card says so; the
         disabled buttons carry the reason as their tooltip. --}}
    @php($noShiftReason = $noShift ? 'No shift on '.\App\Support\HrisFormat::date($todayLocation->date).'. A shift has to be assigned in Shift Roster first.' : null)

    {{-- Time In over Time Out in self-service (My Portal); side by side at
         the kiosk once the card is wide enough. --}}
    <div @class(['mt-auto grid gap-3 pt-5', '@xs:grid-cols-2' => ! $selfService])>
        {{--
            Every punch first asks the browser for a fix (PunchStation.tsx:117-141
            does this in self-service only) and sends lat/lng/accuracy to punch();
            the server decides what counts — typed kiosk boxes win when filled,
            an exempt site passes with no fix, a fenced site refuses one it
            cannot verify. A refused or timed-out fix still punches, with a
            client toast saying why, unless both kiosk boxes are typed and the
            fix would not have been used anyway. The settled/timer guard is
            needed: Safari never calls either callback when the prompt is
            dismissed. Eight seconds bounds the wait — a cold mobile GPS
            routinely needs more than the five PunchStation.tsx:127-138 allows.
        --}}
        <div
            class="contents"
            x-data="{
                busy: false,
                pending: null,
                phase: 'idle',
                typed() {
                    return {{ $selfService ? 'false' : 'true' }} && String($wire.lat ?? '').trim() !== '' && String($wire.lng ?? '').trim() !== '';
                },
                label(type, text) {
                    return this.pending === type && this.phase === 'locating' ? 'Getting location…' : text;
                },
                capture() {
                    return new Promise((resolve) => {
                        if (! navigator.geolocation) return resolve({ fix: null, reason: 'unsupported' });
                        let settled = false;
                        const finish = (v) => { if (! settled) { settled = true; resolve(v); } };
                        const timer = setTimeout(() => finish({ fix: null, reason: 'timeout' }), 8000);
                        navigator.geolocation.getCurrentPosition(
                            (pos) => {
                                clearTimeout(timer);
                                const c = pos.coords;
                                finish({ fix: { lat: c.latitude, lng: c.longitude, accuracy: Number.isFinite(c.accuracy) ? c.accuracy : null }, reason: null });
                            },
                            (err) => {
                                clearTimeout(timer);
                                finish({ fix: null, reason: err.code === err.PERMISSION_DENIED ? 'denied' : (err.code === err.TIMEOUT ? 'timeout' : 'unavailable') });
                            },
                            { enableHighAccuracy: true, timeout: 8000, maximumAge: 0 },
                        );
                    });
                },
                refusal(reason) {
                    return {
                        denied: 'Location access was refused — punching without a location.',
                        timeout: 'Location took too long — punching without a location.',
                        unsupported: 'This browser can\'t share a location — punching without one.',
                    }[reason] ?? 'Location unavailable — punching without a location.';
                },
                async go(type) {
                    if (this.busy) return;
                    this.busy = true;
                    this.pending = type;
                    this.phase = 'locating';
                    try {
                        const { fix, reason } = await this.capture();
                        if (fix === null && ! this.typed()) {
                            window.Flux?.toast({ text: this.refusal(reason), variant: 'warning' });
                        }
                        this.phase = 'sending';
                        await $wire.punch(type, fix?.lat ?? null, fix?.lng ?? null, fix?.accuracy ?? null);
                    } finally {
                        this.busy = false;
                        this.pending = null;
                        this.phase = 'idle';
                    }
                },
            }"
        >
            {{-- The server-rendered disabled state covers the frame before Alpine
                 boots on a slow connection; the x-bind takes over from there. --}}
            <flux:button
                variant="primary"
                :disabled="$punchDisabled || $noShift"
                :title="$noShiftReason"
                x-bind:disabled="busy || {{ $punchDisabled || $noShift ? 'true' : 'false' }}"
                x-on:click="go('TIME_IN')"
            >
                <flux:icon.loading x-show="busy && pending === 'TIME_IN'" x-cloak class="mr-2 size-4" />
                <flux:icon.arrow-left-end-on-rectangle x-show="! (busy && pending === 'TIME_IN')" class="mr-2 size-4" />
                <span x-text="label('TIME_IN', 'Time In')">Time In</span>
            </flux:button>
            <flux:button
                variant="filled"
                :disabled="$punchDisabled || ($noShift && ! $noShiftCanClose)"
                :title="$noShiftCanClose ? 'Closes the Time In still open.' : $noShiftReason"
                x-bind:disabled="busy || {{ $punchDisabled || ($noShift && ! $noShiftCanClose) ? 'true' : 'false' }}"
                x-on:click="go('TIME_OUT')"
            >
                <flux:icon.loading x-show="busy && pending === 'TIME_OUT'" x-cloak class="mr-2 size-4" />
                <flux:icon.arrow-right-start-on-rectangle x-show="! (busy && pending === 'TIME_OUT')" class="mr-2 size-4" />
                <span x-text="label('TIME_OUT', 'Time Out')">Time Out</span>
            </flux:button>
        </div>
    </div>
</section>
