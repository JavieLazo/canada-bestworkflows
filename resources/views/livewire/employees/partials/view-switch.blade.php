{{-- Separate routes share a view switch; ordinary links preserve navigation and URLs. --}}
<nav aria-label="Employee Records views" class="inline-flex max-w-full flex-wrap items-center gap-1 rounded-lg bg-muted p-1 text-sm text-muted-foreground">
    <a
        href="{{ route('employees.index') }}"
        @if ($activeView === 'employees') aria-current="page" @endif
        class="inline-flex items-center justify-center rounded-md px-3 py-1.5 font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary {{ $activeView === 'employees' ? 'bg-card text-foreground shadow-sm' : 'hover:text-foreground' }}"
    >
        Employees
    </a>
    @if (app(\App\Support\Access::class)->mayReadAllData(\App\Domain\Enums\ModuleCode::EMPLOYEES))
        <a href="{{ route('employees.change-requests') }}"
            @if ($activeView === 'requests') aria-current="page" @endif
            class="inline-flex items-center justify-center rounded-md px-3 py-1.5 font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary {{ $activeView === 'requests' ? 'bg-card text-foreground shadow-sm' : 'hover:text-foreground' }}">
            Change Requests
        </a>
    @endif
    <a
        href="{{ route('employees.history') }}"
        @if ($activeView === 'history') aria-current="page" @endif
        class="inline-flex items-center justify-center rounded-md px-3 py-1.5 font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary {{ $activeView === 'history' ? 'bg-card text-foreground shadow-sm' : 'hover:text-foreground' }}"
    >
        Employment History
    </a>
    <a
        href="{{ route('employees.organization') }}"
        @if ($activeView === 'organization') aria-current="page" @endif
        class="inline-flex items-center justify-center rounded-md px-3 py-1.5 font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary {{ $activeView === 'organization' ? 'bg-card text-foreground shadow-sm' : 'hover:text-foreground' }}"
    >
        Organization
    </a>
</nav>
