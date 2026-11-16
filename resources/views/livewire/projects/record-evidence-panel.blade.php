<section class="space-y-3" aria-label="Record evidence">
    <div class="flex flex-wrap items-center justify-between gap-2"><h3 class="text-sm font-medium">Evidence</h3><span class="num text-xs text-muted-foreground">{{ $files->count() }} files</span></div>
    @php($images = $files->whereIn('mime_type', ['image/jpeg', 'image/png', 'image/webp'])->map(fn ($file) => route('projects.record-files.show', ['file' => $file->id, 'preview' => 1]))->values()->all())
    <x-social.photo-grid :urls="$images" compact :key="'record-'.$recordId" />
    @foreach ($files as $file)
        <x-work.file :file="$file" :href="route('projects.record-files.show', $file->id)" wire:key="record-file-{{ $file->id }}">
            @if ($editable)<x-slot:actions><flux:button variant="ghost" class="text-danger!" size="sm" square icon="x-mark" wire:click="remove('{{ $file->id }}')" aria-label="Remove {{ $file->file_name }}" /></x-slot:actions>
            @endif
        </x-work.file>
    @endforeach
    @if ($editable)
        <form wire:submit="attach" class="flex flex-wrap items-end gap-2"><flux:input type="file" wire:model="uploads" multiple label="Attach evidence" class="min-w-0 flex-1" /><flux:button type="submit" size="sm" variant="outline" wire:loading.attr="disabled" wire:target="uploads,attach">Attach</flux:button></form>
        <flux:error name="uploads" /><flux:error name="uploads.*" /><flux:error name="evidence" />
    @endif
</section>
