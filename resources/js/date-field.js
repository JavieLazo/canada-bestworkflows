/*
 * `dateField` — the behaviour behind <x-kit.date-field>: the typed half (a
 * `dd MMM yyyy` box that parses as it is typed) and the picking half (a
 * calendar popover), over one `YYYY-MM-DD` value entangled with Livewire.
 *
 * THE PARSERS ARE THE JS MIRROR OF App\Support\DateText. `toDisplay` and
 * `toKey` below are byte-for-byte what that class does in PHP — keep the two
 * in sync; the server side is the source of truth for what a typed date
 * means. Calendar arithmetic is plain local year/month/day on `new Date(y, m,
 * d)`; nothing here goes through an ISO string or UTC, so there is no
 * timezone to get wrong, and "today" is the browser's calendar day.
 *
 * THE PANEL IS A NATIVE POPOVER (`popover="manual"`), shown with
 * `showPopover()`. That puts it in the browser's top layer, where the
 * viewport is its containing block and an ancestor's `overflow` or
 * `transform` does not apply — the field sits inside `overflow-hidden` cards
 * and inside Flux <dialog>s (which are transformed, and scroll), and an
 * ordinary absolute panel is clipped in about half of its callers. Shown
 * after a dialog's showModal(), it stacks above the dialog. It stays a DOM
 * descendant of the field on purpose: a panel teleported to <body> would be
 * inert underneath an open modal, and Flux's click-outside would read a
 * click on it as a click outside the dialog. Position is set by hand from
 * the field's getBoundingClientRect(), because the top layer ignores
 * `absolute` offsets against the field.
 *
 * Two details that look odd and are not:
 *
 *   - The show is deferred to $nextTick. Alpine's `.outside` handler lives on
 *     `document` and runs in the same click dispatch as the trigger's own
 *     handler, but skips an element that has no box yet — so showing the
 *     panel after the dispatch keeps the trigger's own click from closing
 *     it, with no `.stop` (which would also stop other outside handlers,
 *     e.g. the notification bell's).
 *   - Escape is cancelled on the keydown. Flux relies on the UA's `cancel`
 *     to close a dialog on Escape, and a cancelled keydown suppresses that
 *     close request, so Escape closes the calendar first and the dialog on
 *     the next press. The dialog's `cancel` listener is a second guard for a
 *     browser that fires it anyway.
 *
 * Registered on `alpine:init`, like money-field.js, so it survives
 * wire:navigate. The blade component passes the entangled value in:
 * `x-data="dateField($wire.entangle('form.date_hired'))"`.
 */

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
const MONTH_NAMES = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

const SUPPORTS_POPOVER = typeof HTMLElement !== 'undefined' && 'showPopover' in HTMLElement.prototype;

/* ── DateText mirror ─────────────────────────────────────────────────────── */

/** `2026-08-20` → `20 Aug 2026`. Empty in, empty out. (DateText::toDisplay) */
export function toDisplay(key) {
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(key).trim());
    if (!m) return '';
    const month = MONTHS[Number(m[2]) - 1];
    return month ? m[3] + ' ' + month + ' ' + m[1] : '';
}

/**
 * Accepts what people actually type: `20 Aug 2026`, `20/08/2026`,
 * `2026-08-20`, `20 August 2026`. Returns the storage key, or "" when it is
 * not a real date — the 31st of a 30-day month included. (DateText::toKey)
 */
export function toKey(text) {
    const raw = String(text).trim();
    if (!raw) return '';
    const iso = /^(\d{4})-(\d{1,2})-(\d{1,2})$/.exec(raw);
    const slashed = /^(\d{1,2})[\/.-](\d{1,2})[\/.-](\d{4})$/.exec(raw);
    const worded = /^(\d{1,2})\s+([A-Za-z]+)\s+(\d{4})$/.exec(raw);
    let year, month, day;
    if (iso) { year = Number(iso[1]); month = Number(iso[2]); day = Number(iso[3]); }
    else if (slashed) { day = Number(slashed[1]); month = Number(slashed[2]); year = Number(slashed[3]); }
    else if (worded) {
        const idx = MONTHS.findIndex((mm) => worded[2].toLowerCase().startsWith(mm.toLowerCase()));
        if (idx < 0) return '';
        day = Number(worded[1]); month = idx + 1; year = Number(worded[3]);
    } else return '';
    if (month < 1 || month > 12 || day < 1 || day > 31) return '';
    const date = new Date(year, month - 1, day);
    if (date.getFullYear() !== year || date.getMonth() !== month - 1 || date.getDate() !== day) return '';
    return String(year).padStart(4, '0') + '-' + String(month).padStart(2, '0') + '-' + String(day).padStart(2, '0');
}

/* ── calendar arithmetic (local y/m/d only; `m` is 0-based here) ─────────── */

const pad2 = (n) => String(n).padStart(2, '0');

const keyOf = (y, m, d) => String(y).padStart(4, '0') + '-' + pad2(m + 1) + '-' + pad2(d);

/** `{ y, m (0-based), d }` of a `YYYY-MM-DD` key, or null. */
const partsOf = (key) => {
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(key ?? '').trim());
    return m ? { y: Number(m[1]), m: Number(m[2]) - 1, d: Number(m[3]) } : null;
};

const keyOfDate = (dt) => keyOf(dt.getFullYear(), dt.getMonth(), dt.getDate());

const todayKey = () => keyOfDate(new Date());

const daysInMonth = (y, m) => new Date(y, m + 1, 0).getDate();

const addDays = (key, n) => {
    const p = partsOf(key);
    return p ? keyOfDate(new Date(p.y, p.m, p.d + n)) : key;
};

/** Steps whole months, clamping the day to the month's length (31 Jan + 1 → 28/29 Feb). */
const addMonths = (key, n) => {
    const p = partsOf(key);
    if (!p) return key;
    const total = p.m + n;
    const y = p.y + Math.floor(total / 12);
    const m = ((total % 12) + 12) % 12;
    return keyOf(y, m, Math.min(p.d, daysInMonth(y, m)));
};

const dayOfWeek = (key) => {
    const p = partsOf(key);
    return p ? new Date(p.y, p.m, p.d).getDay() : 0;
};

/** The spoken form a screen reader gets for a day: `20 August 2026`. */
const labelOf = (key) => {
    const p = partsOf(key);
    return p ? p.d + ' ' + MONTH_NAMES[p.m] + ' ' + p.y : '';
};

/* ── the component ───────────────────────────────────────────────────────── */

export default function dateField(entangled) {
    return {
        state: entangled,
        text: '',
        open: false,
        native: SUPPORTS_POPOVER,
        view: { year: 1970, month: 0 },
        focusKey: '',
        dialog: null,
        onDialogClose: null,
        onDialogCancel: null,
        onViewport: null,

        init() {
            if (this.state === null || this.state === undefined) this.state = '';
            this.text = toDisplay(this.state);
            // Re-sync when the form is reset or the value changes from outside,
            // but not while the field holds an equivalent value — that would
            // fight the typist (DateField.tsx:95-98).
            this.$watch('state', (v) => { if (toKey(this.text) !== v) this.text = toDisplay(v ?? ''); });
            this.syncView();

            // Inside a Flux modal: the dialog closing takes the calendar with
            // it, and a `cancel` (Escape) while the calendar is open closes
            // only the calendar.
            this.dialog = this.$root.closest('dialog');
            if (this.dialog) {
                this.onDialogClose = () => this.close(false);
                this.onDialogCancel = (e) => {
                    if (!this.open) return;
                    e.preventDefault();
                    this.close();
                };
                this.dialog.addEventListener('close', this.onDialogClose);
                this.dialog.addEventListener('cancel', this.onDialogCancel);
            }
        },

        destroy() {
            this.unbindViewport();
            this.hideNative();
            if (this.dialog) {
                this.dialog.removeEventListener('close', this.onDialogClose);
                this.dialog.removeEventListener('cancel', this.onDialogCancel);
                this.dialog = null;
            }
        },

        /* ── the typed half ── */

        commit(next) {
            this.text = next;
            const key = toKey(next);
            if (!next.trim()) this.state = '';
            else if (key) this.state = key;
        },

        settle() {
            this.text = toDisplay(this.state ?? '');
        },

        pick(v) {
            if (!v) return;
            this.state = v;
            this.text = toDisplay(v);
        },

        /* ── open and close ── */

        toggle() {
            this.open ? this.close() : this.openPanel();
        },

        openPanel() {
            if (this.open) return;
            this.syncView();
            this.open = true;
            this.$nextTick(() => {
                this.showNative();
                this.reposition();
                this.bindViewport();
                this.focusCell(this.focusKey);
            });
        },

        close(restoreFocus = true) {
            if (!this.open) return;
            const panel = this.$refs.panel;
            const hadFocus = !!panel && panel.contains(document.activeElement);
            this.open = false;
            this.unbindViewport();
            this.hideNative();
            if (restoreFocus && hadFocus) this.$refs.trigger?.focus();
        },

        onEscape(e) {
            if (!this.open) return;
            e.preventDefault();
            e.stopPropagation();
            this.close();
        },

        onFocusOut(e) {
            if (!this.open) return;
            const to = e.relatedTarget;
            // Safari focuses neither a button nor a select on click, and reports
            // a null relatedTarget for it: that is not focus leaving.
            if (!to) return;
            if (this.$root.contains(to)) return;
            this.close(false);
        },

        /** The popover closed underneath us (a dialog going away, or the UA). */
        onToggle(e) {
            if (e.newState === 'closed' && this.open) this.close(false);
        },

        /* ── the month on show ── */

        syncView() {
            const selected = partsOf(this.state);
            const p = selected ?? partsOf(todayKey());
            this.view = { year: p.y, month: p.m };
            this.focusKey = selected ? this.state : todayKey();
        },

        /** After the month or year select moved: keep the focused day inside the new month. */
        viewChanged() {
            const p = partsOf(this.focusKey) ?? partsOf(todayKey());
            const { year, month } = this.view;
            this.focusKey = keyOf(year, month, Math.min(p.d, daysInMonth(year, month)));
        },

        shiftMonth(n) {
            const total = this.view.month + n;
            this.view = { year: this.view.year + Math.floor(total / 12), month: ((total % 12) + 12) % 12 };
            this.viewChanged();
        },

        /** 1900 to ten years out, widened to hold the month on show and the value picked (DateField.tsx). */
        years() {
            const now = new Date().getFullYear();
            const selected = partsOf(this.state)?.y;
            const from = Math.min(1900, this.view.year, selected ?? 1900);
            const to = Math.max(now + 10, this.view.year, selected ?? now);
            const out = [];
            for (let y = from; y <= to; y += 1) out.push(y);
            return out;
        },

        get viewLabel() {
            return MONTH_NAMES[this.view.month] + ' ' + this.view.year;
        },

        /** Six rows of seven: the month on show padded with its neighbours' days. */
        get weeks() {
            const { year, month } = this.view;
            const start = new Date(year, month, 1 - new Date(year, month, 1).getDay());
            const today = todayKey();
            const rows = [];
            for (let w = 0; w < 6; w += 1) {
                const row = [];
                for (let i = 0; i < 7; i += 1) {
                    const dt = new Date(start.getFullYear(), start.getMonth(), start.getDate() + w * 7 + i);
                    const key = keyOfDate(dt);
                    row.push({ key, day: dt.getDate(), inMonth: dt.getMonth() === month, today: key === today, label: labelOf(key) });
                }
                rows.push(row);
            }
            return rows;
        },

        /* ── picking ── */

        select(key) {
            this.pick(key);
            this.close();
        },

        pickToday() {
            this.select(todayKey());
        },

        clear() {
            this.state = '';
            this.text = '';
            this.close();
        },

        /* ── keyboard on the grid (APG date picker dialog) ── */

        onGridKeydown(e) {
            if (!e.target.closest('[data-day]')) return;
            const key = this.focusKey;
            let next;
            switch (e.key) {
                case 'ArrowLeft': next = addDays(key, -1); break;
                case 'ArrowRight': next = addDays(key, 1); break;
                case 'ArrowUp': next = addDays(key, -7); break;
                case 'ArrowDown': next = addDays(key, 7); break;
                case 'PageUp': next = addMonths(key, e.shiftKey ? -12 : -1); break;
                case 'PageDown': next = addMonths(key, e.shiftKey ? 12 : 1); break;
                case 'Home': next = addDays(key, -dayOfWeek(key)); break;
                case 'End': next = addDays(key, 6 - dayOfWeek(key)); break;
                default: return;
            }
            e.preventDefault();
            this.moveFocus(next);
        },

        moveFocus(key) {
            const p = partsOf(key);
            if (!p) return;
            this.focusKey = key;
            if (p.y !== this.view.year || p.m !== this.view.month) this.view = { year: p.y, month: p.m };
            this.$nextTick(() => this.focusCell(key));
        },

        focusCell(key) {
            this.$refs.panel?.querySelector('[data-day="' + key + '"]')?.focus();
        },

        /* ── the top-layer panel ── */

        /** Below the field, right-aligned; above it when there is no room below; never off screen. */
        reposition() {
            if (!this.native || !this.open) return;
            const panel = this.$refs.panel;
            if (!panel) return;
            const field = this.$root.getBoundingClientRect();
            const w = panel.offsetWidth;
            const h = panel.offsetHeight;
            const G = 8;
            const GAP = 4;
            const vw = window.innerWidth;
            const vh = window.innerHeight;
            const left = Math.max(G, Math.min(field.right - w, vw - w - G));
            let top = field.bottom + GAP;
            if (top + h > vh - G && field.top - GAP - h >= G) top = field.top - GAP - h;
            top = Math.max(G, Math.min(top, vh - h - G));
            panel.style.top = Math.round(top) + 'px';
            panel.style.left = Math.round(left) + 'px';
        },

        bindViewport() {
            if (this.onViewport) return;
            this.onViewport = () => this.reposition();
            // Capture: the page and every scrolling ancestor (a dialog's own
            // scroller included) move the field under a fixed panel.
            window.addEventListener('scroll', this.onViewport, { capture: true, passive: true });
            window.addEventListener('resize', this.onViewport, { passive: true });
        },

        unbindViewport() {
            if (!this.onViewport) return;
            window.removeEventListener('scroll', this.onViewport, { capture: true });
            window.removeEventListener('resize', this.onViewport);
            this.onViewport = null;
        },

        /* showPopover() throws when the panel is already showing or is no
           longer in the document, and `:popover-open` throws where the
           selector is unknown — both are "nothing to do", not errors. */
        showNative() {
            if (!this.native) return;
            const panel = this.$refs.panel;
            if (!panel) return;
            try {
                if (!panel.matches(':popover-open')) panel.showPopover();
            } catch (e) { /* see above */ }
        },

        hideNative() {
            if (!this.native) return;
            const panel = this.$refs.panel;
            if (!panel) return;
            try {
                if (panel.matches(':popover-open')) panel.hidePopover();
            } catch (e) { /* see above */ }
        },
    };
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('dateField', dateField);
});
