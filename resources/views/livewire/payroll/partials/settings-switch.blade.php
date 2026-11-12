{{--
    The Payroll Settings pages share one switch (SettingsPages), as Employee
    Records' views do; ordinary links keep each page's own address. With
    room it is the row of links; without room it is one dropdown that opens
    the page picked, as the employee record's sections are below a phone's
    width. Which of the two shows follows the head's own width (its size
    container), never the window's, so an open sidebar cannot wrap the row
    onto a second line.

    Pay Schedules is NOT offered: payroll is only created here, so the
    company's one schedule works unseen and its page stays reachable by its
    address alone. Adjustment Categories is NOT offered either: the
    Adjustments screen it serves is off the Payroll menu.
--}}
@php
    use App\Livewire\Payroll\Support\SettingsPages;

    $views = SettingsPages::all();
@endphp
<nav aria-label="Payroll Settings pages" class="print:hidden">
    <div class="hidden max-w-full items-center gap-1 rounded-lg bg-muted p-1 text-sm text-muted-foreground @[56rem]:inline-flex">
        @foreach ($views as $key => $view)
            <a
                href="{{ route($view['route']) }}"
                wire:navigate
                @if ($activeView === $key) aria-current="page" @endif
                class="inline-flex items-center justify-center whitespace-nowrap rounded-md px-3 py-1.5 font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary {{ $activeView === $key ? 'bg-card text-foreground shadow-sm' : 'hover:text-foreground' }}"
            >
                {{ $view['label'] }}
            </a>
        @endforeach
    </div>

    <div class="relative w-full sm:max-w-xs @[56rem]:hidden">
        <x-kit.select aria-label="Payroll Settings page" x-on:change="Livewire.navigate($event.target.value)">
            @foreach ($views as $key => $view)
                <flux:select.option value="{{ route($view['route']) }}" :selected="$activeView === $key">{{ $view['label'] }}</flux:select.option>
            @endforeach
        </x-kit.select>
    </div>
</nav>
