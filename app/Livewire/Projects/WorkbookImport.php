<?php

declare(strict_types=1);

namespace App\Livewire\Projects;

use App\Modules\WorkManagement\Application\Queries\ProjectFinancials;
use App\Modules\WorkManagement\Application\Queries\ProjectReports;
use App\Modules\WorkManagement\Application\Queries\ProjectResults;
use App\Modules\WorkManagement\Application\WorkbookRows;
use App\Modules\WorkManagement\Application\WorkbookTransfer;
use App\Platform\Transfer\CsvSheet;
use App\Platform\Transfer\ImportRunner;
use App\Platform\Transfer\ImportUpload;
use App\Platform\Transfer\XlsxReader;
use App\Support\Access;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use RuntimeException;

class WorkbookImport extends Component
{
    use WithFileUploads;

    #[Locked]
    public string $projectId;

    #[Locked]
    public bool $showTrigger = true;

    public bool $open = false;

    #[On('open-project-workbook')]
    public function openForProject(string $projectId): void
    {
        if ($this->projectId === $projectId) {
            $this->open = true;
        }
    }

    public ?TemporaryUploadedFile $upload = null;

    public string $sheetName = '';

    public int $headerRow = 4;

    public string $mode = 'RESULT_ENTRY';

    /** Editable column mapping and defaults, not #[Locked]; the transfer validates keys, references and current scope. */
    /** @var array<string, string> */
    public array $mapping = [];

    /** @var array<string, mixed> */
    public array $options = ['entity' => 'REPORT', 'budgetId' => '', 'categoryId' => '', 'indicatorId' => '', 'contributionId' => '', 'source' => '', 'reason' => ''];

    public bool $cachedValuesReviewed = false;

    #[Locked]
    public string $previewStamp = '';

    /** @var array<string, mixed>|null */
    #[Locked]
    public ?array $result = null;

    public function updatedOpen(bool $open): void
    {
        if (! $open) {
            $this->close();
        }
    }

    public function updatedUpload(): void
    {
        $this->previewStamp = '';
        $this->result = null;
        $this->mapping = [];
        $this->sheetName = '';
        if ($this->upload === null) {
            return;
        }
        $this->validateUpload();
        try {
            $sheets = app(XlsxReader::class)->sheets(app(ImportUpload::class)->path($this->upload));
            $preferred = match ($this->mode) {
                'RESULT_ENTRY' => 'Accomplishments', 'BUDGET_LINE' => 'Budget', 'CASH_ENTRY' => 'Cash', 'CONTRIBUTION_DELIVERY' => 'Deliveries', 'TASK' => 'Work', default => 'Report answers'
            };
            $this->sheetName = in_array($preferred, $sheets, true) ? $preferred : $sheets[0];
        } catch (RuntimeException $e) {
            $this->addError('workbook', $e->getMessage());
        }
    }

    public function mapColumns(): void
    {
        $this->previewStamp = '';
        $this->result = null;
        $this->resetValidation();
        $read = $this->read();
        if ($read === null) {
            return;
        }
        $adapter = new WorkbookRows($this->mode, $this->options);
        $columns = $adapter->columns(app(Access::class), $this->projectId);
        $aliases = ['id' => ['id', 'code', 'Entry ID', 'Task ID', 'Cost ID', 'Cash ID', 'Delivery ID', 'Report ID', 'Record ID'], 'indicator' => ['indicator_id', 'Indicator ID'],
            'date' => ['occurred_on', 'Actual date'], 'value' => ['value_scaled', 'Numeric actual'], 'text' => ['value_text', 'Qualitative actual'], 'action' => ['corrective_action', 'Corrective action'],
            'category' => ['category_label', 'Category'], 'group' => ['group_label', 'Activity'], 'quantity' => ['quantity_scaled', 'Quantity', 'Delivered quantity'], 'unit' => ['unit_minor', 'Unit cost'],
            'amount' => ['entered_minor', 'Entered amount', 'amount_minor', 'Amount', 'value_minor', 'Recorded value'], 'period' => ['period_id', 'period_label', 'Reporting period'],
            'contribution' => ['contribution_id', 'Contribution ID'], 'basis' => ['basis', 'valuation_basis', 'Valuation basis', 'Cost basis'], 'title' => ['title', 'Task'], 'assignees' => ['people', 'People', 'Assignees'], 'start' => ['starts_on', 'Start', 'Start date'], 'due' => ['due_on', 'Deadline', 'Due date']];
        $normalize = static fn (string $value): string => mb_strtolower(trim($value));
        $this->mapping = [];
        foreach ($columns as $key => $label) {
            $candidates = array_map($normalize, [...($aliases[$key] ?? []), $key, $label]);
            $match = '';
            foreach ($candidates as $candidate) {
                foreach ($read['sheet']->headers as $index => $header) {
                    if ($normalize($header) === $candidate) {
                        $match = (string) $index;
                        break 2;
                    }
                }
            }
            $this->mapping[$key] = $match;
        }
        $duplicates = array_filter(array_count_values(array_filter($this->mapping, static fn (string $value): bool => $value !== '')), static fn (int $count): bool => $count > 1);
        foreach ($this->mapping as $key => $index) {
            if (isset($duplicates[$index])) {
                $this->mapping[$key] = '';
            }
        }
        $this->cachedValuesReviewed = $read['formula_cells'] === [];
    }

    public function preview(): void
    {
        $this->perform(false);
    }

    public function import(): void
    {
        if ($this->previewStamp === '' || $this->result === null || ($this->result['errors'] ?? []) !== []) {
            throw ValidationException::withMessages(['workbook' => 'Preview a valid sheet before importing.']);
        }
        $this->perform(true);
    }

    public function close(): void
    {
        if ($this->upload !== null) {
            app(ImportUpload::class)->discard($this->upload);
        }
        $this->upload = null;
        $this->previewStamp = '';
        $this->result = null;
        $this->open = false;
    }

    private function perform(bool $keep): void
    {
        $this->resetValidation();
        $read = $this->read();
        if ($read === null) {
            return;
        }
        if ($read['formula_cells'] !== [] && ! $this->cachedValuesReviewed) {
            $this->addError('workbook', 'Review the saved formula results before previewing this sheet. BestWorkflows does not execute uploaded formulas.');

            return;
        }
        try {
            $run = app(WorkbookTransfer::class)->run(app(Access::class), $this->projectId, $this->mode, $read['sheet'], $this->options, $this->mapping, $keep ? $this->previewStamp : null);
            $this->result = $run['report']->toArray();
            $this->previewStamp = $run['stamp'];
        } catch (ValidationException $e) {
            $this->addError('workbook', implode(' ', array_merge(...array_values($e->errors()))));
            $this->previewStamp = '';

            return;
        }
        if ($keep && $this->result['saved']) {
            if ($this->upload !== null) {
                app(ImportUpload::class)->discard($this->upload);
            }
            $this->upload = null;
            $this->previewStamp = '';
            $this->dispatch('workbook-imported');
            Flux::toast('Workbook rows imported.');
        }
    }

    /** @return array{sheet: CsvSheet, formula_cells: list<string>}|null */
    private function read(): ?array
    {
        $this->validateUpload();
        try {
            return app(XlsxReader::class)->read(app(ImportUpload::class)->path($this->upload), $this->sheetName, $this->headerRow, ImportRunner::maxRows());
        } catch (RuntimeException $e) {
            $this->addError('workbook', $e->getMessage());

            return null;
        }
    }

    private function validateUpload(): void
    {
        $this->validate(['upload' => 'required|file|extensions:xlsx|max:9766', 'headerRow' => 'required|integer|min:1|max:100']);
    }

    public function render(): View
    {
        $actor = app(Access::class);
        $permissions = app(ProjectReports::class)->permissions($actor, $this->projectId);
        $finance = app(ProjectFinancials::class)->permissions($actor, $this->projectId);
        $results = app(ProjectResults::class)->permissions($actor, $this->projectId);
        $modes = array_filter(WorkbookRows::MODES, static fn (string $key): bool => match ($key) {
            'RESULT_ENTRY', 'TASK' => $results['edit'], 'FIELDS' => $permissions['edit'], default => $finance['edit']
        }, ARRAY_FILTER_USE_KEY);
        if (! isset($modes[$this->mode]) && $modes !== []) {
            $this->mode = array_key_first($modes);
        }
        $sheets = [];
        $headers = [];
        $sample = [];
        $formulas = 0;
        if ($this->open && $this->upload !== null) {
            try {
                $path = app(ImportUpload::class)->path($this->upload);
                $sheets = app(XlsxReader::class)->sheets($path);
                if (in_array($this->sheetName, $sheets, true)) {
                    $read = app(XlsxReader::class)->read($path, $this->sheetName, $this->headerRow, ImportRunner::maxRows());
                    $headers = $read['sheet']->headers;
                    $sample = array_slice($read['sheet']->rows, 0, 5);
                    $formulas = count($read['formula_cells']);
                }
            } catch (RuntimeException $e) {
                $this->addError('workbook', $e->getMessage());
            }
        }

        return view('livewire.projects.workbook-import', compact('modes', 'sheets', 'headers', 'sample', 'formulas') + [
            'fieldEntities' => $this->open ? WorkbookRows::fieldEntities($actor, $this->projectId) : [],
            'columns' => $this->open && isset($modes[$this->mode]) ? (new WorkbookRows($this->mode, $this->options))->columns($actor, $this->projectId) : [],
            'categories' => $this->open && $finance['edit'] ? app(ProjectFinancials::class)->categories($actor, $this->projectId) : collect(),
            'budgets' => $this->open && $finance['edit'] ? app(ProjectFinancials::class)->versions($actor, $this->projectId)->where('status', 'DRAFT') : collect(),
            'indicators' => $this->open && $results['edit'] ? app(ProjectResults::class)->indicators($actor, $this->projectId)->items() : [],
        ]);
    }
}
