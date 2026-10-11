<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Contracts;

use App\Support\Access;
use Illuminate\Support\Collection;

/** Minimal employee facts consumed by already-authorized task operations. */
interface AssignmentEmployees
{
    /** @param list<string> $ids
     * @return list<string>|string */
    public function resolveActive(Access $actor, array $ids): array|string;

    /** @param list<string> $ids
     * @return array<string, string> Names keyed by id, sorted by surname and given name. */
    public function names(array $ids): array;

    /** Internal attribution for an already-authorized task or attachment.
     * @param  list<string>  $ids
     * @return Collection<int, DirectoryEmployee>
     */
    public function identities(array $ids): Collection;
}
