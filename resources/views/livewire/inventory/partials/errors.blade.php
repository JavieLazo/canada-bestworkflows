{{-- Command validation remains visible even when its field names differ from a presentation draft. --}}
@if ($errors->any())
    <div class="rounded-lg border border-danger/30 bg-danger-soft p-3 text-sm text-danger" role="alert">
        @foreach ($errors->all() as $error)
            <p>{{ $error }}</p>
        @endforeach
    </div>
@endif
