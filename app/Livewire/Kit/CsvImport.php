<?php

declare(strict_types=1);

namespace App\Livewire\Kit;

use App\Platform\Transfer\ImportReport;
use App\Platform\Transfer\ImportRunner;
use App\Platform\Transfer\ImportUpload;
use App\Platform\Transfer\TableImport;
use App\Platform\Transfer\TableTransfers;
use App\Support\Access;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * "Import CSV" for every importable table: the button, the dialog, the
 * preview and the all-or-nothing save.
 *
 * ── API ─────────────────────────────────────────────────────────────────────
 *
 *     <livewire:kit.csv-import
 *         table="records.sites"                          a TableTransfers import key
 *         :template="route('records.sites.export', ['template' => 1])"
 *         :context="$project->id"                         optional: the owning record
 *         :key="'import-sites'"
 *     />
 *
 * Picking a file runs the preview at once: every row through the owner's
 * own commands inside a transaction that is then rolled back
 * ({@see ImportRunner}). "Import" runs the same pass again and keeps it only
 * if every row still succeeds, then refreshes the host component so the new
 * rows show. Nothing renders for an actor the table's import does not
 * allow, and each action re-resolves Access and re-asks the import.
 *
 * The file is read from Livewire's temporary upload and never stored (a
 * CSV can carry salaries and bank numbers). The upload is deleted as soon as
 * the dialog is done with it: on Cancel, on another pick, after an import,
 * and the moment a preview or an import finds any problem — the file can no
 * longer be imported then, only its name stays on screen beside the errors.
 */
class CsvImport extends Component
{
    use WithFileUploads;

    /** The TableTransfers import key. Selects the owner's import → #[Locked]. */
    #[Locked]
    public string $table = '';

    /** The record the table lives under (a project for its tasks), or "". Authorization-relevant → #[Locked]. */
    #[Locked]
    public string $context = '';

    /** "Download template": the table's export link with template=1. */
    #[Locked]
    public string $template = '';

    /** Dialog visibility. Plain UI state. */
    public bool $open = false;

    /** @var TemporaryUploadedFile|null the picked CSV; re-read by each action, never stored */
    public $file = null;

    /** The picked file's name, still shown after a refused upload is deleted. Plain UI state. */
    public string $pickedName = '';

    /**
     * The last preview or import result (ImportReport::toArray()).
     *
     * @var array{rows: int, created: int, updated: int, unchanged: int, failed: int,
     *     errors: list<array{row: int, message: string}>, notices: list<string>, saved: bool}|null
     */
    public ?array $report = null;

    public function mount(string $table, string $context = '', string $template = ''): void
    {
        abort_unless(app(TableTransfers::class)->imports($table), 404);

        $this->table = $table;
        $this->context = $context;
        $this->template = $template;
    }

    public function openDialog(): void
    {
        $this->discard();
        $this->open = true;
    }

    /** Closing the dialog by Escape or the × is a cancel: the upload goes too. */
    public function updatedOpen(bool $open): void
    {
        if (! $open) {
            $this->discard();
        }
    }

    /** Another pick replaces the last one, whose upload goes first. */
    public function updatingFile(): void
    {
        $this->dropUpload();
    }

    /** A picked file is checked straight away. */
    public function updatedFile(): void
    {
        $this->report = null;
        if (! $this->file instanceof TemporaryUploadedFile) {
            $this->file = null;

            return;
        }
        if ((int) $this->file->getSize() > ImportRunner::maxFileBytes()) {
            Flux::toast(text: $this->file->getClientOriginalName().' is larger than '.ImportRunner::humanSize(ImportRunner::maxFileBytes()).'. Split it and import each part.', variant: 'danger');
            $this->discard();

            return;
        }

        $this->pickedName = $this->file->getClientOriginalName();
        $this->preview();
    }

    public function preview(): void
    {
        $access = app(Access::class);
        $import = $this->allowedImport($access);
        $bytes = $this->bytes();
        if ($bytes === null) {
            return;
        }

        $this->report = app(ImportRunner::class)->preview($access, $import, $this->context, $bytes)->toArray();
        if ($this->report['errors'] !== []) {
            // It cannot be imported as it is; a fixed file is a new pick.
            $this->dropUpload();
        }
    }

    public function import(): void
    {
        $access = app(Access::class);
        $import = $this->allowedImport($access);
        $bytes = $this->bytes();
        if ($bytes === null) {
            return;
        }

        $report = app(ImportRunner::class)->commit($access, $import, $this->context, $bytes);
        if (! $report->saved) {
            $this->report = $report->toArray();
            $this->dropUpload();
            Flux::toast(text: 'Nothing was imported. Fix the rows listed, then choose the file again.', variant: 'danger');

            return;
        }

        Flux::toast(text: self::summary($report), variant: 'success');
        $this->discard();
        $this->open = false;
        $this->js('$wire.$parent?.$refresh()');
    }

    public function render(): View
    {
        $access = app(Access::class);
        $import = app(TableTransfers::class)->import($this->table);
        $allowed = $import->allows($access, $this->context);

        return view('livewire.kit.csv-import', [
            'allowed' => $allowed,
            'layout' => $allowed ? $import->layout($access, $this->context) : null,
            'fileName' => $this->pickedName !== '' ? $this->pickedName : null,
            'maxRows' => ImportRunner::maxRows(),
            'maxSize' => ImportRunner::humanSize(ImportRunner::maxFileBytes()),
        ]);
    }

    private function allowedImport(Access $access): TableImport
    {
        $import = app(TableTransfers::class)->import($this->table);
        abort_unless($import->allows($access, $this->context), 403);

        return $import;
    }

    private function bytes(): ?string
    {
        if (! $this->file instanceof TemporaryUploadedFile) {
            Flux::toast(text: 'Choose a CSV file first.', variant: 'danger');

            return null;
        }
        $bytes = app(ImportUpload::class)->bytes($this->file);
        if ($bytes === null) {
            Flux::toast(text: 'The file could not be read. Choose it again.', variant: 'danger');
            $this->discard();

            return null;
        }

        return $bytes;
    }

    /** Forget the picked file, its name and its report. */
    private function discard(): void
    {
        $this->dropUpload();
        $this->pickedName = '';
        $this->report = null;
    }

    /** Delete the upload itself (ImportUpload); the name and the report stay on screen. */
    private function dropUpload(): void
    {
        if ($this->file instanceof TemporaryUploadedFile) {
            app(ImportUpload::class)->discard($this->file);
        }
        $this->file = null;
    }

    private static function summary(ImportReport $report): string
    {
        $parts = [];
        if ($report->created > 0) {
            $parts[] = $report->created.' added';
        }
        if ($report->updated > 0) {
            $parts[] = $report->updated.' updated';
        }
        if ($report->unchanged > 0) {
            $parts[] = $report->unchanged.' unchanged';
        }

        return 'Imported: '.implode(', ', $parts).'.';
    }
}
