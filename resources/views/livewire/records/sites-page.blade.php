{{--
    Site Settings — the port of src/app/(protected)/records/sites/sites-page.tsx.
    Same search and default sort (name, ascending), and the same dialog
    fields.
    DELIBERATE DIVERGENCE: the reference's "geofence-exempt" flag is asked
    the other way round, in plain words — a "Check Location When Punching"
    switch per site (on = a punch must come from inside the site's radius;
    off = anyone assigned to the site punches from anywhere), with the
    coordinates and radius drawn only while it is on. The column, the filter
    and the copy say "location check"; the stored flag and its meaning are
    the reference's.
    DELIBERATE DIVERGENCE: a Status column and filter, and Make inactive /
    Make active in the row menu — a site history names is retired, not
    deleted.
    DELIBERATE DIVERGENCE: no Punches column. The count of recent punches is
    read only to say why a site in use cannot be deleted.
    SITE SETTINGS HAS NO CSV: no Export CSV and no Import CSV.
--}}
<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-bold tracking-tight">Site Settings</h1>
        <p class="text-sm text-muted-foreground">
            Each site decides whether people must be there to punch. Where the location is checked,
            a punch has to come from inside the site&rsquo;s radius; where it is not, anyone assigned to
            the site can punch from anywhere. A change only affects punches made from now on.
        </p>
    </div>

    <section class="hris-card overflow-hidden">
        <header class="flex flex-wrap items-center gap-3 border-b border-border px-5 py-4">
            <flux:input
                aria-label="Search sites"
                placeholder="Search site name"
                class="w-full min-w-[12rem] max-w-xs flex-1"
                wire:model.live.debounce.250ms="search"
            />

            <x-kit.filter-select label="Filter by Location Check" class="w-[13rem]" wire:model.live="geofence">
                <flux:select.option value="ALL">All sites</flux:select.option>
                <flux:select.option value="ENFORCED">Location checked</flux:select.option>
                <flux:select.option value="EXEMPT">Location not checked</flux:select.option>
            </x-kit.filter-select>

            <x-kit.filter-select label="Filter by Status" class="w-[10rem]" wire:model.live="status">
                <flux:select.option value="ALL">All statuses</flux:select.option>
                <flux:select.option value="ACTIVE">Active</flux:select.option>
                <flux:select.option value="INACTIVE">Inactive</flux:select.option>
            </x-kit.filter-select>

            <div class="ml-auto flex flex-wrap items-center gap-2">
                <flux:button variant="primary" icon="plus" wire:click="openAdd">
                    Add Site
                </flux:button>
            </div>
        </header>

        <div class="hris-table-wrap hris-table-scroll">
            <table class="hris-table">
                <thead>
                    <tr>
                        <x-kit.sort-header field="name" label="Site" />
                        <th>Coordinates</th>
                        <x-kit.sort-header field="radius" label="Radius" />
                        <th>Location Check</th>
                        <th>Status</th>
                        {{-- `!text-right`: hris-table's own header rule is left-aligned and outranks a plain `text-right`. --}}
                        <th class="!text-right">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @if ($rows->total() === 0)
                        <tr wire:key="empty">
                            <x-kit.empty-state :colspan="6" title="No site matches those filters." />
                        </tr>
                    @endif
                    @foreach ($rows as $s)
                        @php $punches = $counts[(string) $s->getKey()] ?? 0; @endphp
                        <tr wire:key="row-{{ $s->getKey() }}">
                            <td class="whitespace-nowrap font-medium">{{ $s->name }}</td>
                            <td class="num whitespace-nowrap text-muted-foreground">
                                {{ $s->geofence_exempt ? '—' : "{$s->latitude}, {$s->longitude}" }}
                            </td>
                            <td class="num whitespace-nowrap">
                                {{ $s->geofence_exempt ? '—' : "{$s->radius_meters} m" }}
                            </td>
                            <td>
                                <span
                                    class="{{ $s->geofence_exempt ? 'pill pill-muted' : 'pill pill-info' }}"
                                    title="{{ $s->geofence_exempt ? 'Anyone assigned to this site can punch from anywhere.' : 'A punch must come from inside this site’s radius.' }}"
                                    data-location-check
                                >
                                    {{ $s->geofence_exempt ? 'Not Checked' : 'Checked' }}
                                </span>
                            </td>
                            <td>
                                <span class="{{ $s->is_active ? 'pill pill-success' : 'pill pill-muted' }}">
                                    {{ $s->is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </td>
                            <td class="text-right">
                                <x-kit.row-actions :label="$s->name">
                                    <flux:menu.item icon="pencil" wire:click="startEdit('{{ $s->getKey() }}')">
                                        Edit
                                    </flux:menu.item>
                                    <flux:menu.item
                                        :icon="$s->is_active ? 'no-symbol' : 'check-circle'"
                                        wire:click="confirmStatus('{{ $s->getKey() }}')"
                                    >
                                        {{ $s->is_active ? 'Make Inactive' : 'Make Active' }}
                                    </flux:menu.item>
                                    <flux:menu.item
                                        icon="trash"
                                        :variant="$punches > 0 ? 'default' : 'danger'"
                                        :disabled="$punches > 0"
                                        wire:click="confirmDelete('{{ $s->getKey() }}')"
                                    >
                                        {{ $punches > 0 ? "Delete · in use by {$punches}" : 'Delete' }}
                                    </flux:menu.item>
                                </x-kit.row-actions>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <x-kit.pagination :paginator="$rows" noun="sites" />
    </section>

    {{-- The AlertDialog (sites-page.tsx:288-316). --}}
    <flux:modal wire:model.self="confirmOpen" class="w-full max-w-md">
        <div class="space-y-4">
            <div class="space-y-2">
                <flux:heading size="lg">Delete {{ $deleting?->name }}?</flux:heading>
                <flux:text>
                    Only an unused site can be deleted. A site assigned to employees or referenced
                    by attendance, schedule or project history must remain so those records keep
                    their location. To stop using such a site, make it inactive instead.
                </flux:text>
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="outline">Cancel</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" wire:click="deleteConfirmed">Delete</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Make inactive / Make active. The flag is all that changes. --}}
    <flux:modal wire:model.self="statusOpen" class="w-full max-w-md">
        <div class="space-y-4">
            <div class="space-y-2">
                @if ($changing?->is_active ?? true)
                    <flux:heading size="lg">Make {{ $changing?->name }} inactive?</flux:heading>
                    <flux:text>
                        An inactive site can no longer be chosen for new assignments. Employees
                        already assigned or scheduled there keep it, their punches are still checked
                        against it, and it stays on every past record. No schedule is changed.
                    </flux:text>
                @else
                    <flux:heading size="lg">Make {{ $changing?->name }} active?</flux:heading>
                    <flux:text>
                        The site can be chosen for new assignments again. Nothing else changes.
                    </flux:text>
                @endif
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="outline">Cancel</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" wire:click="statusConfirmed">
                    {{ ($changing?->is_active ?? true) ? 'Make Inactive' : 'Make Active' }}
                </flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- The SiteDialog (sites-page.tsx:337-582). --}}
    <flux:modal wire:model.self="dialogOpen" class="w-full max-w-lg">
        <div class="space-y-4">
            <div class="space-y-2">
                <flux:heading size="lg">{{ $editing !== null ? 'Edit Site' : 'Add Site' }}</flux:heading>
                <flux:text>
                    Name the site and choose whether people must be there to punch.
                </flux:text>
            </div>

            <form
                class="grid gap-4 sm:grid-cols-2"
                wire:submit="save"
                x-data="{
                    locating: false,
                    locate() {
                        if (!navigator.geolocation) {
                            window.Flux?.toast({ variant: 'danger', text: &quot;This browser can't share a location. Enter coordinates manually.&quot; });
                            return;
                        }
                        this.locating = true;
                        navigator.geolocation.getCurrentPosition(
                            (pos) => {
                                this.$wire.set('formLatitude', pos.coords.latitude.toFixed(6));
                                this.$wire.set('formLongitude', pos.coords.longitude.toFixed(6));
                                this.locating = false;
                                window.Flux?.toast({ variant: 'success', text: 'Location captured.' });
                            },
                            (err) => {
                                this.locating = false;
                                window.Flux?.toast({ variant: 'danger', text: 'Location unavailable: ' + err.message + '. Enter coordinates manually.' });
                            },
                            { enableHighAccuracy: true, timeout: 10000 },
                        );
                    },
                }"
            >
                <div class="space-y-2 sm:col-span-2">
                    <flux:label>Site Name</flux:label>
                    <flux:input placeholder="Makati Head Office" wire:model="formName" />
                </div>

                {{-- The per-site choice. The round trip on a flip draws or drops
                     the location fields below; the hint always says what the
                     switch's present state means. --}}
                <div class="flex items-center gap-3 rounded-md border border-border bg-muted/40 p-4 sm:col-span-2">
                    <flux:switch id="site-checks-location" wire:model.live="formChecksLocation" aria-label="Check Location When Punching" />
                    <div>
                        <flux:label for="site-checks-location">Check Location When Punching</flux:label>
                        <p class="text-xs text-muted-foreground" data-location-check-hint>
                            @if ($formChecksLocation)
                                On: people must be at this site to punch. A punch from outside the radius below is not accepted.
                            @else
                                Off: anyone assigned to this site can punch from anywhere. Their location is not asked for.
                            @endif
                        </p>
                    </div>
                </div>

                @if ($formChecksLocation)
                    <div class="space-y-2">
                        <flux:label>Latitude</flux:label>
                        <flux:input
                            class:input="num"
                            placeholder="14.554700"
                            wire:model.live.debounce.250ms="formLatitude"
                        />
                    </div>
                    <div class="space-y-2">
                        <flux:label>Longitude</flux:label>
                        <div class="flex gap-2">
                            <flux:input
                                class="flex-1"
                                class:input="num"
                                placeholder="121.024400"
                                wire:model.live.debounce.250ms="formLongitude"
                            />
                            <flux:button
                                type="button"
                                variant="outline"
                                square
                                aria-label="Use my location"
                                x-on:click="locate"
                            >
                                <flux:icon.loading x-show="locating" class="size-4" />
                                <flux:icon.viewfinder-circle x-show="! locating" class="size-4" />
                            </flux:button>
                        </div>
                    </div>
                    <div class="space-y-2">
                        <flux:label>Radius (metres)</flux:label>
                        <flux:input type="number" min="1" step="1" class:input="num" wire:model="formRadius" />
                    </div>
                    @if ($drift !== null)
                        <div class="flex items-end sm:col-span-2">
                            <p class="flex items-center gap-2 text-sm text-warning">
                                <flux:icon.map-pin class="size-4 shrink-0" />
                                <span>
                                    These coordinates sit
                                    <span class="num font-medium">{{ $drift }} m</span> from the
                                    saved ones.
                                </span>
                            </p>
                        </div>
                    @endif
                @endif

                <div class="sm:col-span-2">
                    <flux:button
                        type="submit"
                        variant="primary"
                        :icon="$editing !== null ? 'pencil' : 'plus'"
                    >
                        {{ $editing !== null ? 'Save Changes' : 'Add Site' }}
                    </flux:button>
                </div>
            </form>
        </div>
    </flux:modal>
</div>
