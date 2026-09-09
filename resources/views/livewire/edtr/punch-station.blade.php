{{--
    The kiosk — the port of copems/src/components/PunchStation.tsx. See
    App\Livewire\Edtr\PunchStation for every check behind the buttons.

    Container queries (@container / @2xl: / @xs:) carry over as-is — Tailwind 4
    supports them in core, so the card reflows by its own width exactly as the
    reference did.
--}}
@use('App\Livewire\Edtr\Support\Hris')

<section class="hris-card @container p-6">
    <header class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="text-lg font-bold">Punch Station</h2>
            <p class="text-sm text-muted-foreground">
                Geofence-validated attendance. Time In is blocked outside the site geofence.
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
                <flux:label for="punch-site">Work site</flux:label>
            </div>
            @if ($selfService || $assignedSiteId !== null)
                <flux:input
                    id="punch-site"
                    readonly
                    value="{{ $assignedSite !== null ? $assignedSite->name : ($assignedSiteId !== null ? 'Assigned worksite unavailable' : 'No worksite assigned yet') }}"
                />
            @elseif ($sites->isEmpty())
                {{-- Nothing to punch against: saying so beats a dropdown that
                     opens onto nothing. --}}
                <flux:input id="punch-site" readonly value="No site set up yet" />
            @else
                <flux:select id="punch-site" wire:change="selectSite($event.target.value)" aria-label="Work site">
                    @foreach ($sites as $s)
                        <flux:select.option value="{{ $s->id }}" :selected="$siteId === (string) $s->id">
                            {{ $s->name }}{{ $s->geofence_exempt ? ' (exempt)' : ' · '.$s->radius_meters.' m' }}
                        </flux:select.option>
                    @endforeach
                </flux:select>
            @endif
        </div>

        @if (! $selfService)
            <div class="space-y-2">
                <flux:label>Punch source</flux:label>
                <flux:select wire:model.live="source" aria-label="Punch source">
                    @foreach (Hris::SOURCES as $s)
                        <flux:select.option value="{{ $s['value'] }}">{{ $s['label'] }}</flux:select.option>
                    @endforeach
                </flux:select>
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

    @if ($selfService)
        <div class="mt-5 flex items-center gap-3 rounded-md border border-border bg-muted px-4 py-3 text-sm text-muted-foreground">
            <flux:icon.map-pin class="size-4 shrink-0" />
            <span>
                @if ($site !== null && $site->geofence_exempt)
                    {{-- Saying a check happens where none does is worse than
                         saying nothing — this is field work, and the punch
                         always passes. --}}
                    {{ $site->name }} is field work — your punches here always pass, wherever you are.
                @else
                    Your location is checked against {{ $site->name ?? 'your work site' }} as you punch. Time In is blocked when outside.
                @endif
            </span>
        </div>
    @else
        <div
            class="mt-5 flex items-center gap-3 rounded-md border px-4 py-3 text-sm {{ $verdict === null
                ? 'border-border bg-muted text-muted-foreground'
                : ($verdict['inBounds']
                    ? 'border-success/30 bg-success-soft text-success'
                    : 'border-danger/30 bg-danger-soft text-danger') }}"
        >
            <flux:icon.map-pin class="size-4 shrink-0" />
            @if ($verdict === null)
                <span>Capture or enter coordinates to preview the geofence verdict.</span>
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

    <div class="mt-5 grid gap-3 @xs:grid-cols-2">
        @if ($selfService)
            {{-- The portal asks the browser for a fix as the punch happens; a
                 refused or absent fix punches with no reading, exactly as
                 `currentCoords()` resolved null (PunchStation.tsx:117-141). --}}
            <div
                class="contents"
                x-data="{
                    busy: false,
                    coords() {
                        return new Promise((resolve) => {
                            if (! navigator.geolocation) return resolve(null);
                            let settled = false;
                            const finish = (v) => { if (! settled) { settled = true; resolve(v); } };
                            const timer = setTimeout(() => finish(null), 5000);
                            navigator.geolocation.getCurrentPosition(
                                (pos) => { clearTimeout(timer); finish({ lat: pos.coords.latitude, lng: pos.coords.longitude }); },
                                () => { clearTimeout(timer); finish(null); },
                                { enableHighAccuracy: true, timeout: 5000 },
                            );
                        });
                    },
                    async go(type) {
                        this.busy = true;
                        const fix = await this.coords();
                        await $wire.punch(type, fix ? fix.lat : null, fix ? fix.lng : null);
                        this.busy = false;
                    },
                }"
            >
                <flux:button variant="primary" x-bind:disabled="busy || {{ $punchDisabled ? 'true' : 'false' }}" x-on:click="go('TIME_IN')">
                    <flux:icon.loading x-show="busy" class="mr-2 size-4" />
                    <flux:icon.arrow-left-end-on-rectangle x-show="! busy" class="mr-2 size-4" />
                    Time In
                </flux:button>
                <flux:button variant="filled" x-bind:disabled="busy || {{ $punchDisabled ? 'true' : 'false' }}" x-on:click="go('TIME_OUT')">
                    <flux:icon.loading x-show="busy" class="mr-2 size-4" />
                    <flux:icon.arrow-right-start-on-rectangle x-show="! busy" class="mr-2 size-4" />
                    Time Out
                </flux:button>
            </div>
        @else
            <flux:button
                variant="primary"
                :disabled="$punchDisabled"
                wire:click="punch('TIME_IN')"
                wire:loading.attr="disabled"
                wire:target="punch"
            >
                <span wire:loading wire:target="punch" class="mr-2 inline-flex"><flux:icon.loading class="size-4" /></span>
                <span wire:loading.remove wire:target="punch" class="mr-2 inline-flex"><flux:icon.arrow-left-end-on-rectangle class="size-4" /></span>
                Time In
            </flux:button>
            <flux:button
                variant="filled"
                :disabled="$punchDisabled"
                wire:click="punch('TIME_OUT')"
                wire:loading.attr="disabled"
                wire:target="punch"
            >
                <span wire:loading wire:target="punch" class="mr-2 inline-flex"><flux:icon.loading class="size-4" /></span>
                <span wire:loading.remove wire:target="punch" class="mr-2 inline-flex"><flux:icon.arrow-right-start-on-rectangle class="size-4" /></span>
                Time Out
            </flux:button>
        @endif
    </div>
</section>
