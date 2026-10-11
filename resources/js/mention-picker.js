/*
 * `mentionPicker` is the list that opens when you type @ in a post, a
 * comment or a chat message, the way Slack and Messenger do it.
 *
 * It lists the people who can read what you're writing, the groups you may
 * name, and @everyone when you may use it, narrowing as you type. Up and
 * down move through it, Enter or Tab picks, and Escape closes it. Picking
 * writes "@Full Name " into the box. The server turns names into mentions
 * when the post is saved, so a name typed out by hand works the same way.
 *
 * The list comes from the page (a Livewire call, `$wire.mentionOptions()`),
 * once per audience, the first time @ is typed there.
 *
 * Usage, around the box it serves:
 *
 *     <div x-data="mentionPicker({ audience: 'group-id' })" class="relative">
 *
 * `audience` can also be a function, for a box whose audience can change.
 *         <textarea ...></textarea>
 *         <x-social.mention-list />
 *     </div>
 */

const LIMIT = 8;

// One list per audience for the life of the page, shared by every box.
const loaded = new Map();

const initials = (name) => {
    const words = name.trim().split(/\s+/).filter(Boolean);
    return ((words[0]?.[0] ?? '') + (words.length > 1 ? words[words.length - 1][0] : '')).toUpperCase();
};

export default function mentionPicker({ audience = '', method = 'mentionOptions' } = {}) {
    return {
        open: false,
        items: [],
        active: 0,
        start: -1,
        field: null,

        init() {
            this.field = this.$el.querySelector('textarea, input[type="text"]');
            if (!this.field) {
                return;
            }

            this.field.addEventListener('input', () => this.scan());
            this.field.addEventListener('click', () => this.scan());
            this.field.addEventListener('blur', () => setTimeout(() => (this.open = false), 150));
            // Capture, so a pick with Enter never also sends the message.
            this.field.addEventListener('keydown', (event) => this.key(event), true);
        },

        async options() {
            // The post box's audience can change, so it may be read fresh each time.
            const target = typeof audience === 'function' ? audience() : audience;
            const key = method + ':' + target;
            if (!loaded.has(key)) {
                loaded.set(key, this.$wire.call(method, target || null).catch(() => {
                    loaded.delete(key);
                    return { people: [], groups: [], everyone: false };
                }));
            }

            return loaded.get(key);
        },

        async scan() {
            const el = this.field;
            const caret = el.selectionStart ?? el.value.length;
            const before = el.value.slice(0, caret);
            const at = before.lastIndexOf('@');

            // An @ that starts a word, followed by at most a few words on one line.
            const query = at < 0 ? null : before.slice(at + 1);
            if (at < 0 || (at > 0 && !/[\s([{"'“]/.test(before[at - 1])) || query.length > 40 || /\n|\s{2}/.test(query)) {
                this.open = false;
                return;
            }

            const all = await this.options();
            const q = query.toLowerCase();
            const fits = (name) => {
                const lower = name.toLowerCase();
                return q === '' || lower.startsWith(q) || lower.split(/\s+/).some((word) => word.startsWith(q));
            };

            const items = [];
            if (all.everyone && fits('everyone')) {
                items.push({ kind: 'everyone', key: 'everyone', name: 'everyone', sub: 'Notify everyone who can see this', initials: '@' });
            }
            for (const person of all.people ?? []) {
                if (fits(person.name)) {
                    items.push({ kind: 'person', key: 'p' + person.id, name: person.name, sub: person.sub, photo: person.photo, initials: initials(person.name) });
                }
            }
            for (const group of all.groups ?? []) {
                if (fits(group.name)) {
                    items.push({ kind: 'group', key: 'g' + group.id, name: group.name, sub: 'Group, notifies its members', initials: initials(group.name) });
                }
            }

            this.start = at;
            this.items = items.slice(0, LIMIT);
            this.active = 0;
            this.open = this.items.length > 0;
        },

        key(event) {
            if (!this.open) {
                return;
            }

            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                event.stopImmediatePropagation();
                const step = event.key === 'ArrowDown' ? 1 : -1;
                this.active = (this.active + step + this.items.length) % this.items.length;
            } else if ((event.key === 'Enter' || event.key === 'Tab') && !event.isComposing) {
                event.preventDefault();
                event.stopImmediatePropagation();
                this.choose(this.items[this.active]);
            } else if (event.key === 'Escape') {
                event.preventDefault();
                event.stopImmediatePropagation();
                this.open = false;
            }
        },

        choose(item) {
            if (!item) {
                return;
            }

            const el = this.field;
            const caret = el.selectionStart ?? el.value.length;
            const text = '@' + item.name + ' ';
            el.value = el.value.slice(0, this.start) + text + el.value.slice(caret);
            const at = this.start + text.length;
            el.focus();
            el.setSelectionRange(at, at);
            // So wire:model and the box's own auto-grow hear about it.
            el.dispatchEvent(new Event('input', { bubbles: true }));
            this.open = false;
        },
    };
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('mentionPicker', mentionPicker);
});
