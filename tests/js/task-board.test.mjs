import test from 'node:test';
import assert from 'node:assert/strict';
import {taskBoard} from '../../resources/js/task-board.js';

function fixture(move) {
    const lanes = Object.fromEntries(['PENDING', 'IN_PROGRESS', 'COMPLETED'].map(status => {
        const cards = [], count = {dataset: {total: status === 'PENDING' ? '1' : '0'}}, empty = {};
        const items = {
            isConnected: true, cards,
            insertBefore(card, next) {
                const old = card.parentElement?.cards;
                if (old) old.splice(old.indexOf(card), 1);
                cards.splice(next ? cards.indexOf(next) : cards.length, 0, card);
                card.parentElement = this;
            },
            prepend(card) { this.insertBefore(card, cards[0]); },
        };
        return [status, {items, count, empty, querySelector: selector => selector === '[data-board-count]' ? count : selector === '[data-board-empty]' ? empty : cards[0] ?? null}];
    }));
    const card = {dataset: {boardTask: 'task-1', taskStatus: 'PENDING'}, isConnected: true, nextSibling: null};
    lanes.PENDING.items.prepend(card);
    const root = {
        querySelectorAll: () => Object.values(lanes),
        querySelector(selector) {
            const lane = lanes[selector.match(/="([A-Z_]+)"/)[1]];
            return lane ? (selector.endsWith('[data-board-items]') ? lane.items : lane.count) : null;
        },
    };
    const board = Object.assign(taskBoard({moveBoardTask: move}), {$root: root});
    const start = () => board.start({currentTarget: card, dataTransfer: {setData() {}}, preventDefault() {}}, ['IN_PROGRESS'], true);
    return {board, lanes, card, start};
}

test('places a direct move immediately, updates lane counts and keeps it while awaiting the server', async () => {
    let complete;
    const {board, lanes, card, start} = fixture(() => new Promise(resolve => { complete = resolve; }));
    start();
    const pending = board.drop('IN_PROGRESS');
    assert.equal(card.parentElement, lanes.IN_PROGRESS.items);
    assert.equal(lanes.PENDING.count.textContent, 0);
    assert.equal(lanes.IN_PROGRESS.count.textContent, 1);
    assert.equal(lanes.PENDING.empty.hidden, false);
    assert.equal(lanes.IN_PROGRESS.empty.hidden, true);
    assert.equal(board.moving, true);
    lanes.PENDING.count.dataset.total = '0';
    lanes.IN_PROGRESS.count.dataset.total = '1';
    card.dataset.taskStatus = 'IN_PROGRESS';
    complete(true);
    await pending;
    assert.equal(card.parentElement, lanes.IN_PROGRESS.items);
    assert.equal(board.moving, false);
    assert.equal(board.announcement, 'Task moved.');
});

test('rolls a refused direct move back and restores counts', async () => {
    const {board, lanes, card, start} = fixture(async () => false);
    start();
    await board.drop('IN_PROGRESS');
    assert.equal(card.parentElement, lanes.PENDING.items);
    assert.equal(lanes.PENDING.count.textContent, '1');
    assert.equal(lanes.IN_PROGRESS.count.textContent, '0');
    assert.equal(board.moving, false);
});

test('rolls a transport failure back and reports the failed move', async () => {
    const notices = [];
    globalThis.window = {Flux: {toast: notice => notices.push(notice)}};
    try {
        const {board, lanes, card, start} = fixture(async () => { throw new Error('Offline'); });
        start();
        await board.drop('IN_PROGRESS');
        assert.equal(card.parentElement, lanes.PENDING.items);
        assert.equal(board.moving, false);
        assert.equal(notices[0].variant, 'danger');
    } finally { delete globalThis.window; }
});

test('keeps a newer authoritative status instead of restoring a stale original lane', async () => {
    const f = fixture(async () => {
        f.lanes.COMPLETED.items.prepend(f.card);
        f.card.dataset.taskStatus = 'COMPLETED';
        return false;
    });
    f.start();
    await f.board.drop('IN_PROGRESS');
    assert.equal(f.card.parentElement, f.lanes.COMPLETED.items);
});

test('leaves review decisions in their current lane and ignores same-lane drops', async () => {
    let calls = 0;
    const {board, lanes, card, start} = fixture(async () => { calls++; assert.equal(card.parentElement, lanes.PENDING.items); return false; });
    start();
    await board.drop('PENDING');
    assert.equal(calls, 0);
    start();
    await board.drop('COMPLETED');
    assert.equal(calls, 1);
    assert.equal(card.parentElement, lanes.PENDING.items);
});
