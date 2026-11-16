import test from 'node:test';
import assert from 'node:assert/strict';

globalThis.document = {addEventListener() {}};
const {workProjectDetails} = await import('../../resources/js/project-details.js');

function fixture() {
    const events = {};
    let watched, closeCalls = 0;
    const body = {scrollTop: 240};
    const dialog = {setAttribute() {}, addEventListener(name, callback) { events[name] = callback; }};
    const state = Object.assign(workProjectDetails(), {
        $el: {querySelector: () => dialog}, $refs: {body},
        $wire: {editing: false, projectStatusEditing: false, closeProjectDetails() { closeCalls++; }},
        $nextTick: callback => callback(), $watch: (key, callback) => { watched = callback; },
    });
    state.init();
    return {state, body, events, modeChanged: () => watched(), closeCalls: () => closeCalls};
}

test('leaves read-only Escape and backdrop dismissal native', () => {
    const f = fixture();
    let prevented = false;
    f.events.cancel({preventDefault() { prevented = true; }});
    assert.equal(prevented, false);
    assert.equal(f.closeCalls(), 0);
});

for (const mode of ['editing', 'projectStatusEditing']) {
    test(`asks the server before native dismissal during ${mode}`, () => {
        const f = fixture();
        f.state.$wire[mode] = true;
        let prevented = false;
        f.events.cancel({preventDefault() { prevented = true; }});
        assert.equal(prevented, true);
        assert.equal(f.closeCalls(), 1);
    });
}

test('returns to the start when switching between facts and editing', () => {
    const f = fixture();
    f.modeChanged();
    assert.equal(f.body.scrollTop, 0);
});

test('reveals the start of the form on submit so validation feedback is in view', () => {
    const f = fixture();
    f.events.submit();
    assert.equal(f.body.scrollTop, 0);
});

test('tolerates the body leaving the page during close', () => {
    const f = fixture();
    delete f.state.$refs.body;
    assert.doesNotThrow(() => f.modeChanged());
});
