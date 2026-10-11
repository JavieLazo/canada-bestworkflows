<?php

declare(strict_types=1);

namespace App\Platform\Records;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Platform\ReadModels\QueryPage;
use App\Platform\ReadModels\Snapshot;
use App\Support\Access;
use App\Support\AuditService;
use App\Support\CodeSequence;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Shared mechanics for identical name/description catalogs; subclasses own their model and vocabulary. */
abstract class ReferenceCatalog
{
    /** @return class-string<Model> */
    abstract protected function model(): string;

    abstract protected function prefix(): string;

    abstract protected function noun(): string;

    public function find(Access $actor, string $id): ?CatalogEntry
    {
        abort_if($actor->userId === null, 403);
        $row = ($this->model())::query()->find($id);

        return $row === null ? null : Snapshot::of($row, CatalogEntry::class);
    }

    /** The entry an import row names by the ID the table shows (its `code`). */
    public function findByCode(Access $actor, string $code): ?CatalogEntry
    {
        abort_if($actor->userId === null, 403);
        $row = ($this->model())::query()->where('code', trim($code))->first();

        return $row === null ? null : Snapshot::of($row, CatalogEntry::class);
    }

    /** Whether the actor may add, rename or delete entries — the check every write repeats. */
    public function mayManage(Access $actor): bool
    {
        return $actor->mayReadAllData(ModuleCode::RECORDS);
    }

    /** @return list<string> */
    public function names(Access $actor): array
    {
        abort_if($actor->userId === null, 403);

        return array_values(($this->model())::query()->orderBy('name')->pluck('name')->map(static fn (string $name): string => $name)->all());
    }

    /**
     * Every entry, in name order, for a select that keys on the id.
     *
     * @return list<array{id: string, name: string}>
     */
    public function options(Access $actor): array
    {
        abort_if($actor->userId === null, 403);

        return array_values(($this->model())::query()->orderBy('name')->get(['id', 'name'])
            ->map(static fn ($row): array => ['id' => (string) $row->getKey(), 'name' => (string) $row->getAttribute('name')])->all());
    }

    /** @return LengthAwarePaginator<int, CatalogEntry> */
    public function page(Access $actor, string $search, string $sort, string $direction, int $page, int $perPage): LengthAwarePaginator
    {
        abort_if($actor->userId === null, 403);
        $query = ($this->model())::query();
        $needle = trim($search);
        if ($needle !== '') {
            $like = '%'.addcslashes($needle, '%_\\').'%';
            $query->where(static function ($q) use ($like): void {
                $q->where('code', 'like', $like)->orWhere('name', 'like', $like)->orWhere('description', 'like', $like);
            });
        }
        // 'id' is numeric: catalog ids are bare numbers, so ordering the
        // column as text would read 1, 10, 11, 2.
        QueryPage::sort($query, $sort, $direction, ['id' => 'code', 'name' => 'name'], ['id']);

        return QueryPage::get($query, $page, $perPage, CatalogEntry::class);
    }

    /**
     * Create or rename an entry. `$extra` is an owner's additional column
     * values, already validated by the subclass that owns them (the Payroll
     * Deductions `calculation`); the shared mechanics only carry them.
     *
     * @param  array<string, mixed>  $extra
     */
    public function save(Access $actor, ?string $id, string $name, string $description, array $extra = []): string
    {
        $this->authorize($actor);
        $name = trim($name);
        $description = trim($description);
        if ($name === '') {
            throw new RuntimeException('Name is required.');
        }
        if (mb_strlen($name) > 160) {
            throw new RuntimeException('name: must be at most 160 characters.');
        }
        if (mb_strlen($description) > 2000) {
            throw new RuntimeException('description: must be at most 2000 characters.');
        }
        $model = $this->model();
        $payload = ['name' => $name, 'description' => $description === '' ? null : $description] + $extra;
        if ($id !== null) {
            $row = $model::query()->find($id);
            if ($row === null) {
                throw new RuntimeException("That {$this->noun()} no longer exists.");
            }
            $row->fill($payload)->save();
            AuditService::record($actor, AuditEvent::REFERENCE_UPDATED, $row->getTable(), (string) $row->getKey(),
                ucfirst($this->noun())." {$row->getAttribute('code')} updated to \"{$name}\"");

            return "{$name} updated.";
        }
        $row = DB::transaction(function () use ($model, $payload): Model {
            $code = CodeSequence::next($this->prefix(), $model::query()->lockForUpdate()->pluck('code'));

            return $model::query()->create($payload + ['code' => $code]);
        });
        AuditService::record($actor, AuditEvent::REFERENCE_CREATED, $row->getTable(), (string) $row->getKey(),
            "\"{$name}\" added as {$this->noun()} {$row->getAttribute('code')}");

        return "{$name} added.";
    }

    public function delete(Access $actor, string $id): string
    {
        $this->authorize($actor);
        $row = ($this->model())::query()->find($id);
        if ($row === null) {
            throw new RuntimeException("That {$this->noun()} no longer exists.");
        }
        $code = (string) $row->getAttribute('code');
        $name = (string) $row->getAttribute('name');
        $row->delete();
        // The noun, then the id: "Leave type 4 \"Sick Leave\" deleted". A sentence
        // opening with a bare number says nothing in an audit trail.
        AuditService::record($actor, AuditEvent::REFERENCE_DELETED, $row->getTable(), $id,
            ucfirst($this->noun())." {$code} \"{$name}\" deleted");

        return "{$name} deleted.";
    }

    private function authorize(Access $actor): void
    {
        if (! $this->mayManage($actor)) {
            throw new RuntimeException('Your role cannot manage organization-wide data.');
        }
    }
}
