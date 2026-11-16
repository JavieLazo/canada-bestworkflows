const CONTROL = 'a, button, input, select, textarea, summary, [role="button"], [role="combobox"], [contenteditable="true"], [draggable="true"]';

export function canPan(target) {
    return !target.closest(CONTROL) || target.closest('[data-pan-open]') !== null;
}

export function panPosition(start, origin, point) {
    return { left: start.left + origin.x - point.x, top: start.top + origin.y - point.y };
}

if (typeof document !== 'undefined') {
    let active = null;
    let blocked = null;
    const finish = () => {
        if (!active) return;
        const { surface, pointer, moved, selection } = active;
        active = null;
        delete surface.dataset.panning;
        surface.style.userSelect = selection;
        if (surface.hasPointerCapture(pointer)) surface.releasePointerCapture(pointer);
        if (moved) blocked = { surface, until: performance.now() + 200 };
    };
    document.addEventListener('pointerdown', event => {
        if (event.pointerType !== 'mouse' || event.button !== 0 || !(event.target instanceof Element)) return;
        const surface = event.target.closest('[data-work-pan]');
        if (!surface || !canPan(event.target)) return;
        if (surface.scrollWidth <= surface.clientWidth && surface.scrollHeight <= surface.clientHeight) return;
        const bounds = surface.getBoundingClientRect();
        if (event.clientX >= bounds.left + surface.clientWidth || event.clientY >= bounds.top + surface.clientHeight) return;
        finish();
        active = { surface, pointer: event.pointerId, origin: { x: event.clientX, y: event.clientY }, start: { left: surface.scrollLeft, top: surface.scrollTop }, moved: false, selection: surface.style.userSelect };
    });
    window.addEventListener('pointermove', event => {
        if (!active || event.pointerId !== active.pointer) return;
        if (!active.surface.isConnected) { finish(); return; }
        if (!active.moved && Math.hypot(event.clientX - active.origin.x, event.clientY - active.origin.y) < 5) return;
        active.moved = true;
        active.surface.dataset.panning = '';
        active.surface.style.userSelect = 'none';
        active.surface.setPointerCapture(event.pointerId);
        event.preventDefault();
        const position = panPosition(active.start, active.origin, { x: event.clientX, y: event.clientY });
        active.surface.scrollLeft = position.left;
        active.surface.scrollTop = position.top;
    }, { passive: false });
    window.addEventListener('pointerup', finish);
    window.addEventListener('pointercancel', finish);
    window.addEventListener('blur', finish);
    window.addEventListener('keydown', event => { if (event.key === 'Escape') finish(); });
    document.addEventListener('click', event => {
        if (event.detail && blocked && performance.now() < blocked.until && blocked.surface.contains(event.target)) {
            blocked = null;
            event.preventDefault();
            event.stopImmediatePropagation();
        }
    }, true);
}
