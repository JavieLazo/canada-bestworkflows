<section class="hris-card p-5 space-y-4">
    <div><h2 class="font-semibold">Signed MR Attachments</h2><p class="text-sm text-muted-foreground">Private evidence of the signed receipt. The original MR details remain unchanged.</p></div>
    @if ($canManage)
        <form wire:submit="attach" class="flex flex-wrap items-end gap-3">
            <div class="min-w-0 flex-1"><flux:input type="file" wire:model="upload" accept="application/pdf,image/jpeg,image/png" label="Signed PDF or Image" /><flux:error name="upload" /><p class="text-xs text-muted-foreground mt-1">Maximum {{ number_format($maxBytes / 1024 / 1024, 1) }} MB.</p></div>
            <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="upload,attach">Attach Signed MR</flux:button>
        </form>
    @endif
    <ul class="divide-y divide-border">
        @forelse ($files as $file)
            <li wire:key="attachment-{{ $file->id }}" class="flex flex-wrap items-center gap-3 py-3">
                <a class="text-primary underline min-w-0 break-words flex-1" href="{{ route($own ? 'portal.inventory.attachments.download' : 'inventory.attachments.download', $file->id) }}">{{ $file->file_name }}</a>
                <span class="text-xs text-muted-foreground">{{ number_format($file->size / 1024, 1) }} KB</span>
                @if ($canManage)<flux:button size="sm" variant="danger" wire:click="deleteFile('{{ $file->id }}')" wire:confirm="Remove this signed MR attachment?">Remove</flux:button>@endif
            </li>
        @empty
            <li class="text-sm text-muted-foreground py-3">No signed MR attached.</li>
        @endforelse
    </ul>
</section>
