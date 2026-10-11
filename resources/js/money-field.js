/*
 * `moneyField` — comma grouping while an amount is typed, for a bare <input>.
 *
 * The payroll workspace cannot use <x-kit.money-input>: that component wraps
 * a Flux input, and a Flux input's wrapper does not fit a grid cell two dozen
 * columns wide (see run-workspace.blade.php's fidelity notes). Nor can the
 * cells be `type="number"`, because a number input refuses to DISPLAY a comma
 * at all — the browser drops any character that is not part of a number. So
 * the cells are `type="text"` and this carries the formatting.
 *
 * WHAT LEAVES THIS COMPONENT IS ALWAYS COMMA-FREE. `raw` is the plain numeric
 * string ("1000", "1000.50") and is what the wire call must send; `text` is the
 * display ("1,000.00"). Sending `text` would reach RunWorkspace::asMoney(),
 * whose is_numeric() test rejects "1,000.00" and returns 0.0 — a deduction
 * silently becoming zero. asMoney strips commas defensively for the same
 * reason, but the client is what makes it never arise.
 *
 * The caret arithmetic is the fiddly part and is the same idea as
 * <x-kit.money-input>: count the significant characters left of the caret,
 * reformat, then walk the new string until that many have been passed. Without
 * it, typing into the middle of "1,234,567" throws the caret to the end every
 * time a comma is inserted or removed.
 */
const group = (int) => int.replace(/\B(?=(\d{3})+(?!\d))/g, ',');

const toDisplay = (raw) => {
    if (!raw) return '';
    const [int = '', dec] = String(raw).split('.');
    return dec === undefined ? group(int) : group(int) + '.' + dec;
};

/* Negatives are unrepresentable on purpose: every amount these cells hold is
   clamped with max(0.0, …) server-side, so a minus sign could only ever be a
   keystroke that silently did nothing. */
const toRaw = (text) => {
    const cleaned = String(text).replace(/,/g, '');
    return /^\d*(\.\d{0,2})?$/.test(cleaned) ? cleaned : null;
};

/*
 * `fallback` is the figure an EMPTIED cell stands for: the computed SSS or
 * tax, the computed NSD. Emptying such a cell sends '' — the server clears
 * the override and keeps using the computed figure — but when nothing on
 * the server changed (the cell held no override) Livewire re-renders the
 * input byte-identical and Alpine keeps its blank state, so the cell would
 * look like zero while the payslip deducts the computed amount.
 * `restoreIfEmpty()`, called right after the wire call, puts the computed
 * figure back on screen. An overridden cell passes no fallback: clearing it
 * changes its `wire:key`, so the server's re-render builds a fresh input from
 * the computed value instead.
 */
export default function moneyField(initial = '', fallback = null) {
    return {
        raw: '',
        text: '',

        init() {
            this.raw = toRaw(initial ?? '') ?? '';
            this.settle();
        },

        onInput(e) {
            const typed = e.target.value;
            const next = toRaw(typed);

            // An unacceptable keystroke restores what the field had, caret intact.
            if (next === null) {
                const el = e.target;
                const back = typed.length - (el.selectionStart ?? typed.length);
                el.value = this.text;
                const at = Math.max(0, this.text.length - back);
                el.setSelectionRange(at, at);
                return;
            }

            const caret = e.target.selectionStart ?? typed.length;
            const significant = typed.slice(0, caret).replace(/,/g, '').length;
            const display = toDisplay(next);

            this.raw = next;
            this.text = display;

            requestAnimationFrame(() => {
                const el = e.target;
                if (!el.isConnected) return;
                el.value = display;

                let seen = 0;
                let at = 0;
                while (at < display.length && seen < significant) {
                    if (display[at] !== ',') seen += 1;
                    at += 1;
                }
                el.setSelectionRange(at, at);
            });
        },

        restoreIfEmpty() {
            if (this.raw !== '' || fallback === null) return;
            this.raw = toRaw(String(fallback)) ?? '';
            this.settle();
        },

        /* `1,000` settles as `1,000.00`; a leftover that never became a number
           ("." alone) clears rather than settling as "NaN". */
        settle() {
            if (this.raw === '') {
                this.text = '';
                return;
            }

            const n = Number(this.raw);

            if (!Number.isFinite(n)) {
                this.raw = '';
                this.text = '';
                return;
            }

            this.raw = n.toFixed(2);
            this.text = toDisplay(this.raw);
        },
    };
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('moneyField', moneyField);
});
