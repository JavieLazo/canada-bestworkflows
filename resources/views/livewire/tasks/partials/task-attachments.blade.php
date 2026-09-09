{{--
    The files hanging off one side of a task — port of
    components/TaskAttachments.tsx, as a Blade partial of the My Tasks dialog.

    Expects: $kind ('BRIEF'|'SUBMISSION'), $label, $files (already narrowed to
    this task and kind), $canUpload, $canDelete, $emptyLabel. Uploads bind the
    page component's $upload property (SUBMISSION only on this screen —
    the BRIEF is read-only for the assignee).

    Divergence noted: lucide Download/Trash2 icons approximate to heroicons
    arrow-down-tray/trash on the Flux primitives.
--}}
@php
    use App\Livewire\Tasks\MyTasksPage;
@endphp
<div class="space-y-2">
    <div class="flex items-center justify-between gap-3">
        <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">{{ $label }}</p>
        @if ($canUpload)
            <div
                x-data
                x-on:task-upload-finished.window="if ($refs.taskUpload) $refs.taskUpload.value = ''"
            >
                <flux:button
                    type="button"
                    variant="outline"
                    size="sm"
                    class="h-7"
                    icon="paper-clip"
                    x-on:click="$refs.taskUpload.click()"
                    wire:loading.attr="disabled"
                    wire:target="upload"
                >
                    Attach
                </flux:button>
                <input
                    type="file"
                    x-ref="taskUpload"
                    class="hidden"
                    wire:model="upload"
                    aria-label="Attach a file"
                />
            </div>
        @endif
    </div>

    @if ($files->isEmpty())
        <p class="rounded-md border border-dashed border-border px-3 py-2 text-xs text-muted-foreground">
            {{ $emptyLabel }}
        </p>
    @else
        <ul class="space-y-1">
            @foreach ($files as $f)
                <li
                    wire:key="file-{{ $f->id }}"
                    class="flex items-center gap-2 rounded-md border border-border bg-muted/30 px-3 py-2"
                >
                    <flux:icon.paper-clip class="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                    <span class="min-w-0 flex-1 truncate text-sm">{{ $f->file_name }}</span>
                    <span class="num shrink-0 text-xs text-muted-foreground">{{ MyTasksPage::humanSize((int) $f->size) }}</span>
                    <a
                        href="{{ route('tasks.files.download', $f->id) }}"
                        aria-label="Download {{ $f->file_name }}"
                        class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-md transition-colors hover:bg-accent hover:text-accent-foreground"
                    >
                        <flux:icon.arrow-down-tray class="h-3.5 w-3.5" />
                    </a>
                    @if ($canDelete)
                        <button
                            type="button"
                            aria-label="Remove {{ $f->file_name }}"
                            class="inline-flex h-7 w-7 shrink-0 cursor-pointer items-center justify-center rounded-md transition-colors hover:bg-accent"
                            wire:click="deleteFile('{{ $f->id }}')"
                            wire:loading.attr="disabled"
                        >
                            <flux:icon.trash class="h-3.5 w-3.5 text-danger" />
                        </button>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</div>
