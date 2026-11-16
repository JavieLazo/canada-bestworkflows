@props(['data', 'isDraft' => false, 'submissionId' => null])
@php
    use App\Modules\WorkManagement\Application\ReportColumns;
@endphp
<div class="space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-3"><span class="text-xs text-muted-foreground">{{ $data['period']['name'] }} · {{ $data['period']['starts_on'] }} – {{ $data['period']['ends_on'] }}{{ $data['currency'] ? ' · '.$data['currency'] : '' }}</span><span class="text-xs {{ $isDraft ? 'text-warning' : 'text-muted-foreground' }}">{{ $isDraft ? 'Live draft' : 'Saved submission' }}</span></div>
    @foreach (['summary' => 'Summary', 'issues' => 'Issues', 'corrective_action' => 'Corrective action', 'amendment_reason' => 'Amendment'] as $key => $label)
        @if ($data[$key] ?? null)<div><h3 class="text-xs text-muted-foreground">{{ $label }}</h3><p class="mt-1 whitespace-pre-line break-words text-sm">{{ $data[$key] }}</p></div>
        @endif
    @endforeach
    @if ($data['scope']['financial'])<dl class="grid gap-4 rounded-lg bg-muted p-4 sm:grid-cols-2 lg:grid-cols-3">@foreach (ReportColumns::financialSummary($data) as $row)<div class="min-w-0"><dt class="text-xs text-muted-foreground">{{ $row['label'] }}</dt><dd class="num mt-1 break-words text-lg font-semibold">{{ ReportColumns::display($row['value'], $row['type']) }}</dd></div>
        @endforeach
    </dl>
    @endif
    @if (($data['coverage']['blocking'] ?? []) !== [] || ($data['coverage']['warnings'] ?? []) !== [])
        <details class="rounded-lg border border-border px-4 py-3" @if (($data['coverage']['blocking'] ?? []) !== []) open @endif><summary class="w-fit text-sm font-medium">Coverage · {{ count($data['coverage']['blocking'] ?? []) }} required items missing</summary><ul class="mt-3 max-h-48 list-inside list-disc space-y-1 overflow-auto text-xs text-muted-foreground">@foreach (array_merge($data['coverage']['blocking'] ?? [], $data['coverage']['warnings'] ?? []) as $message)<li>{{ $message }}</li>
            @endforeach
        </ul></details>
    @endif
    @foreach ($data['sections'] as $section => $sectionRows)
        @php($table = ReportColumns::section($data, $section))
        <details class="rounded-lg border border-border px-4 py-3"><summary class="w-fit text-sm font-medium">{{ ucfirst($section) }} <span class="num ml-2 text-xs text-muted-foreground">{{ count($sectionRows) }}</span></summary>
            <div class="mt-3 max-h-96 overflow-auto"><table class="hris-table w-full"><caption class="sr-only">{{ ucfirst($section) }}</caption><thead><tr>@foreach ($table['columns'] as $key => $column)@if (! str_ends_with($key, '_id') && $key !== 'id')<th class="whitespace-nowrap">{{ $column['label'] }}</th>
                @endif
                @endforeach
            </tr></thead><tbody>@forelse (array_slice($table['rows'], 0, 50) as $row)<tr>@foreach ($table['columns'] as $key => $column)@if (! str_ends_with($key, '_id') && $key !== 'id')<td class="min-w-28 max-w-80 break-words">{{ ReportColumns::display($row[$key] ?? null, $column['type']) }}</td>
                @endif
                @endforeach
            </tr>@empty<tr><td colspan="{{ count($table['columns']) }}" class="py-5 text-muted-foreground">No records in this period.</td></tr>
                @endforelse
            </tbody></table></div>
            @if (count($sectionRows) > 50)<p class="mt-3 text-xs text-muted-foreground">Showing the first 50 rows. The workbook includes all {{ count($sectionRows) }}.</p>
            @endif
        </details>
    @endforeach
    @if ($data['files'] !== [])
        <section class="space-y-3"><h3 class="text-sm font-medium">Included evidence</h3>
            @foreach ($data['files'] as $file)<a class="block truncate text-sm text-primary underline-offset-4 hover:underline" href="{{ route('projects.record-files.show', ['file' => $file['id'], 'project' => $data['project_id'], 'submission' => $submissionId]) }}">{{ $file['file_name'] }}</a>
            @endforeach
        </section>
    @endif
</div>
