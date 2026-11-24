{{-- Ticking wall clock (LiveClock.tsx) — Alpine ticks, the server paints frame one.
     Both show the organization's own zone, whatever zone the device is set to. --}}
<div
    class="{{ $class }}"
    x-data="{ now: new Date(), zone: @js($zone) }"
    x-init="setInterval(() => now = new Date(), 1000)"
>
    <p
        class="num text-3xl font-bold leading-none tracking-tight"
        x-text="now.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', second: '2-digit', hour12: true, timeZone: zone })"
    >{{ $initialTime }}</p>
    <p
        class="mt-1 text-xs text-muted-foreground"
        x-text="now.toLocaleDateString('en-CA', { weekday: 'long', month: 'long', day: 'numeric', timeZone: zone })"
    >{{ $initialDate }}</p>
</div>
