<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Queries;

use App\Domain\Enums\ModuleCode;
use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use App\Modules\Workforce\Application\EmployeeFields;
use App\Modules\Workforce\Contracts\Views\EmployeeDocumentView;
use App\Platform\ReadModels\QueryPage;
use App\Support\Access;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Official documents are visible to Employee Records management, including their linked record. */
final class EmployeeDocuments
{
    /** @return LengthAwarePaginator<int, EmployeeDocumentView> */
    public function page(Access $actor, string $employeeId, int $page, int $perPage, string $category = ''): LengthAwarePaginator
    {
        $query = EmployeeDocument::query()->where('employee_id', $employeeId)->orderByDesc('created_at');
        if (! $this->allowed($actor)) {
            $query->whereRaw('1 = 0');
        }

        if ($category !== '') {
            // Older documents store tokens; the filter follows the label shown in the table.
            $label = EmployeeFields::categoryLabel($category);
            $query->whereIn('category', [$label, ...array_keys(EmployeeFields::BUILT_IN_CATEGORIES, $label, true)]);
        }

        return QueryPage::get($query, $page, $perPage, EmployeeDocumentView::class, 'documentsPage');
    }

    /** @return list<string> Catalog choices plus categories still on this employee's documents. */
    public function filterCategories(Access $actor, string $employeeId): array
    {
        if (! $this->allowed($actor)) {
            return [];
        }

        return array_values(EmployeeDocument::query()->where('employee_id', $employeeId)
            ->distinct()->pluck('category')
            ->concat($this->categories($actor))
            ->map(static fn (string $category): string => EmployeeFields::categoryLabel($category))
            ->unique()->sort()->all());
    }

    /** @return list<string> */
    public function categories(Access $actor): array
    {
        abort_unless($actor->mayReadAllData(ModuleCode::EMPLOYEES), 403);

        return array_values(DocumentType::query()->orderBy('name')->get(['name'])->map(static fn (DocumentType $row): string => $row->name)->all());
    }

    public function download(Access $actor, string $id): StreamedResponse
    {
        $document = EmployeeDocument::query()->find($id);
        abort_if($document === null, 404, 'That document was not found.');
        abort_unless($this->allowed($actor), 403);
        $disk = Storage::disk('local');
        abort_unless($disk->exists($document->path), 404, 'That file could not be read.');

        return $disk->download($document->path, $document->file_name, [
            'Content-Type' => $document->mime !== '' ? $document->mime : 'application/octet-stream',
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function allowed(Access $actor): bool
    {
        return $actor->mayReadAllData(ModuleCode::EMPLOYEES);
    }
}
