{{--
    The files hanging off one side of a task — port of
    components/TaskAttachments.tsx, as a Blade partial of the My Tasks dialog.

    Expects: $kind ('BRIEF'|'SUBMISSION'), $label, $files (already narrowed to
    this task and kind), $canUpload, $canDelete, $emptyLabel, plus the page's
    $actorUserId. Uploads bind the page component's $upload property
    (SUBMISSION only on this screen — the BRIEF is read-only for the assignee).

    On a GROUP task every SUBMISSION file is the whole crew's and
    downloadable by all of them. On an INDIVIDUAL task a member's evidence is
    theirs and the reviewer's alone: $files holds only the viewer's own
    SUBMISSION files (a reviewer off the crew gets everyone's), and
    WorkRecords::download() refuses a peer's file on both routes — see
    WorkRecords::files(). Either way the trash button appears only on the
    files THIS account uploaded — MyTasksPage::deleteFile() refuses the rest,
    and a button that always 403s would be a lie. A SUBMISSION file somebody
    else uploaded is named for its uploader instead ("by Ramon Cruz" — the
    employee spelling the dialog uses elsewhere, else the account name), so a
    peer's evidence never reads as the actor's own. Each file view carries
    its uploader for that — an AccountLabel with the account's employee —
    batch-resolved by WorkRecords::files().

    Divergence noted: lucide Download/Trash2 icons approximate to heroicons
    arrow-down-tray/trash on the Flux primitives.
--}}
@php
    use App\Livewire\Tasks\MyTasksPage;
    use App\Support\EmployeeName;
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
                    @if ($kind === 'SUBMISSION' && $f->uploader !== null && (int) $f->uploaded_by !== ($actorUserId ?? null))
                        {{-- Somebody else's evidence: say whose. --}}
                        <span class="shrink-0 text-xs text-muted-foreground">by {{ EmployeeName::display($f->uploader->employee, $f->uploader->name) }}</span>
                    @endif
                    <span class="num shrink-0 text-xs text-muted-foreground">{{ MyTasksPage::humanSize((int) $f->size) }}</span>
                    <a
                        href="{{ route('tasks.files.download', $f->id) }}"
                        aria-label="Download {{ $f->file_name }}"
                        class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-md transition-colors hover:bg-accent hover:text-accent-foreground"
                    >
                        <flux:icon.arrow-down-tray class="h-3.5 w-3.5" />
                    </a>
                    @if ($canDelete && $f->uploaded_by !== null && (int) $f->uploaded_by === ($actorUserId ?? null))
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
