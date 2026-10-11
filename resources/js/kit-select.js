/*
 * `kitSelect` — the behaviour behind <x-kit.select>: a dropdown drawn like
 * the employee picker (a trigger styled as a field, and a panel with the
 * list) in place of the browser's own select list.
 *
 * THE NATIVE <select> STAYS, hidden, and it is the single source of truth.
 * Every binding a caller puts on the tag lands on it (`wire:model`,
 * `wire:change="…($event.target.value)"`, `x-model`, `x-on:change`, options
 * marked `:selected`), Livewire keeps morphing its options, and a pick here
 * is written to it with the same `input` and `change` events a person picking
 * in the native list would fire. Nothing here keeps a second copy of the
 * value. The list is read from the select's options each time it opens, and
 * the trigger's label is read back from the select whenever something may
 * have changed it:
 *
 *   - a `change` event on it (a pick here, or any other script);
 *   - a mutation inside it (Livewire morphing the options or a `selected`);
 *   - the bound `wire:model` path or `x-model` expression changing, because
 *     Livewire and Alpine set a select's value through `option.selected`,
 *     which fires no event and no mutation.
 *
 * Each of those defers the read by a microtask and a macrotask. Alpine applies
 * an `x-model` value in a queued job that can run after this component's
 * watcher in the same flush, and the select is only right once that job is
 * done.
 *
 * THE PANEL IS A NATIVE POPOVER (`popover="manual"`), placed by hand from the
 * trigger's getBoundingClientRect(): the date-field calendar's and the
 * employee picker's mechanics (see date-field.js). In the top layer it
 * escapes `overflow-hidden` cards and the transformed, scrolling Flux
 * <dialog>s. It stays a DOM descendant of the field, because teleported to
 * <body> it would be inert under an open modal and Flux would read a click on
 * it as a click outside the dialog. Escape is cancelled on the keydown, so it
 * closes the list before the dialog around it. Without `showPopover` the
 * panel falls back to an absolute box under the trigger, which those
 * containers clip.
 *
 * A search box sits on top of the list once the list is long enough to need
 * one (more than SEARCH_AFTER options), as on the employee picker; a short
 * list answers type-ahead instead, the way a native select does.
 *
 * Registered on `alpine:init`, like date-field.js, so it survives
 * wire:navigate. Run `npm run build` after touching it: a stale bundle breaks
 * every dropdown on every screen.
 */

const SUPPORTS_POPOVER = typeof HTMLElement !== 'undefined' && 'showPopover' in HTMLElement.prototype;

/** A list with more options than this gets a search box. */
export const SEARCH_AFTER = 8;

/** Viewport gutter and the gap between the trigger and the panel, in px. */
const GUTTER = 8;
const GAP = 4;

/** A long option wraps past this width (28rem) unless the trigger is wider. */
const MAX_WIDTH = 448;

/** A panel with a search box is never narrower than this (12rem), even under a narrow trigger. */
const SEARCH_MIN_WIDTH = 192;

/** Type-ahead keystrokes closer together than this extend one search. */
const TYPEAHEAD_MS = 700;

/** Whether the main pointer is a finger (phones and tablets). */
function coarsePointer() {
    return typeof window.matchMedia === 'function' && window.matchMedia('(pointer: coarse)').matches;
}

/**
 * Lower-case, accents folded and no-break spaces made plain, so "jose"
 * finds "José" and the indent a unit carries never blocks a match.
 */
export function fold(text) {
    return String(text)
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .replace(/ /g, ' ')
        .toLowerCase()
        .trim();
}

/** Whether every space-separated term of `query` appears in `label`. */
export function matches(label, query) {
    const haystack = fold(label);
    return fold(query)
        .split(/\s+/)
        .filter(Boolean)
        .every((term) => haystack.includes(term));
}

/**
 * The select's options as panel rows, in document order: options and `<hr>`
 * separators, each carrying the `<optgroup>` it sits in (`group`, a key, and
 * `groupLabel`). Flux's placeholder option (disabled, class `placeholder`) is
 * the field's empty state, not a choice, so it is left out, and so is a
 * `hidden` option.
 */
export function readOptions(select) {
    const rows = [];
    let groups = 0;
    const walk = (parent, group, groupKey) => {
        for (const node of parent.children) {
            const tag = node.tagName;
            if (tag === 'OPTION') {
                if (node.hidden || (node.disabled && node.classList.contains('placeholder'))) continue;
                rows.push({
                    type: 'option',
                    key: 'o' + node.index,
                    index: node.index,
                    value: node.value,
                    label: node.text,
                    disabled: node.disabled || Boolean(group && group.disabled),
                    group: groupKey,
                    groupLabel: group ? group.label : '',
                });
            } else if (tag === 'OPTGROUP') {
                groups += 1;
                walk(node, node, 'g' + groups);
            } else if (tag === 'HR') {
                rows.push({ type: 'separator', key: 's' + rows.length, group: groupKey, groupLabel: group ? group.label : '' });
            }
        }
    };
    walk(select, null, null);
    return rows;
}

export default function kitSelect({ searchable = null, clearValue = null, model = null, xModel = null } = {}) {
    return {
        native: SUPPORTS_POPOVER,
        open: false,

        /** What the trigger shows, and whether that is the placeholder. */
        label: '',
        placeholder: false,
        value: '',
        selectedIndex: -1,

        /** The list as it was read when the panel opened. */
        rows: [],
        query: '',
        searching: false,
        activeKey: null,

        /** Whether the highlight was moved by the keyboard since the list opened (Tab keeps only that). */
        keyed: false,

        /** Name read from a `<label for>` on the caller's id, when the tag had no aria-label. */
        externalName: '',

        /** False until setup() has read the select: the clear button waits for it. */
        ready: false,

        typed: '',
        typedAt: 0,
        queued: false,
        unwatchModel: null,
        timer: null,
        observer: null,
        onViewport: null,
        sizer: null,

        /*
         * Set up on the next tick, not in init() itself: the children's
         * `x-ref`s register only as the children initialise, which is after
         * this init(), and the first read of `$refs` is cached for good.
         */
        init() {
            /* The server's first label (App\Livewire\Kit\Support\SelectSlot)
               is taken over before the trigger's x-text first runs, so the
               field never flashes blank. It is read from the DOM rather than
               passed in x-data, which stays the same on every render. */
            const first = this.$el.querySelector('[data-kit-select-value]');
            if (first) {
                this.label = first.textContent.trim();
                this.placeholder = first.dataset.placeholder === 'true';
            }
            this.$nextTick(() => this.setup());
        },

        setup() {
            const select = this.$refs.native;
            if (!select) return;

            select.addEventListener('change', () => this.sync());
            this.observer = new MutationObserver(() => this.later());
            this.observer.observe(select, { childList: true, subtree: true, attributes: true, characterData: true });

            if (model && this.$wire && typeof this.$wire.$watch === 'function') {
                this.unwatchModel = this.$wire.$watch(model, () => this.later());
            }
            if (xModel) {
                this.$watch(xModel, () => this.later());
            }

            /* Flux's field links its label to the first input or select it
               holds, and the trigger is neither, so a click on the label is
               routed here instead. */
            this.$root.querySelector('[data-flux-label]')?.addEventListener('click', () => this.$refs.trigger?.focus());

            this.nameFromLabelFor();
            this.sync();
            this.ready = true;
            this.later();
        },

        /* The model watcher lives on the Livewire component, not on this
           element, so a select re-keyed away releases its own. */
        destroy() {
            this.observer?.disconnect();
            this.unwatchModel?.();
            this.unwatchModel = null;
            clearTimeout(this.timer);
            this.unbindViewport();
        },

        /* ── The value, read back from the native select ─────────────────── */

        sync() {
            const select = this.$refs.native;
            if (!select) return;
            const option = select.selectedIndex >= 0 ? select.options[select.selectedIndex] : null;
            this.selectedIndex = select.selectedIndex;
            this.value = select.value;
            this.label = option ? option.text : '';
            this.placeholder = !option || (option.disabled && option.classList.contains('placeholder'));
        },

        /*
         * One read per burst of changes, after the microtasks that apply
         * them, and a trailing read on the next task. Every call re-arms both:
         * a change that lands while an earlier read is still waiting must not
         * be dropped (the server's reply morphs the options after the pick
         * that sent it).
         */
        later() {
            if (!this.queued) {
                this.queued = true;
                queueMicrotask(() => {
                    this.queued = false;
                    this.sync();
                });
            }
            clearTimeout(this.timer);
            this.timer = setTimeout(() => this.sync(), 0);
        },

        /**
         * A clear button shows once the value has moved off `clearValue`,
         * and only after setup(): before the select is read, `value` is still
         * empty and every filter would flash its button.
         */
        get clearable() {
            return this.ready && clearValue !== null && !this.disabled() && this.value !== String(clearValue);
        },

        disabled() {
            return Boolean(this.$refs.trigger?.disabled);
        },

        /**
         * `<flux:label for="…">` and a plain `<label for="…">` name nothing
         * on a button that is not a form control, so a label pointing at the
         * caller's id is read once and spoken as the trigger's name.
         */
        nameFromLabelFor() {
            const id = this.$refs.trigger?.id;
            if (!id || this.$refs.name?.textContent.trim()) return;
            const escaped = window.CSS && CSS.escape ? CSS.escape(id) : id;
            const label = document.querySelector('label[for="' + escaped + '"], [data-flux-label][for="' + escaped + '"]');
            if (label) this.externalName = label.textContent.replace(/\s+/g, ' ').trim();
        },

        /* ── The list ────────────────────────────────────────────────────── */

        options() {
            return this.rows.filter((row) => row.type === 'option');
        },

        /** Rows as shown: a search keeps only matching options and drops the rules. */
        visible() {
            if (!this.searching || this.query.trim() === '') return this.rows;
            return this.rows.filter((row) => row.type === 'option' && matches(row.label, this.query));
        },

        /**
         * The visible rows in runs: each `<optgroup>` is one section under
         * its heading, and rows outside a group form untitled sections.
         */
        sections() {
            const out = [];
            let current = null;
            for (const row of this.visible()) {
                const group = row.group ?? null;
                if (!current || current.group !== group) {
                    current = { key: 'sec' + out.length + (group ?? ''), group, label: row.groupLabel ?? '', rows: [] };
                    out.push(current);
                }
                current.rows.push(row);
            }
            return out;
        },

        choices() {
            return this.visible().filter((row) => row.type === 'option' && !row.disabled);
        },

        optionId(row) {
            return this.$id('kit-select-option') + '-' + row.index;
        },

        activeId() {
            const row = this.choices().find((r) => r.key === this.activeKey);
            return row ? this.optionId(row) : null;
        },

        isSelected(row) {
            return row.type === 'option' && row.index === this.selectedIndex;
        },

        status() {
            const count = this.choices().length;
            if (!this.searching || this.query.trim() === '') return '';
            return count === 0 ? 'No matches' : count === 1 ? '1 match' : count + ' matches';
        },

        /* ── Opening and closing ─────────────────────────────────────────── */

        toggle() {
            if (this.open) {
                this.close(true);
            } else {
                this.show();
            }
        },

        show(typed = '') {
            if (this.open || this.disabled()) return;
            this.sync();
            this.rows = readOptions(this.$refs.native);
            this.searching = searchable === null ? this.options().length > SEARCH_AFTER : Boolean(searchable);
            this.query = this.searching ? typed : '';
            const selected = this.choices().find((row) => this.isSelected(row));
            this.activeKey = selected && !this.query ? selected.key : (this.choices()[0]?.key ?? null);
            this.keyed = false;
            if (typed && !this.searching) this.typeAhead(typed);
            this.open = true;
            /* Shown after this click's dispatch: an `.outside` handler on
               another open dropdown runs in the same dispatch and closes it,
               and this panel has no box yet to be "outside" of. */
            this.$nextTick(() => {
                this.showNative();
                this.reposition();
                this.bindViewport();
                /* On a touch screen focus stays put: a focused search box
                   would raise the on-screen keyboard over the list before
                   anyone asked to type. A tap on the box focuses it. */
                if (!coarsePointer()) {
                    (this.searching ? this.$refs.search : this.$refs.list)?.focus({ preventScroll: true });
                    if (this.searching && this.$refs.search) {
                        const end = this.$refs.search.value.length;
                        this.$refs.search.setSelectionRange(end, end);
                    }
                }
                this.scrollActive();
            });
        },

        close(restoreFocus = false) {
            if (!this.open) return;
            this.open = false;
            this.query = '';
            this.unbindViewport();
            this.hideNative();
            if (restoreFocus) this.$refs.trigger?.focus({ preventScroll: true });
        },

        /**
         * Focus moving to something outside the field closes the list. A
         * null relatedTarget is not that: Safari and Firefox on macOS focus
         * no button on click, so a click on the open trigger blurs the list
         * with nothing to go to, and closing here would let the same click
         * reopen it. A click elsewhere is closed by `click.outside`.
         */
        onFocusOut(event) {
            if (!this.open) return;
            const next = event.relatedTarget;
            if (!next || this.$root.contains(next)) return;
            this.close(false);
        },

        /** The popover closed underneath us (its dialog going away, or the UA). */
        onToggle(event) {
            if (event.newState === 'closed' && this.open) this.close(false);
        },

        /* ── Picking ─────────────────────────────────────────────────────── */

        /**
         * Write the pick to the native select by INDEX (two options may share
         * a value) and fire what a native pick fires. No event when the pick
         * is the option already chosen, exactly as a native select behaves.
         * The list was read when it opened and Livewire may have morphed the
         * options since, so the option at that index is checked against the
         * row's value and text, and looked up again when it moved.
         */
        choose(row) {
            if (!row || row.type !== 'option' || row.disabled) return;
            const select = this.$refs.native;
            this.close(true);
            if (!select) return;
            const same = (o) => o && o.value === row.value && o.text === row.label && !o.disabled;
            const option = row.value === undefined || same(select.options[row.index])
                ? select.options[row.index]
                : Array.from(select.options).find(same);
            if (!option || select.selectedIndex === option.index) return;
            select.selectedIndex = option.index;
            select.dispatchEvent(new Event('input', { bubbles: true }));
            select.dispatchEvent(new Event('change', { bubbles: true }));
            this.sync();
        },

        /** The clear button puts a filter back on its "all" option. */
        clear() {
            const select = this.$refs.native;
            if (!select || clearValue === null) return;
            const option = Array.from(select.options).find((o) => o.value === String(clearValue));
            if (!option) return;
            this.choose({ type: 'option', index: option.index, disabled: false });
            this.$refs.trigger?.focus({ preventScroll: true });
        },

        /* ── Keyboard ────────────────────────────────────────────────────── */

        /** On the closed trigger: the keys that open a native select open this one. */
        onTriggerKeydown(event) {
            /* Open with focus still here (a touch screen with a keyboard):
               the list's keys apply, and Enter or Space picks rather than
               clicking the trigger shut. */
            if (this.open) {
                this.onPanelKeydown(event);
                return;
            }
            const key = event.key;
            if (key === 'ArrowDown' || key === 'ArrowUp' || key === 'Enter' || key === ' ' || key === 'F4') {
                event.preventDefault();
                this.show();
            } else if (key.length === 1 && !event.ctrlKey && !event.metaKey && !event.altKey && key !== ' ') {
                event.preventDefault();
                this.show(key);
            }
        },

        /** In the open panel, on the search box or the list. */
        onPanelKeydown(event) {
            const key = event.key;
            const inSearch = event.target === this.$refs.search;

            if (key === 'Escape') {
                /* Cancelled here so that, inside a <dialog>, Escape closes
                   the list and not the dialog; a second Escape reaches it. */
                event.preventDefault();
                event.stopPropagation();
                this.close(true);
            } else if (key === 'Tab') {
                /* A highlight reached with the arrows or by typing is kept,
                   as a select-only combobox does; a search box's first match
                   is not, so typing never picks by accident. Focus is back on
                   the trigger before the browser moves it on, so Tab leaves
                   the field the way it leaves a select. */
                const row = inSearch || !this.keyed ? null : this.choices().find((r) => r.key === this.activeKey);
                if (row && !this.isSelected(row)) {
                    this.choose(row);
                } else {
                    this.close(true);
                }
            } else if (key === 'ArrowDown' || key === 'ArrowUp') {
                event.preventDefault();
                this.move(key === 'ArrowDown' ? 1 : -1);
            } else if (key === 'PageDown' || key === 'PageUp') {
                event.preventDefault();
                this.move(key === 'PageDown' ? 10 : -10);
            } else if ((key === 'Home' || key === 'End') && !inSearch) {
                event.preventDefault();
                const list = this.choices();
                this.activeKey = (key === 'Home' ? list[0] : list[list.length - 1])?.key ?? null;
                this.keyed = true;
                this.scrollActive();
            } else if (key === 'Enter' || (key === ' ' && !inSearch)) {
                event.preventDefault();
                this.choose(this.choices().find((row) => row.key === this.activeKey));
            } else if (!inSearch && key.length === 1 && !event.ctrlKey && !event.metaKey && !event.altKey) {
                event.preventDefault();
                this.typeAhead(key);
            }
        },

        move(step) {
            const list = this.choices();
            if (list.length === 0) return;
            const at = list.findIndex((row) => row.key === this.activeKey);
            const next = at < 0 ? (step > 0 ? 0 : list.length - 1) : Math.min(Math.max(at + step, 0), list.length - 1);
            this.activeKey = list[next].key;
            this.keyed = true;
            this.scrollActive();
        },

        /** Search typing narrows the list; the highlight goes to the first match. */
        onSearchInput() {
            this.activeKey = this.choices()[0]?.key ?? null;
            this.scrollActive();
        },

        /**
         * Type-ahead on a short list: letters typed close together build one
         * prefix; a single repeated letter cycles through the options that
         * start with it, as a native select does.
         */
        typeAhead(char) {
            const now = Date.now();
            this.typed = now - this.typedAt > TYPEAHEAD_MS ? char : this.typed + char;
            this.typedAt = now;
            const list = this.choices();
            if (list.length === 0) return;
            const needle = fold(this.typed);
            const cycling = needle.length > 1 && needle.split('').every((c) => c === needle[0]);
            const prefix = cycling ? needle[0] : needle;
            const at = list.findIndex((row) => row.key === this.activeKey);
            const ordered = cycling || prefix.length === 1 ? list.slice(at + 1).concat(list.slice(0, at + 1)) : list;
            const hit = ordered.find((row) => fold(row.label).startsWith(prefix));
            if (hit) {
                this.activeKey = hit.key;
                this.keyed = true;
                this.scrollActive();
            }
        },

        scrollActive() {
            this.$nextTick(() => {
                this.$refs.list?.querySelector('[data-active]')?.scrollIntoView({ block: 'nearest' });
            });
        },

        /* ── Placement ───────────────────────────────────────────────────── */

        /**
         * Below the trigger and at least as wide as it, wider when an option
         * needs it (up to MAX_WIDTH, and never wider than the viewport);
         * above it when there is no room below; never off screen.
         */
        reposition() {
            if (!this.native || !this.open) return;
            const panel = this.$refs.panel;
            const trigger = this.$refs.trigger;
            if (!panel || !trigger) return;
            const field = trigger.getBoundingClientRect();
            const vw = window.innerWidth;
            const vh = window.innerHeight;
            const room = Math.max(vw - 2 * GUTTER, 0);
            const least = this.searching ? Math.max(field.width, SEARCH_MIN_WIDTH) : field.width;
            panel.style.minWidth = Math.round(Math.min(least, room)) + 'px';
            panel.style.maxWidth = Math.round(Math.min(Math.max(field.width, MAX_WIDTH), room)) + 'px';
            const w = panel.offsetWidth;
            const h = panel.offsetHeight;
            const left = Math.max(GUTTER, Math.min(field.left, vw - w - GUTTER));
            let top = field.bottom + GAP;
            if (top + h > vh - GUTTER && field.top - GAP - h >= GUTTER) top = field.top - GAP - h;
            top = Math.max(GUTTER, Math.min(top, vh - h - GUTTER));
            panel.style.top = Math.round(top) + 'px';
            panel.style.left = Math.round(left) + 'px';
        },

        bindViewport() {
            if (this.onViewport) return;
            this.onViewport = () => this.reposition();
            window.addEventListener('scroll', this.onViewport, { capture: true, passive: true });
            window.addEventListener('resize', this.onViewport, { passive: true });
            /* A search that shortens the list shrinks the panel, and a panel
               flipped above the trigger has to follow its own height; the
               trigger is watched too, for a dialog still laying out. */
            if ('ResizeObserver' in window) {
                this.sizer = new ResizeObserver(() => this.reposition());
                if (this.$refs.panel) this.sizer.observe(this.$refs.panel);
                if (this.$refs.trigger) this.sizer.observe(this.$refs.trigger);
            }
        },

        unbindViewport() {
            if (!this.onViewport) return;
            window.removeEventListener('scroll', this.onViewport, { capture: true });
            window.removeEventListener('resize', this.onViewport);
            this.onViewport = null;
            this.sizer?.disconnect();
            this.sizer = null;
        },

        /* showPopover() throws when already showing or detached, and
           :popover-open throws where the selector is unknown — both are
           nothing to do, not errors. */
        showNative() {
            if (!this.native || !this.$refs.panel) return;
            try {
                if (!this.$refs.panel.matches(':popover-open')) this.$refs.panel.showPopover();
            } catch (e) {}
        },

        hideNative() {
            if (!this.native || !this.$refs.panel) return;
            try {
                if (this.$refs.panel.matches(':popover-open')) this.$refs.panel.hidePopover();
            } catch (e) {}
        },
    };
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('kitSelect', kitSelect);
});
