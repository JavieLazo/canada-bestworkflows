export function workPopover() {
    return {
        open: false,
        observer: null,
        init() {
            this.observer = new ResizeObserver(() => { if (this.open) this.place(); });
            this.observer.observe(this.$refs.panel);
            this.observer.observe(this.$refs.trigger);
        },
        destroy() { this.observer?.disconnect(); },
        place() {
            const panel = this.$refs.panel, button = this.$refs.trigger.getBoundingClientRect();
            const width = document.documentElement.clientWidth, height = document.documentElement.clientHeight;
            const below = button.bottom + 8;
            panel.style.left = Math.max(16, Math.min(button.left, width - panel.offsetWidth - 16)) + 'px';
            panel.style.top = Math.max(16, Math.min(below + panel.offsetHeight <= height - 16 ? below : button.top - panel.offsetHeight - 8, height - panel.offsetHeight - 16)) + 'px';
        },
        opening(event) {
            if (event.newState !== 'open') return;
            this.$refs.panel.style.visibility = 'hidden';
            this.$nextTick(() => {
                if (!this.$refs.panel.matches(':popover-open')) return;
                this.place();
                this.$refs.panel.style.visibility = '';
                Array.from(this.$refs.panel.querySelectorAll('input:not([type=hidden]), [role=combobox], button'))
                    .find(element => !element.disabled && element.getClientRects().length > 0)?.focus();
            });
        },
    };
}

document.addEventListener('alpine:init', () => window.Alpine.data('workPopover', workPopover));
