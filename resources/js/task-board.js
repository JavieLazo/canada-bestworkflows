export function taskBoard(wire) {
    return {
        dragged: null,
        over: null,
        moving: false,
        announcement: '',
        start(event, moves, allowed) {
            if (!allowed || this.moving) { event.preventDefault(); return; }
            const card = event.currentTarget;
            this.dragged = { id: card.dataset.boardTask, from: card.dataset.taskStatus, card, moves };
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', this.dragged.id);
        },
        syncLanes() {
            this.$root.querySelectorAll('[data-board-status]').forEach(lane => {
                const empty = lane.querySelector('[data-board-empty]');
                if (empty) empty.hidden = lane.querySelector('[data-board-task]') !== null;
                const count = lane.querySelector('[data-board-count]');
                count.textContent = count.dataset.total;
            });
        },
        async drop(status) {
            const task = this.dragged;
            this.dragged = null;
            this.over = null;
            if (!task || task.from === status || this.moving) return;
            const destination = this.$root.querySelector(`[data-board-status="${status}"] [data-board-items]`);
            if (!destination) return;
            const origin = task.card.parentElement;
            const next = task.card.nextSibling;
            let saved = false;
            this.moving = true;
            this.announcement = '';
            if (task.moves.includes(status)) {
                destination.prepend(task.card);
                this.syncLanes();
                for (const [value, delta] of [[task.from, -1], [status, 1]]) {
                    const count = this.$root.querySelector(`[data-board-status="${value}"] [data-board-count]`);
                    count.textContent = Number(count.dataset.total) + delta;
                }
            }
            try {
                saved = await wire.moveBoardTask(task.id, status, task.from) === true;
                this.announcement = saved ? 'Task moved.' : 'No status change saved.';
            } catch {
                window.Flux?.toast({ variant: 'danger', text: 'The task could not be moved. Please try again.' });
            } finally {
                // A refused request may already have rendered a newer authoritative status.
                if (!saved && origin.isConnected && task.card.isConnected && task.card.parentElement === destination && task.card.dataset.taskStatus === task.from) {
                    origin.insertBefore(task.card, next?.parentElement === origin ? next : null);
                }
                this.syncLanes();
                this.moving = false;
            }
        },
    };
}

if (typeof document !== 'undefined') {
    document.addEventListener('alpine:init', () => window.Alpine.data('taskBoard', taskBoard));
}
