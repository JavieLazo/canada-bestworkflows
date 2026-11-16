<section class="space-y-3" aria-label="Additional fields">
    @if ($fields->isNotEmpty())
        <div class="flex items-center justify-between gap-3"><h3 class="text-sm font-medium">Additional fields</h3>@if ($editable)<flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit">Edit fields</flux:button>
            @endif
        </div>
        <dl class="grid gap-3 sm:grid-cols-2">
            @foreach ($fields as $field)
                <div class="min-w-0"><dt class="text-xs text-muted-foreground">{{ $field->name }}{{ $field->unit ? ' · '.$field->unit : '' }}</dt><dd class="mt-1 break-words text-sm">{{ is_array($values[$field->id] ?? null) ? count($values[$field->id]).' files' : ($values[$field->id] ?? '—') }}</dd></div>
            @endforeach
        </dl>
    @endif
    <flux:modal wire:model.self="editing" class="w-full max-w-xl"><form wire:submit="save" class="space-y-4"><flux:heading size="lg" level="2">Additional fields</flux:heading>
        @foreach ($fields as $field)
            @if ($field->type === 'CHOICE')
                <x-kit.select wire:model="answers.{{ $field->id }}" :label="$field->name" :aria-label="$field->name"><flux:select.option value="">Not recorded</flux:select.option>
                    @foreach ($field->options['choices'] ?? [] as $option)<flux:select.option value="{{ $option }}">{{ $option }}</flux:select.option>
                    @endforeach
                </x-kit.select>
            @elseif ($field->type === 'EVIDENCE')
                <x-kit.select wire:model="answers.{{ $field->id }}" :label="$field->name" :aria-label="$field->name" multiple searchable>
                    @foreach ($evidenceChoices as $file)<flux:select.option value="{{ $file->id }}">{{ $file->file_name }}</flux:select.option>
                    @endforeach
                </x-kit.select>
            @elseif ($field->type === 'DATE')
                <flux:field><flux:label>{{ $field->name }}</flux:label><x-kit.date-field wire:model="answers.{{ $field->id }}" :aria-label="$field->name" /></flux:field>
            @else
                <flux:input wire:model="answers.{{ $field->id }}" :label="$field->name.($field->unit ? ' · '.$field->unit : '')" :inputmode="in_array($field->type, ['NUMBER', 'MONEY'], true) ? 'decimal' : 'text'" />
            @endif
        @endforeach
        <flux:error name="fields" /><div class="flex justify-end gap-2"><flux:button type="button" variant="ghost" wire:click="$set('editing', false)">Cancel</flux:button><flux:button type="submit" variant="primary">Save fields</flux:button></div>
    </form></flux:modal>
</section>
