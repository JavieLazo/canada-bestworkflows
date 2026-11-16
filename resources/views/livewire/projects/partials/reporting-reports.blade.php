<div class="ppm-collection-toolbar"><flux:input size="sm" wire:model.live.debounce.250ms="search" icon="magnifying-glass" placeholder="Find a report…" aria-label="Search reports" class="ppm-search" /><x-work.filters id="report-filters" label="Report filters" :count="$reportStatus === 'ALL' ? 0 : 1"><x-kit.select wire:model.live="reportStatus" label="Status" aria-label="Report status"><flux:select.option value="ALL">All reports</flux:select.option>@foreach (['DRAFT' => 'Draft', 'SUBMITTED' => 'Awaiting review', 'RETURNED' => 'Returned', 'ACCEPTED' => 'Accepted'] as $key => $label)<flux:select.option value="{{ $key }}">{{ $label }}</flux:select.option>
    @endforeach
</x-kit.select></x-work.filters></div>
<div class="ppm-surface overflow-hidden"><div class="overflow-x-auto"><table class="hris-table w-full"><caption class="sr-only">Project reports</caption><thead><tr><x-kit.sort-header field="title" label="Report" /><x-kit.sort-header field="period" label="Period" /><x-kit.sort-header field="status" label="Status" /><x-kit.sort-header field="due" label="Due" /></tr></thead><tbody>
    @forelse ($reports ?? [] as $row)<tr wire:key="project-report-{{ $row->id }}"><td class="max-w-sm"><button type="button" class="block max-w-full truncate text-left font-medium hover:text-primary" wire:click="openReport('{{ $row->id }}')">{{ $row->title }}</button><span class="mt-1 block text-xs text-muted-foreground">{{ $row->template_name }}</span></td><td>{{ $row->period_name }}</td><td><span class="rounded-md bg-muted px-2 py-1 text-xs">{{ ucfirst(strtolower($row->status)) }}</span></td><td class="num whitespace-nowrap">{{ $row->due_on ?? '—' }}</td></tr>
    @empty<tr><x-kit.empty-state :colspan="4" :title="$search !== '' || $reportStatus !== 'ALL' ? 'No reports match these filters.' : 'No reports yet.'" :hint="$search !== '' || $reportStatus !== 'ALL' ? 'Try another search or clear the filters.' : ($permissions['edit'] ? 'Choose New report to start from a template and reporting period.' : 'Reports will appear once your team prepares them.')" /></tr>
    @endforelse
</tbody></table></div>@if ($reports !== null)<x-kit.pagination :paginator="$reports" noun="reports" />
    @endif
</div>
@if ($search !== '' || $reportStatus !== 'ALL')<flux:button size="sm" variant="ghost" wire:click="clearFilters">Clear filters</flux:button>
@endif
@if ($report !== null)
    <section class="ppm-surface space-y-5 p-5" aria-label="Selected report"><div class="flex flex-wrap items-start justify-between gap-3"><div class="min-w-0 flex-1"><h2 class="break-words text-lg font-semibold">{{ $snapshot['title'] ?? $report->title }}</h2><p class="mt-1 text-xs text-muted-foreground">{{ ucfirst(strtolower($report->status)) }}{{ $submission !== null ? ' · Version '.$submission->version : ' · Live draft' }}</p></div><div class="ppm-actions" role="group" aria-label="Report actions">
        @if ($permissions['admin'] && $report->status === 'SUBMITTED')<flux:button size="sm" variant="ghost" wire:click="reportAction('reassign')">Change reviewer</flux:button>
        @endif
        <flux:button size="sm" variant="outline" icon="arrow-down-tray" href="{{ route('projects.reports.workbook', ['project' => $projectId, 'report' => $report->id, 'submission' => $submission?->id]) }}">Export XLSX</flux:button>
        @if ($report->status === 'DRAFT' && $canEditReport)<flux:button size="sm" variant="outline" icon="pencil-square" wire:click="openReport('{{ $report->id }}', true)">Edit draft</flux:button><flux:button size="sm" variant="primary" wire:click="reportAction('submit')">Submit</flux:button>
        @elseif ($canReview)<flux:button size="sm" variant="outline" wire:click="reportAction('return')">Return</flux:button><flux:button size="sm" variant="primary" wire:click="reportAction('accept')">Accept</flux:button>
        @elseif ($canEditReport && in_array($report->status, ['ACCEPTED', 'RETURNED'], true))<flux:button size="sm" variant="outline" wire:click="reportAction('amend')">Start amendment</flux:button>
        @endif
        <flux:button size="sm" variant="ghost" square icon="x-mark" wire:click="$set('reportId', null)" aria-label="Close report" />
    </div></div>
        @if ($submissions->isNotEmpty())<x-kit.select wire:model.live="versionChoice" label="Version" aria-label="Report version" class="sm:max-w-72"><flux:select.option value="CURRENT">{{ $report->status === 'DRAFT' ? 'Current draft' : 'Current submission' }}</flux:select.option>@foreach ($submissions as $saved)<flux:select.option value="{{ $saved->id }}">Version {{ $saved->version }} · {{ ucfirst(strtolower($saved->status)) }}</flux:select.option>
            @endforeach
        </x-kit.select>
        @endif
        @if ($submission !== null)
            <div class="flex flex-wrap gap-x-6 gap-y-2 text-xs text-muted-foreground"><span>Submitted {{ $submission->created_at->format('M j, Y g:i A') }}</span><span class="num" title="{{ $submission->sha256 }}">Integrity verified · {{ substr($submission->sha256, 0, 12) }}</span></div>
            @if ($submission->review_notes)<p class="rounded-lg bg-muted p-3 text-sm">{{ $submission->review_notes }}</p>
            @endif
        @endif
        @if ($snapshot !== null)<x-work.report-snapshot :data="$snapshot" :is-draft="$draft" :submission-id="$submission?->id" />
        @endif
        @if ($draft)
            <livewire:projects.record-evidence-panel :project-id="$projectId" entity="REPORT" :record-id="$report->id" :key="'report-proof-'.$report->id" />
            <livewire:projects.record-fields :project-id="$projectId" entity="REPORT" :record-id="$report->id" :key="'report-fields-'.$report->id" />
        @endif
    </section>
@endif
