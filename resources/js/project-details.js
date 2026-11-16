export function workProjectDetails() {
    return {
        resetScroll() {
            this.$nextTick(() => {
                if (this.$refs.body) this.$refs.body.scrollTop = 0;
            });
        },
        init() {
            const dialog = this.$el.querySelector('dialog');
            dialog.setAttribute('aria-labelledby', 'project-details-heading');
            dialog.addEventListener('cancel', event => {
                if (!this.$wire.editing && !this.$wire.projectStatusEditing) return;
                event.preventDefault();
                this.$wire.closeProjectDetails();
            });
            dialog.addEventListener('submit', () => this.resetScroll());
            this.$watch('$wire.editing', () => this.resetScroll());
        },
    };
}

document.addEventListener('alpine:init', () => window.Alpine.data('workProjectDetails', workProjectDetails));
