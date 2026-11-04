{{-- Where one of an employee's pay figures comes from (EmployeePayProfile::FROM_*): the company settings, their employment status's override, or set for them alone. --}}
@if ($from === \App\Livewire\Records\Support\EmployeePayProfile::FROM_OWN)
    <span class="pill pill-primary">Own</span>
@elseif ($from === \App\Livewire\Records\Support\EmployeePayProfile::FROM_STATUS)
    <span class="pill pill-muted">{{ $status ?: 'Status' }} status</span>
@else
    <span class="pill pill-muted">Company</span>
@endif
