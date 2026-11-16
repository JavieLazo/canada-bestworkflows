const DAY = 86_400_000;

function ordinal(key) {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(key)) throw new Error('Invalid calendar day');
    const value = Date.parse(`${key}T00:00:00Z`);
    if (!Number.isFinite(value) || new Date(value).toISOString().slice(0, 10) !== key) throw new Error('Invalid calendar day');
    return value / DAY;
}

function dateKey(day) {
    const date = new Date(day * DAY);
    if (date.getUTCFullYear() < 1 || date.getUTCFullYear() > 9999) throw new Error('Date outside calendar');
    return date.toISOString().slice(0, 10);
}

export function shiftTaskDates(start, due, mode, delta) {
    if (!Number.isInteger(delta) || !['move', 'start', 'due'].includes(mode)) throw new Error('Invalid date move');
    let nextStart = start;
    let nextDue = due;
    if (start && mode !== 'due') nextStart = dateKey(ordinal(start) + delta);
    if (due && mode !== 'start') nextDue = dateKey(ordinal(due) + delta);
    if (nextStart && nextDue && nextStart > nextDue) throw new Error('Start is after due date');
    return { start: nextStart, due: nextDue };
}

export function clippedDates(start, due, from, days) {
    const first = ordinal(start || due);
    const last = ordinal(due || start);
    const left = ordinal(from);
    return { offset: Math.max(0, Math.min(days - 1, first - left)), span: Math.max(1, Math.min(last, left + days - 1) - Math.max(first, left) + 1) };
}

export function taskTimeline(wire) {
    return {
        busy: false,
        editing: false,
        zooming: false,
        scales: [],
        scaleIndex: 2,
        wheelListener: null,
        wheelTarget: null,
        wheelDelta: 0,
        wheelTime: 0,
        previewLabel: '',
        active: null,
        blockClickUntil: 0,
        windowKey: null,
        moveListener: null,
        upListener: null,
        cancelListener: null,
        keyListener: null,
        init() {
            this.scales = JSON.parse(this.$root.dataset.scales);
            this.scaleIndex = this.scales.indexOf(Number(this.$root.dataset.days));
            this.wheelListener = event => {
                if (!event.ctrlKey && !event.metaKey) return;
                if (event.target.closest('.ppm-gantt-label')) return;
                event.preventDefault();
                if (event.timeStamp - this.wheelTime > 200) this.wheelDelta = 0;
                this.wheelTime = event.timeStamp;
                this.wheelDelta += event.deltaY;
                if (Math.abs(this.wheelDelta) < 40) return;
                this.changeZoom(this.wheelDelta < 0 ? 1 : -1, event.clientX);
                this.wheelDelta = 0;
            };
            this.$nextTick(() => {
                if (!this.$root.isConnected) return;
                this.wheelTarget = this.$refs.scroller;
                this.wheelTarget.addEventListener('wheel', this.wheelListener, { passive: false });
            });
            this.moveListener = event => this.move(event);
            this.upListener = event => this.finish(event);
            this.cancelListener = () => this.cancel();
            this.keyListener = event => { if (event.key === 'Escape' && this.active) { event.preventDefault(); this.cancel(); } };
            window.addEventListener('pointermove', this.moveListener);
            window.addEventListener('pointerup', this.upListener);
            window.addEventListener('pointercancel', this.cancelListener);
            window.addEventListener('keydown', this.keyListener);
            window.addEventListener('resize', this.cancelListener);
        },
        destroy() {
            this.wheelTarget?.removeEventListener('wheel', this.wheelListener);
            this.cancel();
            window.removeEventListener('pointermove', this.moveListener);
            window.removeEventListener('pointerup', this.upListener);
            window.removeEventListener('pointercancel', this.cancelListener);
            window.removeEventListener('keydown', this.keyListener);
            window.removeEventListener('resize', this.cancelListener);
        },
        checkWindow(from, scale) {
            const key = `${from}:${scale}`;
            if (this.windowKey !== key) { this.cancel(); this.editing = false; this.windowKey = key; this.scaleIndex = this.scales.indexOf(Number(scale)); }
        },
        allowed(bar, mode) {
            return this.editing && bar.dataset.editStart === '1' && (mode === 'start' || bar.dataset.editDue === '1' || (mode === 'move' && !bar.dataset.due));
        },
        begin(event, bar, mode) {
            if (event.pointerType !== 'mouse' || event.button !== 0 || this.busy || !this.allowed(bar, mode)) return;
            this.cancel();
            this.active = { bar, capture: event.currentTarget, mode, pointer: event.pointerId, x: event.clientX, scroll: this.$refs.scroller.scrollLeft, delta: 0, start: bar.dataset.start, due: bar.dataset.due, moved: false };
            this.active.capture.setPointerCapture(event.pointerId);
        },
        move(event) {
            const active = this.active;
            if (!active || event.pointerId !== active.pointer) return;
            const pixels = event.clientX - active.x + this.$refs.scroller.scrollLeft - active.scroll;
            if (!active.moved && Math.abs(pixels) < 5) return;
            active.moved = true;
            event.preventDefault();
            const track = active.bar.closest('[data-track]');
            const cell = track.getBoundingClientRect().width / Number(this.$root.dataset.days);
            let delta = Math.round(pixels / cell);
            if (active.start && active.due) {
                if (active.mode === 'start') delta = Math.min(delta, ordinal(active.due) - ordinal(active.start));
                if (active.mode === 'due') delta = Math.max(delta, ordinal(active.start) - ordinal(active.due));
            }
            try {
                const dates = shiftTaskDates(active.start, active.due, active.mode, delta);
                active.delta = delta;
                this.preview(active.bar, dates, cell);
            } catch { /* Keep the last valid date pair while a handle crosses its edge. */ }
        },
        preview(bar, dates, cell) {
            const clip = clippedDates(dates.start, dates.due, this.$root.dataset.from, Number(this.$root.dataset.days));
            bar.dataset.preview = '';
            bar.style.setProperty('--ppm-preview-left', `${clip.offset * cell}px`);
            bar.style.setProperty('--ppm-preview-width', `${clip.span * cell}px`);
            this.previewLabel = [dates.start ? `Start ${dates.start}` : '', dates.due ? `Due ${dates.due}` : ''].filter(Boolean).join(' · ');
        },
        clear(bar) {
            if (!bar) return;
            delete bar.dataset.preview;
            bar.style.removeProperty('--ppm-preview-left');
            bar.style.removeProperty('--ppm-preview-width');
            this.previewLabel = '';
        },
        cancel() {
            const active = this.active;
            if (!active) return;
            this.active = null;
            this.clear(active.bar);
            if (active.capture.hasPointerCapture(active.pointer)) active.capture.releasePointerCapture(active.pointer);
            if (active.moved) this.blockClickUntil = performance.now() + 150;
        },
        async finish(event) {
            const active = this.active;
            if (!active || event.pointerId !== active.pointer) return;
            this.move(event);
            this.active = null;
            if (active.capture.hasPointerCapture(active.pointer)) active.capture.releasePointerCapture(active.pointer);
            if (active.moved) this.blockClickUntil = performance.now() + 150;
            if (!active.moved || active.delta === 0) { this.clear(active.bar); return; }
            await this.write(active.bar, active.start, active.due, active.mode, active.delta);
        },
        async nudge(event, bar, mode, direction) {
            if (this.busy || this.active || !this.allowed(bar, mode)) return;
            const delta = direction * (event.shiftKey ? 7 : 1);
            await this.write(bar, bar.dataset.start, bar.dataset.due, mode, delta);
        },
        async write(bar, start, due, mode, delta) {
            let dates;
            try { dates = shiftTaskDates(start, due, mode, delta); } catch { this.clear(bar); return; }
            if (dates.start === start && dates.due === due) { this.clear(bar); return; }
            const track = bar.closest('[data-track]');
            if (track) this.preview(bar, dates, track.getBoundingClientRect().width / Number(this.$root.dataset.days));
            this.busy = true;
            try {
                await wire.moveSchedule(bar.dataset.ganttBar, dates.start, dates.due, start, due);
            } catch {
                if (this.$root.isConnected) await wire.$refresh().catch(() => {});
            } finally {
                this.clear(bar);
                this.busy = false;
            }
        },
        openTask(event, id) {
            if (event.detail && performance.now() < this.blockClickUntil) return;
            wire.openTask(id);
        },
        openEditor(event, id) {
            if (event.detail && performance.now() < this.blockClickUntil) return;
            wire.openSchedule(id);
        },
        async changeZoom(direction, clientX = null) {
            if (this.zooming || this.busy || (direction === 0 && this.scaleIndex === 2)) return;
            if ((direction > 0 && this.scaleIndex <= 0) || (direction < 0 && this.scaleIndex >= this.scales.length - 1)) return;
            this.cancel();
            this.editing = false;
            const scroller = this.$refs.scroller;
            const track = this.$root.querySelector('[data-track]');
            const label = this.$root.querySelector('.ppm-gantt-label')?.getBoundingClientRect().width || 0;
            const point = clientX === null ? (scroller.clientWidth + label) / 2 : clientX - scroller.getBoundingClientRect().left;
            const anchor = Math.max(0, point - label);
            const days = Number(this.$root.dataset.days);
            const cell = track ? track.getBoundingClientRect().width / days : 28;
            const offset = Math.max(0, Math.min(days - 1, Math.floor((scroller.scrollLeft + anchor) / cell)));
            const date = dateKey(ordinal(this.$root.dataset.from) + offset);
            this.zooming = true;
            try {
                await wire.zoomTimeline(direction, date);
                this.$nextTick(() => requestAnimationFrame(() => {
                    const nextTrack = this.$root.querySelector('[data-track]');
                    if (!nextTrack) return;
                    const nextCell = nextTrack.getBoundingClientRect().width / Number(this.$root.dataset.days);
                    this.$refs.scroller.scrollLeft = Math.max(0, (ordinal(date) - ordinal(this.$root.dataset.from) + 0.5) * nextCell - anchor);
                }));
            } finally {
                this.zooming = false;
            }
        },
        async resetZoom() {
            await this.changeZoom(0);
        },
        focusToday() {
            this.$nextTick(() => requestAnimationFrame(() => {
                const index = Number(this.$root.dataset.today);
                const track = this.$root.querySelector('[data-track]');
                const label = this.$root.querySelector('.ppm-gantt-label');
                if (!track || index < 0) return;
                const cell = track.getBoundingClientRect().width / Number(this.$root.dataset.days);
                const space = this.$refs.scroller.clientWidth - (label?.getBoundingClientRect().width || 0);
                this.$refs.scroller.scrollLeft = Math.max(0, (index + 0.5) * cell - space / 2);
            }));
        },
    };
}

if (typeof document !== 'undefined') {
    document.addEventListener('alpine:init', () => window.Alpine.data('taskTimeline', taskTimeline));
}
