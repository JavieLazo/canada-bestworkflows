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
--}}
<div
    class="relative"
    x-data="{
        open: false,
        active: 0,
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
            if (this.open) { this.open = false; return; }
            const finish = () => {
                const current = this.rows().findIndex((r) => r.getAttribute('aria-selected') === 'true');
                this.active = current < 0 ? 0 : current;
                this.open = true;
                this.$nextTick(() => { this.$refs.search?.focus(); this.scrollActive(); });
            };
            if (this.$wire.search !== '') { this.$wire.set('search', '').then(finish); } else { finish(); }
        },
        onKeydown(e) {
            const max = this.rows().length - 1;
            const idx = this.clamp();
            if (e.key === 'ArrowDown') { e.preventDefault(); this.active = Math.min(idx + 1, Math.max(max, 0)); this.scrollActive(); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); this.active = Math.max(idx - 1, 0); this.scrollActive(); }
            else if (e.key === 'Enter') { e.preventDefault(); const pick = this.rows()[idx]; if (pick) this.choose(pick.dataset.id); }
            else if (e.key === 'Escape') { this.open = false; }
        },
        choose(id) { this.open = false; this.$wire.choose(id); }
    }"
    x-on:click.outside="open = false"
>
    {{-- The trigger: everything a SelectTrigger looks like, so the field sits
         level with the selects beside it (EmployeePicker.tsx:148-183). --}}
    <button
        id="{{ $buttonId }}"
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
                     wired yet; initials stand in until the Employees module adds
                     one (source showed the photo when present). --}}
                <span class="flex size-5 shrink-0 items-center justify-center overflow-hidden rounded-full bg-muted">
                    <span class="num text-[0.5rem] font-semibold">{{ mb_strtoupper(mb_substr($selected->first_name, 0, 1).mb_substr($selected->last_name, 0, 1)) }}</span>
                </span>
                <span class="truncate">
                    <span class="num">{{ $selected->employee_code }}</span> &mdash; {{ $selected->first_name }} {{ $selected->last_name }}
                </span>
            </span>
        @else
            <span class="truncate text-muted-foreground">{{ $placeholder }}</span>
        @endif
        <flux:icon.chevron-down class="size-4 shrink-0 opacity-50" />
    </button>

    <div
        x-show="open"
        style="display: none"
        class="absolute left-0 right-0 top-full z-50 mt-1 min-w-[16rem] overflow-hidden rounded-md border border-border bg-popover text-popover-foreground shadow-md"
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
                placeholder="Search code, name or position"
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
                        <span class="num text-xs font-semibold">{{ mb_strtoupper(mb_substr($employee->first_name, 0, 1).mb_substr($employee->last_name, 0, 1)) }}</span>
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate font-medium">{{ \App\Support\EmployeeName::display($employee) }}</span>
                        <span class="block truncate text-xs text-muted-foreground">
                            <span class="num">{{ $employee->employee_code }}</span>{{ $employee->position ? ' · '.$employee->position : '' }}{{ $employee->is_active === false ? ' · inactive' : '' }}
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
