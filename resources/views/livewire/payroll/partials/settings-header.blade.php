{{--
    The head of every Payroll Settings page: where the reader is ("Payroll
    Settings" over the page's own name), one short line saying what the page
    is for, then the page switch.

    Expects:
        $activeView   the switch key of the page in view
        $title        the page's name
        $description  one short line

    The head is a size container: the switch under it draws its links or
    its dropdown by the room the head has, whatever the sidebar's state.

    THE HEAD HAS NO EMPLOYEE SEARCH: an employee's pay settings open from
    their row on the Employees page.
--}}
<div class="@container space-y-6" data-settings-head>
    <div class="min-w-0">
        <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Payroll Settings</p>
        <h1 class="text-2xl font-bold tracking-tight">{{ $title }}</h1>
        <p class="mt-1 text-sm text-muted-foreground">{{ $description }}</p>
    </div>

    @include('livewire.payroll.partials.settings-switch', ['activeView' => $activeView])
</div>
