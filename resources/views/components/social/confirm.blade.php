{{--
    The page's one "Are you sure?" dialog, used instead of the browser's own
    confirm box. A button asks it a question with a `social-confirm` event,
    and the action only runs on a yes:

        @php($ask = ['title' => 'Leave Book Club?', 'body' => 'You can join again whenever you like.',
                     'confirm' => 'Leave group', 'danger' => true, 'call' => ['leave', $group->id]])
        <flux:menu.item x-on:click="$dispatch('social-confirm', @js($ask))">Leave group</flux:menu.item>

    `call` is the page's Livewire action and what to pass it. The dialog opens
    straight away in the browser, and only a yes goes to the server.

    Why not wire:confirm: Livewire pins its question to the button itself and
    never lets go of it. When a redraw reuses that button for a different one,
    the old question comes along. That's how "Add people" once asked me to
    withdraw a request to join a different group.

    wire:ignore keeps Livewire's redraws away from it. Everything it shows is
    Alpine's, and the page's regular check would blank an open dialog.
--}}
<div
    wire:ignore
    x-data="{
        ask: { title: '', body: '', confirm: 'OK', danger: false, call: [] },
        open(question) {
            this.ask = { title: '', body: '', confirm: 'OK', danger: false, call: [], ...question };
            this.$flux.modal('social-confirm').show();
        },
        yes() {
            this.$flux.modal('social-confirm').close();
            const [method, ...args] = this.ask.call;
            if (method) this.$wire.$call(method, ...args);
        },
    }"
    x-on:social-confirm.window="open($event.detail)"
>
    <flux:modal
        name="social-confirm"
        class="w-full max-w-md"
        x-init="$el.querySelector('dialog')?.setAttribute('aria-labelledby', 'social-confirm-heading')"
    >
        <div class="space-y-4">
            <div class="space-y-2 pr-6">
                <flux:heading size="lg" id="social-confirm-heading"><span x-text="ask.title"></span></flux:heading>
                <flux:text x-show="ask.body !== ''"><span x-text="ask.body"></span></flux:text>
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="outline">Cancel</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" x-show="ask.danger" x-on:click="yes()"><span x-text="ask.confirm"></span></flux:button>
                <flux:button variant="primary" x-show="! ask.danger" x-on:click="yes()"><span x-text="ask.confirm"></span></flux:button>
            </div>
        </div>
    </flux:modal>
</div>
