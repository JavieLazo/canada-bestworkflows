{{--
    View for App\Livewire\Kit\EmployeePicker — see that class for the API.

    Structure is the port of EmployeePicker.tsx:146-273: a combobox trigger
    styled level with the selects beside it, a panel the width of the trigger
    with the search box pinned on top, and a scrolling listbox under it.
    Open/close and the keyboard highlight are Alpine (no round trip per arrow
    key); the LIST is server-rendered from the scoped, searched query.

    Accessibility carried over: the search box is the combobox a screen reader
    tracks, `aria-activedescendant` follows the highlight, rows stay out of the
    tab order, and a polite live region announces how many matches a search
    found.

    THE PANEL IS A NATIVE POPOVER (`popover="manual"` — the date-field
    calendar's mechanics, see resources/js/date-field.js): shown with
    showPopover() it sits in the browser's top layer, where a Flux <dialog>
    (a transformed scroll container) cannot clip it — inside the roster's
    Assign shift dialog an absolute panel would be cut off at the dialog's
    edge. It stays a DOM descendant of the field (teleported to
    <body> it would be inert under the modal, and Flux would read a click on
    it as outside the dialog), is positioned by hand from the trigger's
    getBoundingClientRect(), and carries `wire:ignore.self` so the morph
    that repaints the LIST on every search keeps the position JS set on the
    panel itself. Escape is cancelled on the keydown so it closes the list
    before the dialog. Without showPopover the panel falls back to the
    absolute box those containers clip.
--}}
<div
    class="relative"
    x-data="{
        open: false,
        active: 0,
        native: 'showPopover' in HTMLElement.prototype,
        onViewport: null,
        sizer: null,
        rows() { return Array.from(this.$refs.list?.querySelectorAll('[role=option]') ?? []); },
        {{-- The highlight can never point past the end of a list the last
             keystroke shortened (EmployeePicker.tsx:93-94). --}}
        clamp() { return Math.min(this.active, Math.max(this.rows().length - 1, 0)); },
        scrollActive() {
            this.$nextTick(() => { this.$refs.list?.querySelector('[data-active]')?.scrollIntoView({ block: 'nearest' }); });
        },
        {{-- Opening starts from a clean search box and lands the highlight on
             whoever is already chosen (EmployeePicker.tsx:104-117). --}}
        toggle() {
            if (this.open) { this.close(); return; }
            const finish = () => {
                const current = this.rows().findIndex((r) => r.getAttribute('aria-selected') === 'true');
                this.active = current < 0 ? 0 : current;
                this.open = true;
                this.$nextTick(() => { this.showNative(); this.reposition(); this.bindViewport(); this.$refs.search?.focus(); this.scrollActive(); });
            };
            if (this.$wire.search !== '') { this.$wire.set('search', '').then(finish); } else { finish(); }
        },
        close(restoreFocus = false) {
            if (!this.open) return;
            this.open = false;
            this.unbindViewport();
            this.hideNative();
            if (restoreFocus) this.$refs.trigger?.focus();
        },
        onKeydown(e) {
            const max = this.rows().length - 1;
            const idx = this.clamp();
            if (e.key === 'ArrowDown') { e.preventDefault(); this.active = Math.min(idx + 1, Math.max(max, 0)); this.scrollActive(); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); this.active = Math.max(idx - 1, 0); this.scrollActive(); }
            else if (e.key === 'Enter') { e.preventDefault(); const pick = this.rows()[idx]; if (pick) this.choose(pick.dataset.id); }
            {{-- Cancelled on the keydown so that, inside a <dialog>, Escape
                 closes the list and not the dialog around it; a second
                 Escape then reaches the dialog. --}}
            else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); this.close(true); }
        },
        choose(id) { this.close(); this.$wire.choose(id); },
        {{-- The popover closed underneath us (the dialog going away, or the UA). --}}
        onToggle(e) { if (e.newState === 'closed' && this.open) this.close(); },
        {{-- Below the trigger and as wide as it (never narrower than the
             list's minimum); above it when there is no room below; never
             off screen. --}}
        reposition() {
            if (!this.native || !this.open) return;
            const panel = this.$refs.panel;
            const trigger = this.$refs.trigger;
            if (!panel || !trigger) return;
            const field = trigger.getBoundingClientRect();
            const G = 8;
            const GAP = 4;
            const vw = window.innerWidth;
            const vh = window.innerHeight;
            const w = Math.min(Math.max(field.width, 256), vw - 2 * G);
            panel.style.width = Math.round(w) + 'px';
            const h = panel.offsetHeight;
            const left = Math.max(G, Math.min(field.left, vw - w - G));
            let top = field.bottom + GAP;
            if (top + h > vh - G && field.top - GAP - h >= G) top = field.top - GAP - h;
            top = Math.max(G, Math.min(top, vh - h - G));
            panel.style.top = Math.round(top) + 'px';
            panel.style.left = Math.round(left) + 'px';
        },
        bindViewport() {
            if (this.onViewport) return;
            this.onViewport = () => this.reposition();
            window.addEventListener('scroll', this.onViewport, { capture: true, passive: true });
            window.addEventListener('resize', this.onViewport, { passive: true });
            {{-- A search that shortens the list shrinks the panel, and a
                 panel flipped above the trigger has to follow its own
                 height; the trigger is watched too, for a dialog still
                 laying out when the panel opened. --}}
            if ('ResizeObserver' in window && this.$refs.panel) { this.sizer = new ResizeObserver(() => this.reposition()); this.sizer.observe(this.$refs.panel); if (this.$refs.trigger) this.sizer.observe(this.$refs.trigger); }
        },
        unbindViewport() {
            if (!this.onViewport) return;
            window.removeEventListener('scroll', this.onViewport, { capture: true });
            window.removeEventListener('resize', this.onViewport);
            this.onViewport = null;
            this.sizer?.disconnect();
            this.sizer = null;
        },
        {{-- showPopover() throws when already showing or detached, and
             :popover-open throws where the selector is unknown — both are
             nothing to do, not errors. --}}
        showNative() {
            if (!this.native || !this.$refs.panel) return;
            try { if (!this.$refs.panel.matches(':popover-open')) this.$refs.panel.showPopover(); } catch (e) {}
        },
        hideNative() {
            if (!this.native || !this.$refs.panel) return;
            try { if (this.$refs.panel.matches(':popover-open')) this.$refs.panel.hidePopover(); } catch (e) {}
        }
    }"
    x-on:click.outside="close()"
>
    {{-- The trigger: everything a SelectTrigger looks like, so the field sits
         level with the selects beside it (EmployeePicker.tsx:148-183). --}}
    <button
        id="{{ $buttonId }}"
        x-ref="trigger"
        type="button"
        role="combobox"
        x-bind:aria-expanded="open"
        aria-controls="{{ $listId }}"
        @if ($labelledBy) aria-labelledby="{{ $labelledBy }} {{ $buttonId }}" @endif
        @disabled($disabled)
        x-on:click="toggle()"
        class="flex h-10 w-full cursor-pointer items-center justify-between gap-2 whitespace-nowrap rounded-lg border border-input bg-transparent px-3 py-2 text-sm shadow-xs focus:outline-none focus:ring-1 focus:ring-ring disabled:cursor-not-allowed disabled:opacity-50"
    >
        @if ($selected !== null)
            <span class="flex min-w-0 items-center gap-2">
                {{-- photo_path exists on the model, but no photo-serving route is
                     wired here, so initials stand in (the source shows the photo
                     when present). --}}
                <span class="flex size-5 shrink-0 items-center justify-center overflow-hidden rounded-full bg-muted">
                    <span class="num text-[0.5rem] font-semibold">{{ \App\Support\EmployeeName::initials($selected) }}</span>
                </span>
                {{-- Spelled by EmployeeName, like the option rows below, so the
                     person the list calls "Dela Cruz Jr." is never just "Dela
                     Cruz" here. It is also the button's accessible name, and a
                     screen-reader user has no second surface to check it
                     against.

                     The Employee ID goes in front of the name only when the
                     person has one — with the whole `@if` skipped, the em dash
                     goes with it rather than opening the accessible name with
                     a stray separator. --}}
                @php $identity = \App\Support\EmployeeIdentity::besideName($selected); @endphp
                <span class="truncate">
                    @if ($identity !== '')<span class="num">{{ $identity }}</span> &mdash; @endif{{ \App\Support\EmployeeName::display($selected) }}
                </span>
            </span>
        @else
            <span class="truncate text-muted-foreground">{{ $placeholder }}</span>
        @endif
        <flux:icon.chevron-down class="size-4 shrink-0 opacity-50" />
    </button>

    {{-- The UA popover stylesheet is overridden explicitly (m-0, inset-auto,
         p-0, the border, colours and size) because it sets margin:auto,
         inset:0, a solid border, padding, Canvas colours and fit-content; its
         display:none while not :popover-open is the hidden state in a
         supporting browser, and x-show is the fallback's. --}}
    <div
        x-ref="panel"
        popover="manual"
        wire:ignore.self
        x-show="native || open"
        x-bind:class="native ? 'fixed inset-auto' : 'absolute left-0 right-0 top-full mt-1'"
        x-on:toggle="onToggle($event)"
        style="display: none"
        class="z-50 m-0 h-auto min-w-[16rem] overflow-hidden rounded-md border border-border bg-popover p-0 text-popover-foreground shadow-md"
    >
        <div class="relative border-b border-border">
            <flux:icon.magnifying-glass class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
            {{-- The search box is where the arrow keys land, so it — not the
                 trigger — is the combobox a screen reader is tracking
                 (EmployeePicker.tsx:198-215). --}}
            <input
                x-ref="search"
                type="text"
                role="combobox"
                aria-expanded="true"
                aria-controls="{{ $listId }}"
                x-bind:aria-activedescendant="rows().length ? @js($listId) + '-option-' + clamp() : null"
                aria-label="Search {{ strtolower($ariaLabel) }}"
                placeholder="Search ID, name or position"
                autocomplete="off"
                wire:model.live.debounce.150ms="search"
                x-on:input="active = 0"
                x-on:keydown="onKeydown($event)"
                class="h-10 w-full border-0 bg-transparent pl-9 pr-3 text-sm outline-none placeholder:text-muted-foreground"
            />
        </div>

        <div
            x-ref="list"
            id="{{ $listId }}"
            role="listbox"
            aria-label="{{ $ariaLabel }}"
            wire:key="epk-list"
            class="max-h-72 overflow-y-auto p-1"
        >
            {{-- Announced as it changes, so someone who cannot see the list
                 still hears that a search found nothing. --}}
            <p aria-live="polite" class="sr-only">
                {{ count($visible) === 1 ? '1 employee found' : count($visible).' employees found' }}
            </p>

            @if (count($visible) === 0)
                <p class="px-3 py-6 text-center text-sm text-muted-foreground">
                    No employee matches that search.
                </p>
            @endif

            @foreach ($visible as $index => $employee)
                {{-- Out of the tab order: the search box is the only stop, and it
                     carries the highlight in aria-activedescendant
                     (EmployeePicker.tsx:242-247). --}}
                <button
                    wire:key="epk-option-{{ $employee->id }}"
                    id="{{ $listId }}-option-{{ $index }}"
                    type="button"
                    role="option"
                    tabindex="-1"
                    aria-selected="{{ $employee->id === $value ? 'true' : 'false' }}"
                    data-id="{{ $employee->id }}"
                    x-bind:data-active="clamp() === {{ $index }} ? 'true' : null"
                    x-bind:class="clamp() === {{ $index }} && 'bg-accent text-accent-foreground'"
                    x-on:mouseenter="active = {{ $index }}"
                    x-on:click="choose('{{ $employee->id }}')"
                    class="flex w-full items-center gap-3 rounded-sm px-2 py-1.5 text-left text-sm outline-none"
                >
                    <span class="flex size-8 shrink-0 items-center justify-center overflow-hidden rounded-full bg-muted">
                        <span class="num text-xs font-semibold">{{ \App\Support\EmployeeName::initials($employee) }}</span>
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate font-medium">{{ \App\Support\EmployeeName::display($employee) }}</span>
                        {{-- `num` stays on the id alone: the position beside it is
                             words, and the monospace face is for digits. The tail
                             is joined by EmployeeIdentity so an employee with no
                             company number opens the line with their position
                             instead of with " · ". --}}
                        @php
                            $identity = \App\Support\EmployeeIdentity::besideName($employee);
                            $tail = \App\Support\EmployeeIdentity::meta($employee->position, $employee->is_active === false ? 'inactive' : null);
                        @endphp
                        <span class="block truncate text-xs text-muted-foreground">
                            <span class="num">{{ $identity }}</span>{{ $identity !== '' && $tail !== '' ? ' · ' : '' }}{{ $tail }}
                        </span>
                    </span>
                    @if ($employee->id === $value)
                        <flux:icon.check class="size-4 shrink-0" />
                    @endif
                </button>
            @endforeach
        </div>
    </div>
</div>
