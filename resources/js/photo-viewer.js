/*
 * `photoViewer` runs the full-screen photo viewer Community and Chat share
 * (resources/views/components/social/lightbox.blade.php).
 *
 * Zoom with the wheel, the buttons, + and -, a double click, or on a phone a
 * double tap or two fingers. Once zoomed, drag to look around. Panning stops
 * at the photo's edges, so it never slides off the screen. The arrows, a
 * swipe, and the thumbnail strip move between photos. Escape closes it.
 *
 * It opens as a modal dialog, so it sits above any other dialog (a post's
 * edit history, say) and stays clickable there.
 */

const MIN = 1;
const MAX = 6;

// One press of a button or of + and -.
const STEP = 0.25;

// One wheel notch. Multiplied rather than added, so every notch feels the same at any zoom.
const WHEEL = 1.2;

// Where a double click or double tap takes you.
const DOUBLE = 2.5;

// How far a finger has to travel sideways before it counts as a swipe.
const SWIPE = 60;

export default function photoViewer() {
    return {
        open: false,
        images: [],
        index: 0,
        scale: 1,
        x: 0,
        y: 0,
        // Fingers or the mouse, by pointer id, while they're down.
        pointers: {},
        gesture: null,
        moved: false,
        lastTap: null,
        returnFocus: null,
        // The page's scroll lock before it opened, which may be another dialog's.
        scrollWas: '',
        // Off for photos that aren't saved yet, like the ones waiting in a post box.
        canDownload: true,

        get percent() {
            return Math.round(this.scale * 100);
        },

        get transform() {
            return `translate(${this.x}px, ${this.y}px) scale(${this.scale})`;
        },

        get download() {
            const url = this.images[this.index] ?? '';

            return url + (url.includes('?') ? '&' : '?') + 'download=1';
        },

        show(detail) {
            this.images = detail.images ?? [];
            this.canDownload = detail.download !== false;
            this.index = Math.min(Math.max(0, detail.index ?? 0), Math.max(0, this.images.length - 1));
            this.reset();
            this.open = this.images.length > 0;

            if (this.open) {
                this.returnFocus = document.activeElement;
                this.scrollWas = document.documentElement.style.overflow;
                document.documentElement.style.overflow = 'hidden';
                if (! this.$refs.layer.open) {
                    this.$refs.layer.showModal();
                }
                this.$nextTick(() => {
                    this.$refs.close?.focus();
                    this.reveal();
                });
            }
        },

        close() {
            if (! this.open) {
                return;
            }
            this.open = false;
            this.pointers = {};
            this.gesture = null;
            if (this.$refs.layer.open) {
                this.$refs.layer.close();
            }
            document.documentElement.style.overflow = this.scrollWas;
            this.returnFocus?.focus?.();
        },

        go(at) {
            this.index = at;
            this.reset();
            this.$nextTick(() => this.reveal());
        },

        step(by) {
            if (this.images.length > 1) {
                this.go((this.index + by + this.images.length) % this.images.length);
            }
        },

        reset() {
            this.scale = MIN;
            this.x = 0;
            this.y = 0;
        },

        // Keeps the current photo in view in the thumbnail strip.
        reveal() {
            this.$refs.strip?.children[this.index]?.scrollIntoView({ block: 'nearest', inline: 'center', behavior: 'smooth' });
        },

        // Zooms to `next` while keeping the point under (cx, cy) exactly where it is.
        zoomAt(next, cx, cy) {
            const target = Math.min(MAX, Math.max(MIN, next));

            if (target === MIN) {
                this.reset();

                return;
            }

            const rect = this.$refs.photo.getBoundingClientRect();
            // The box on screen already includes the pan, so adding it back gives
            // the distance from where the photo's centre sits unmoved.
            const dx = cx - (rect.left + rect.width / 2) + this.x;
            const dy = cy - (rect.top + rect.height / 2) + this.y;
            const k = target / this.scale;

            this.x = dx - k * (dx - this.x);
            this.y = dy - k * (dy - this.y);
            this.scale = target;
            this.clamp();
        },

        // The buttons and keys have no cursor to aim with, so they use the middle.
        zoomBy(by) {
            const stage = this.$refs.stage.getBoundingClientRect();
            this.zoomAt(this.scale + by, stage.left + stage.width / 2, stage.top + stage.height / 2);
        },

        toggleAt(cx, cy) {
            if (this.scale > MIN) {
                this.reset();
            } else {
                this.zoomAt(DOUBLE, cx, cy);
            }
        },

        wheel(event) {
            this.zoomAt(this.scale * (event.deltaY < 0 ? WHEEL : 1 / WHEEL), event.clientX, event.clientY);
        },

        // While the photo is bigger than the screen it has to cover it. While
        // it's smaller, it stays in the middle.
        clamp() {
            const photo = this.$refs.photo;
            const stage = this.$refs.stage;
            const spareX = Math.max(0, (photo.offsetWidth * this.scale - stage.clientWidth) / 2);
            const spareY = Math.max(0, (photo.offsetHeight * this.scale - stage.clientHeight) / 2);

            this.x = Math.min(spareX, Math.max(-spareX, this.x));
            this.y = Math.min(spareY, Math.max(-spareY, this.y));
        },

        down(event) {
            event.currentTarget.setPointerCapture?.(event.pointerId);
            this.pointers[event.pointerId] = { x: event.clientX, y: event.clientY };
            this.moved = false;

            const fingers = Object.values(this.pointers);
            if (fingers.length === 2) {
                const [a, b] = fingers;
                this.gesture = { kind: 'pinch', distance: Math.hypot(a.x - b.x, a.y - b.y) || 1, scale: this.scale };
            } else if (fingers.length === 1) {
                this.gesture = { kind: 'pan', fromX: event.clientX - this.x, fromY: event.clientY - this.y, startX: event.clientX, startY: event.clientY };
            }
        },

        move(event) {
            if (this.pointers[event.pointerId] === undefined || this.gesture === null) {
                return;
            }

            this.pointers[event.pointerId] = { x: event.clientX, y: event.clientY };

            if (this.gesture.kind === 'pinch') {
                const [a, b] = Object.values(this.pointers);
                if (a !== undefined && b !== undefined) {
                    this.zoomAt(this.gesture.scale * (Math.hypot(a.x - b.x, a.y - b.y) / this.gesture.distance), (a.x + b.x) / 2, (a.y + b.y) / 2);
                    this.moved = true;
                }

                return;
            }

            if (Math.abs(event.clientX - this.gesture.startX) + Math.abs(event.clientY - this.gesture.startY) > 4) {
                this.moved = true;
            }
            if (this.scale > MIN) {
                this.x = event.clientX - this.gesture.fromX;
                this.y = event.clientY - this.gesture.fromY;
                this.clamp();
            }
        },

        up(event) {
            const gesture = this.gesture;
            delete this.pointers[event.pointerId];
            const left = Object.values(this.pointers);

            // One finger lifted after a pinch: carry on panning with the other.
            if (gesture?.kind === 'pinch' && left.length === 1) {
                this.gesture = { kind: 'pan', fromX: left[0].x - this.x, fromY: left[0].y - this.y, startX: left[0].x, startY: left[0].y };

                return;
            }
            if (left.length > 0) {
                return;
            }

            this.gesture = null;

            if (gesture?.kind !== 'pan') {
                return;
            }

            // Not zoomed, a sideways swipe moves to the next or previous photo.
            const dx = event.clientX - gesture.startX;
            const dy = event.clientY - gesture.startY;
            if (this.scale === MIN && Math.abs(dx) > SWIPE && Math.abs(dx) > Math.abs(dy)) {
                this.step(dx < 0 ? 1 : -1);

                return;
            }

            // A phone has no double click, so two quick taps do the same.
            if (event.pointerType === 'touch' && ! this.moved) {
                const now = Date.now();
                if (this.lastTap !== null && now - this.lastTap.at < 300 && Math.hypot(event.clientX - this.lastTap.x, event.clientY - this.lastTap.y) < 30) {
                    this.toggleAt(event.clientX, event.clientY);
                    this.lastTap = null;
                } else {
                    this.lastTap = { at: now, x: event.clientX, y: event.clientY };
                }
            }
        },

        key(event) {
            if (! this.open) {
                return;
            }

            const actions = {
                Escape: () => this.close(),
                ArrowLeft: () => this.step(-1),
                ArrowRight: () => this.step(1),
                '+': () => this.zoomBy(STEP),
                '=': () => this.zoomBy(STEP),
                '-': () => this.zoomBy(-STEP),
                0: () => this.reset(),
            };

            if (actions[event.key] !== undefined) {
                event.preventDefault();
                actions[event.key]();
            }
        },
    };
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('photoViewer', photoViewer);
});
