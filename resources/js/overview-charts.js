// Hover cards for the overview. Each chart fills one card from its own
// numbers, so sliding along a line never waits or flickers, and the card sits
// in the top layer so a card's rounded clipping never cuts it off.

const EDGE = 8;
const GAP = 10;

// Above the anchor, centred, and below it only when there's no room above.
export function placeCard(card, anchor, view = {width: document.documentElement.clientWidth, height: document.documentElement.clientHeight}) {
    const width = card.offsetWidth, height = card.offsetHeight;
    const left = Math.max(EDGE, Math.min(anchor.x - width / 2, view.width - width - EDGE));
    let top = anchor.top - height - GAP;
    if (top < EDGE) top = anchor.bottom + GAP;
    top = Math.max(EDGE, Math.min(top, view.height - height - EDGE));
    card.style.left = Math.round(left) + 'px';
    card.style.top = Math.round(top) + 'px';
}

// The point nearest the pointer, so nobody has to land on a 2px line.
export function nearestIndex(clientX, rect, count) {
    if (count < 2 || rect.width <= 0) return 0;
    return Math.max(0, Math.min(count - 1, Math.round((clientX - rect.left) / rect.width * (count - 1))));
}

function showCard(card) {
    if (!card.matches(':popover-open')) card.showPopover();
}

function hideCard(card) {
    if (card.matches(':popover-open')) card.hidePopover();
}

export function overviewChart(points) {
    return {
        points,
        active: null,
        target: null,
        get point() { return this.active === null ? null : this.points[this.active] ?? null; },
        show(index, target = null) {
            this.active = index;
            this.target = target;
            showCard(this.$refs.card);
            this.$nextTick(() => placeCard(this.$refs.card, this.anchor()));
        },
        hide() {
            this.active = null;
            hideCard(this.$refs.card);
        },
        track(event) {
            this.show(nearestIndex(event.clientX, this.$refs.plot.getBoundingClientRect(), this.points.length));
        },
        step(by) {
            const last = this.points.length - 1;
            const from = this.active ?? (by < 0 ? last + 1 : -1);
            const index = Math.max(0, Math.min(last, from + by));
            this.show(index, this.$refs.plot.querySelector(`[data-index="${index}"]`));
        },
        anchor() {
            if (this.target) {
                const box = this.target.getBoundingClientRect();
                return {x: box.left + box.width / 2, top: box.top, bottom: box.bottom};
            }
            const box = this.$refs.plot.getBoundingClientRect(), point = this.point;
            const ys = [point.y, point.py].filter(value => value !== null);
            const top = box.top + box.height * Math.min(...ys, 100) / 100;
            return {x: box.left + box.width * point.x / 100, top, bottom: box.top + box.height * Math.max(...ys, 0) / 100};
        },
    };
}

// The one-line explanations. Hover or focus shows them; a click or tap pins
// one open until Escape, a second click or a click elsewhere.
export function overviewHint() {
    return {
        pinned: false,
        open() {
            showCard(this.$refs.hint);
            const box = this.$refs.trigger.getBoundingClientRect();
            placeCard(this.$refs.hint, {x: box.left + box.width / 2, top: box.top, bottom: box.bottom});
        },
        close(force = false) {
            if (this.pinned && !force) return;
            this.pinned = false;
            hideCard(this.$refs.hint);
        },
        toggle() {
            this.pinned = !this.pinned;
            if (this.pinned) this.open(); else this.close(true);
        },
    };
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('overviewChart', overviewChart);
    window.Alpine.data('overviewHint', overviewHint);
});
