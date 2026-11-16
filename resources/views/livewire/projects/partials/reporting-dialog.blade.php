@use('App\Modules\WorkManagement\Application\ReportDefinitions')
<flux:modal wire:model.self="editorOpen" class="w-full max-w-2xl"><form wire:submit="save" class="space-y-5">
    <flux:heading size="lg" level="2">{{ match ($editingKind) { 'outcome' => $editingId ? 'Edit objective / deliverable' : 'New objective / deliverable', 'indicator' => $editingId ? 'Edit indicator plan' : 'New indicator', 'result' => $editingId ? 'Correct accomplishment' : 'Record accomplishment', 'void' => 'Void accomplishment', 'report' => $editingId ? 'Edit report draft' : 'New report', 'period' => 'Reporting period', 'field' => 'Additional field', 'template' => 'Report template', 'submit' => 'Submit report', 'accept' => 'Accept report', 'return' => 'Return report', 'amend' => 'Start amendment', 'reassign' => 'Change reviewer', default => 'Reporting' } }}</flux:heading>
    @if (in_array($editingKind, ['outcome', 'indicator', 'period', 'field', 'template'], true))<flux:input wire:model="form.name" label="Name" maxlength="160" required autofocus />
    @endif
    @if ($editingKind === 'outcome')
        <x-kit.select wire:model.live="form.kind" label="Kind" aria-label="Outcome kind"><flux:select.option value="OBJECTIVE">Objective</flux:select.option><flux:select.option value="DELIVERABLE">Deliverable</flux:select.option></x-kit.select>
        @if (($form['kind'] ?? '') === 'DELIVERABLE')<x-kit.select wire:model="form.parent" label="Objective" aria-label="Deliverable objective" searchable><flux:select.option value="">Independent deliverable</flux:select.option>@foreach ($outcomes->where('kind', 'OBJECTIVE') as $o)<flux:select.option value="{{ $o->id }}">{{ $o->name }}</flux:select.option>
            @endforeach
        </x-kit.select>
        @endif
    @elseif ($editingKind === 'indicator')
        <div class="grid gap-4 sm:grid-cols-2"><x-kit.select wire:model.live="form.kind" label="Value" aria-label="Indicator value kind"><flux:select.option value="NUMERIC">Numeric</flux:select.option><flux:select.option value="QUALITATIVE">Qualitative</flux:select.option></x-kit.select><x-kit.select wire:model="form.aggregation" label="Aggregation" aria-label="Indicator aggregation"><flux:select.option value="SUM">Add accomplishments</flux:select.option><flux:select.option value="LATEST">Latest recorded value</flux:select.option></x-kit.select></div>
        @if (($form['kind'] ?? '') === 'NUMERIC')
            <div class="grid gap-4 sm:grid-cols-3"><flux:input wire:model="form.unit" label="Unit" placeholder="e.g. sites" required /><flux:input wire:model="form.target" label="Target · blank if unplanned" inputmode="decimal" /><x-kit.select wire:model="form.direction" label="Target condition" aria-label="Target condition"><flux:select.option value="AT_LEAST">At least</flux:select.option><flux:select.option value="AT_MOST">At most</flux:select.option><flux:select.option value="EXACT">Exactly</flux:select.option></x-kit.select></div>
        @else<flux:input wire:model="form.targetText" label="Expected result" />
        @endif
        <x-kit.select wire:model="form.outcome" label="Objective / deliverable" aria-label="Linked outcome" searchable><flux:select.option value="">Independent indicator</flux:select.option>@foreach ($outcomes as $o)<flux:select.option value="{{ $o->id }}">{{ $o->name }}</flux:select.option>
            @endforeach
        </x-kit.select>
        <details class="rounded-lg border border-border p-4"><summary class="w-fit text-sm font-medium">Work and target dates</summary><div class="space-y-4 pt-4"><x-kit.select wire:model="form.group" label="Activity" aria-label="Indicator activity" searchable><flux:select.option value="">No activity</flux:select.option>@foreach ($groups as $g)<flux:select.option value="{{ $g->id }}">{{ $g->name }}</flux:select.option>
            @endforeach
        </x-kit.select><x-kit.select wire:model="form.task" label="Task" aria-label="Linked indicator task" searchable><flux:select.option value="">No task</flux:select.option>@foreach ($tasks as $t)<flux:select.option value="{{ $t['id'] }}">{{ $t['title'] }}</flux:select.option>
            @endforeach
        </x-kit.select><div class="grid gap-4 sm:grid-cols-2">@foreach (['startsOn' => 'Target start', 'endsOn' => 'Target end'] as $key => $label)<flux:field><flux:label>{{ $label }}</flux:label><x-kit.date-field wire:model="form.{{ $key }}" :aria-label="$label" /></flux:field>
            @endforeach
        </div></div></details>
        <flux:checkbox wire:model="form.evidenceRequired" label="Require evidence for accomplishments" />
    @elseif ($editingKind === 'result')
        <flux:field><flux:label>Actual date</flux:label><x-kit.date-field wire:model="form.occurredOn" aria-label="Accomplishment date" /></flux:field>
        @if ($indicator?->kind === 'NUMERIC')<flux:input wire:model="form.value" :label="'Accomplished · '.$indicator->unit" inputmode="decimal" required />
        @else<x-kit.select wire:model="form.state" label="Result state" aria-label="Qualitative result state">@foreach (['NOT_STARTED' => 'Not started', 'IN_PROGRESS' => 'In progress', 'PARTIAL' => 'Partial', 'ACHIEVED' => 'Achieved', 'NOT_APPLICABLE' => 'Not applicable'] as $key => $label)<flux:select.option value="{{ $key }}">{{ $label }}</flux:select.option>
            @endforeach
        </x-kit.select><flux:input wire:model="form.valueText" label="Observed result" />
        @endif
        <flux:textarea wire:model="form.narrative" label="Accomplishment" rows="3" /><div class="grid gap-4 sm:grid-cols-2"><flux:textarea wire:model="form.issues" label="Issues" rows="2" /><flux:textarea wire:model="form.correctiveAction" label="Corrective action" rows="2" /></div><flux:input wire:model="form.reference" label="Reference" />
    @elseif ($editingKind === 'report')
        <flux:input wire:model="form.title" label="Report title" required autofocus />
        <div class="grid gap-4 sm:grid-cols-2"><x-kit.select wire:model="form.template" label="Template" aria-label="Report template" searchable><flux:select.option value="">Choose a template</flux:select.option>@foreach ($templates as $t)<flux:select.option value="{{ $t->id }}">{{ $t->name }}{{ $t->archived ? ' · Archived' : '' }}</flux:select.option>
            @endforeach
        </x-kit.select><x-kit.select wire:model="form.period" label="Period" aria-label="Report period" searchable><flux:select.option value="">Choose a period</flux:select.option>@foreach ($periods as $p)<flux:select.option value="{{ $p->id }}">{{ $p->name }}{{ $p->archived ? ' · Archived' : '' }}</flux:select.option>
            @endforeach
        </x-kit.select></div>
        @if ($permissions['finance'])<x-kit.select wire:model="form.budget" label="Budget plan" aria-label="Report budget plan"><flux:select.option value="">No baseline</flux:select.option>@foreach ($budgets as $b)<flux:select.option value="{{ $b->id }}">Version {{ $b->version }} · {{ ucfirst(strtolower($b->status)) }}</flux:select.option>
            @endforeach
        </x-kit.select>
        @endif
        <flux:textarea wire:model="form.summary" label="Summary" rows="4" /><div class="grid gap-4 sm:grid-cols-2"><flux:textarea wire:model="form.issues" label="Issues" rows="3" /><flux:textarea wire:model="form.correctiveAction" label="Corrective action" rows="3" /></div>
    @elseif ($editingKind === 'period')
        <div class="grid gap-4 sm:grid-cols-3">@foreach (['startsOn' => 'Start', 'endsOn' => 'End', 'dueOn' => 'Report due'] as $key => $label)<flux:field><flux:label>{{ $label }}</flux:label><x-kit.date-field wire:model="form.{{ $key }}" :aria-label="$label" /></flux:field>
            @endforeach
        </div>
    @elseif ($editingKind === 'field')
        <div class="grid gap-4 sm:grid-cols-2"><x-kit.select wire:model="form.entity" label="Applies to" aria-label="Field record kind">@foreach (ReportDefinitions::ENTITIES as $key => $label)<flux:select.option value="{{ $key }}">{{ $label }}</flux:select.option>
            @endforeach
        </x-kit.select><x-kit.select wire:model.live="form.type" label="Type" aria-label="Field type">@foreach (ReportDefinitions::TYPES as $key => $label)<flux:select.option value="{{ $key }}">{{ $label }}</flux:select.option>
            @endforeach
        </x-kit.select></div>
        <div class="grid gap-4 sm:grid-cols-2"><flux:input wire:model="form.unit" label="Unit" /><x-kit.select wire:model="form.visibility" label="Visibility" aria-label="Field visibility"><flux:select.option value="PROJECT">Project viewers</flux:select.option><flux:select.option value="FINANCE">Financial viewers</flux:select.option></x-kit.select></div>
        @if (($form['type'] ?? '') === 'CHOICE')<flux:textarea wire:model="form.options" label="Choices · one per line" rows="4" />
        @endif
        <div class="flex flex-wrap gap-4"><flux:checkbox wire:model="form.required" label="Required in reports" /><flux:checkbox wire:model="form.total" label="Total this numeric column" /></div>
    @elseif ($editingKind === 'template')
        <x-kit.select wire:model="form.kind" label="Report kind" aria-label="Report kind">@foreach (ReportDefinitions::KINDS as $key => $label)<flux:select.option value="{{ $key }}">{{ $label }}</flux:select.option>
            @endforeach
        </x-kit.select>
        <flux:checkbox.group wire:model="form.sections" label="Sections"><div class="grid gap-3 sm:grid-cols-2">@foreach (ReportDefinitions::SECTIONS as $key => $label)<flux:checkbox value="{{ $key }}" :label="$label" />
            @endforeach
        </div></flux:checkbox.group>
        @if ($fields->where('archived', false)->isNotEmpty())<flux:checkbox.group wire:model="form.fields" label="Additional columns"><div class="grid max-h-60 gap-3 overflow-auto sm:grid-cols-2">@foreach ($fields->where('archived', false) as $field)<flux:checkbox value="{{ $field->id }}" :label="$field->name.' · '.ReportDefinitions::ENTITIES[$field->entity_type]" />
            @endforeach
        </div></flux:checkbox.group>
        @endif
        @if (! empty($form['fields']))<div class="space-y-2"><h3 class="text-sm font-medium">Column order</h3><ol class="max-h-48 space-y-1 overflow-auto">@foreach ($form['fields'] as $index => $fieldId)@php($columnName = $fields->firstWhere('id', $fieldId)?->name ?? 'Column')<li class="flex items-center gap-2 rounded-lg bg-muted px-3 py-1.5"><span class="min-w-0 flex-1 truncate text-sm">{{ $columnName }}</span><flux:button type="button" size="sm" variant="ghost" square icon="arrow-up" wire:click="moveField({{ $index }}, -1)" :disabled="$index === 0" aria-label="Move {{ $columnName }} up" /><flux:button type="button" size="sm" variant="ghost" square icon="arrow-down" wire:click="moveField({{ $index }}, 1)" :disabled="$loop->last" aria-label="Move {{ $columnName }} down" /></li>
            @endforeach
        </ol></div>
        @endif
    @elseif (in_array($editingKind, ['submit', 'reassign'], true))
        <x-kit.select wire:model="form.reviewer" label="Reviewer" aria-label="Report reviewer" searchable><flux:select.option value="">Choose another project viewer / editor</flux:select.option>@foreach ($reviewers as $person)<flux:select.option value="{{ $person->id }}">{{ $person->name }}</flux:select.option>
            @endforeach
        </x-kit.select><p class="text-xs text-muted-foreground">The reviewer needs access to every included section. Submission freezes the report and its evidence.</p>
    @elseif (in_array($editingKind, ['accept', 'return'], true))<flux:textarea wire:model="form.notes" :label="$editingKind === 'return' ? 'Required changes' : 'Review notes'" rows="4" :required="$editingKind === 'return'" />
    @endif
    @if (in_array($editingKind, ['outcome', 'indicator', 'template'], true))<flux:textarea wire:model="form.description" label="Description" rows="3" />
    @endif
    @if (in_array($editingKind, ['outcome', 'indicator'], true))<x-kit.select wire:model="form.status" label="Status" aria-label="Plan status"><flux:select.option value="ACTIVE">Active</flux:select.option><flux:select.option value="DISCONTINUED">Discontinued</flux:select.option></x-kit.select>
    @endif
    @if (in_array($editingKind, ['period', 'field', 'template'], true) && $editingId !== null)<flux:checkbox wire:model="form.archived" label="Archive for new reports" />
    @endif
    @if (in_array($editingKind, ['void', 'amend', 'reassign'], true) || ($editingId !== null && in_array($editingKind, ['outcome', 'indicator', 'result', 'period', 'field', 'template'], true)))<flux:textarea wire:model="form.reason" label="Reason for change" rows="2" required />
    @endif
    @if ($errors->any())<ul class="space-y-1 text-sm text-destructive" role="alert">@foreach ($errors->all() as $error)<li>{{ $error }}</li>
        @endforeach
    </ul>
    @endif
    <div class="flex justify-end gap-2"><flux:button type="button" variant="ghost" wire:click="$set('editorOpen', false)">Cancel</flux:button><flux:button type="submit" :variant="$editingKind === 'void' ? 'danger' : 'primary'">{{ match ($editingKind) { 'submit' => 'Submit for review', 'accept' => 'Accept report', 'return' => 'Return report', 'amend' => 'Start amendment', 'void' => 'Void accomplishment', default => 'Save' } }}</flux:button></div>
</form></flux:modal>
