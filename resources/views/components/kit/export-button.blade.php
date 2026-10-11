{{--
    Export CSV — the button a table header carries to its export route
    (App\Http\Controllers\TableExportController, or a module's own export
    controller), on every table that has a CSV.

    Usage:
        <x-kit.export-button :href="route('records.sites.export', ['q' => $search, 'sort' => $sortKey, 'dir' => $sortDir])" />

    Pass the table's CURRENT search, filters and sort in the link: the
    export reads through the same owner query as the table, so the file
    holds the rows on screen, in the same order, across every page. A plain
    link, not wire:click — the response is an attachment, so the page stays
    put and nothing goes through Livewire.

    Solid green on every table: a primary button recolored through the
    `success` token (`hris-button-success` in app.css). The four Export CSV
    buttons that cannot use this component — the projects list and a
    project's tasks (their own icon set) and the two payroll grids (a
    wire:click that streams the file) — carry the same class, so they match.
--}}
@props(['href', 'label' => 'Export CSV'])

<flux:button variant="primary" icon="arrow-down-tray" :href="$href" class="hris-button-success">
    {{ $label }}
</flux:button>
