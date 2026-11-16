import test from 'node:test';
import assert from 'node:assert/strict';

globalThis.document = {addEventListener() {}, documentElement: {clientWidth: 800, clientHeight: 600}};
const {placeCard, nearestIndex, overviewHint} = await import('../../resources/js/overview-charts.js');

const card = () => ({style: {}, offsetWidth: 160, offsetHeight: 60});

test('snaps the pointer to the nearest point, including both ends', () => {
    const rect = {left: 100, width: 300};
    assert.equal(nearestIndex(100, rect, 12), 0);
    assert.equal(nearestIndex(400, rect, 12), 11);
    assert.equal(nearestIndex(250, rect, 12), 6);
    assert.equal(nearestIndex(90, rect, 12), 0);
    assert.equal(nearestIndex(250, rect, 1), 0);
});

test('centres the card above its point and keeps it on screen', () => {
    const placed = card();
    placeCard(placed, {x: 400, top: 300, bottom: 300}, {width: 800, height: 600});
    assert.deepEqual(placed.style, {left: '320px', top: '230px'});
    placeCard(placed, {x: 790, top: 300, bottom: 300}, {width: 800, height: 600});
    assert.equal(placed.style.left, '632px');
});

test('drops the card below a point near the top of the screen', () => {
    const placed = card();
    placeCard(placed, {x: 400, top: 30, bottom: 50}, {width: 800, height: 600});
    assert.equal(placed.style.top, '60px');
});

test('keeps a clicked hint open until it is closed on purpose', () => {
    let open = false;
    const hint = {style: {}, offsetWidth: 160, offsetHeight: 40, matches: () => open, showPopover: () => { open = true; }, hidePopover: () => { open = false; }};
    const trigger = {getBoundingClientRect: () => ({left: 100, width: 20, top: 200, bottom: 220})};
    const state = Object.assign(overviewHint(), {$refs: {hint, trigger}});
    state.open();
    state.toggle();
    state.close();
    assert.equal(open, true, 'leaving a pinned hint keeps it');
    state.close(true);
    assert.equal(open, false, 'Escape or a click elsewhere closes it');
    state.open();
    state.close();
    assert.equal(open, false, 'an unpinned hint follows the pointer');
});
