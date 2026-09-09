{{-- Ticking wall clock (LiveClock.tsx) — Alpine ticks, the server paints frame one. --}}
<div
    class="{{ $class }}"
    x-data="{ now: new Date() }"
    x-init="setInterval(() => now = new Date(), 1000)"
>
    <p
        class="num text-3xl font-bold leading-none tracking-tight"
        x-text="now.toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit', second: '2-digit', hour12: true })"
    >{{ $initialTime }}</p>
    <p
        class="mt-1 text-xs text-muted-foreground"
        x-text="now.toLocaleDateString('en-PH', { weekday: 'long', month: 'long', day: 'numeric' })"
    >{{ $initialDate }}</p>
</div>
