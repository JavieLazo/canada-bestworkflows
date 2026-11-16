import test from 'node:test';
import assert from 'node:assert/strict';

globalThis.document = {addEventListener() {}};
const {workPopover} = await import('../../resources/js/work-popover.js');

function fixture() {
    globalThis.window = {innerWidth: 800, innerHeight: 600};
    document.documentElement = {clientWidth: 800, clientHeight: 600};
    let callback, disconnected = false;
    globalThis.ResizeObserver = class {
        constructor(fn) { callback = fn; }
        observe() {}
        disconnect() { disconnected = true; }
    };
    const anchor = {left: 500, top: 100, bottom: 132};
    const panel = {style: {}, offsetWidth: 240, offsetHeight: 200, matches: () => true, querySelectorAll: () => []};
    const state = Object.assign(workPopover(), {$refs: {panel, trigger: {getBoundingClientRect: () => anchor}}, $nextTick: fn => fn()});
    return {state, panel, anchor, resized: () => callback(), disconnected: () => disconnected};
}

test('keeps an open popup attached when content changes without another opening event', () => {
    const {state, panel, anchor, resized} = fixture();
    state.init(); state.open = true; state.place();
    assert.deepEqual(panel.style, {left: '500px', top: '140px'});
    panel.offsetHeight = 400;
    anchor.left = 560;
    resized();
    assert.equal(panel.style.left, '544px');
    assert.equal(panel.style.top, '140px');
});

test('flips above a low trigger and stays inside a narrow viewport', () => {
    const {state, panel, anchor} = fixture();
    window.innerWidth = 320;
    document.documentElement.clientWidth = 320;
    anchor.left = 270; anchor.top = 480; anchor.bottom = 512;
    state.place();
    assert.equal(panel.style.left, '64px');
    assert.equal(panel.style.top, '272px');
});

test('clamps oversized content to the viewport and leaves closed panels alone', () => {
    const {state, panel, resized} = fixture();
    state.init(); panel.offsetHeight = 900;
    resized();
    assert.deepEqual(panel.style, {});
    state.open = true; resized();
    assert.equal(panel.style.top, '16px');
});

test('uses the visible viewport when a native overlay widens the layout canvas', () => {
    const {state, panel, anchor} = fixture();
    window.innerWidth = 1118; window.innerHeight = 1989;
    document.documentElement = {clientWidth: 375, clientHeight: 667};
    panel.offsetWidth = 320;
    anchor.left = 270; anchor.top = 600; anchor.bottom = 632;
    state.place();
    assert.equal(panel.style.left, '39px');
    assert.equal(panel.style.top, '392px');
});

test('focuses a visible control instead of an input inside a closed nested popup', () => {
    const {state, panel} = fixture();
    let focus = '';
    panel.querySelectorAll = () => [
        {getClientRects: () => [], focus: () => { focus = 'hidden'; }},
        {getClientRects: () => [{}], focus: () => { focus = 'visible'; }},
    ];
    state.opening({newState: 'open'});
    assert.equal(focus, 'visible');
    assert.equal(panel.style.visibility, '');
});

test('cleans up resize observation when a popup leaves the page', () => {
    const {state, disconnected} = fixture();
    state.init(); state.destroy();
    assert.equal(disconnected(), true);
});
