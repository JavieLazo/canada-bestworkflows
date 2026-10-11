{{--
    Chat's own frame: the app's usual sidebar and header, with the page
    filling everything under the header edge to edge, the way a messenger
    does. The app's other pages sit in a centred column with padding (their
    layouts.app), which suits a form or a table but leaves a chat floating in
    the middle of the screen.
--}}
<x-layouts::app.sidebar :title="$title ?? null">
    <flux:main class="!p-0 min-w-0">
        {{ $slot }}
    </flux:main>
</x-layouts::app.sidebar>
