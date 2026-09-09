<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Access;

/**
 * Route middleware: the caller must hold one of the named modules AND the
 * `all_data` scope. The port of `requireAllDataModule(...modules)`
 * (src/lib/api/guard.ts:92-97): "Management operations must be granted both the
 * module and all-data scope."
 *
 *     Route::get('/users', Users\Index::class)->middleware('module.all:USERS');
 *     Route::get('/audit', Audit\Index::class)->middleware('module.all:AUDIT');
 *
 * {@see EnsureModuleAccess} with one predicate swapped — everything else
 * (the Livewire-aware denial, the redirect to the landing module, the
 * read-from-the-database-every-request guarantee) is inherited so the two
 * cannot drift.
 *
 * SEC-01, restated because this is the class where getting it wrong is the
 * whole bug: `all_data` is NOT a permission by itself. It widens a read from
 * "my rows" to "every row"; the module says WHICH rows. A Supervisor holds
 * `all_data` so they can see the roster, and that must not also hand them
 * everyone's salary — which is why the widening is always
 * {@see Access::mayReadAllData()}, never a bare `allData` test,
 * and why that method has no zero-argument overload to reach for.
 */
final class EnsureAllData extends EnsureModuleAccess
{
    /**
     * @param  list<string>  $codes
     */
    protected function passes(array $codes): bool
    {
        return $this->access->mayReadAllData(...$codes);
    }

    /**
     * Two failures, two messages, exactly as the source distinguishes them: the
     * module gate fails first with `requireModule`'s wording, and only a caller
     * who cleared it hears about the scope (guard.ts:86-96).
     *
     * @param  list<string>  $codes
     */
    protected function deniedMessage(array $codes): string
    {
        return $this->access->hasModule(...$codes)
            ? 'Your role cannot manage organization-wide data.'
            : 'You do not have access to that.';
    }
}
