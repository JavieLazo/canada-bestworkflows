<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Models\BaseModel;
use App\Models\User;
use App\Platform\Notifications\Inbox;
use App\Platform\Records\ReferenceCatalogs;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The rules of AGENTS.md that a grep can enforce, as failing checks.
 *
 * Without these checks the suite could stay green while a `sort()` landed on
 * ModuleCode::all(), a hand-rolled `where('employee_id',
 * $access->employeeId)` compiled to IS NULL and leaked rows, a queued job or a
 * `dailyAt()` shipped to a host with no worker, or a `wire:poll` skipped the
 * change stamp that protects the cPanel entry-process budget. AGENTS.md and
 * the area guides are gitignored, so for anyone cloning this repository these
 * tests ARE the rules.
 *
 * Every scan here strips comments first — PHP through `token_get_all`, Blade
 * through `{{-- --}}` — because the prose that names a banned token is exactly
 * the prose these rules are written in. Line numbers are preserved so a
 * failure points at the real line. The technique is `PayrollArchitectureTest`'s,
 * which guards the money rules the same way; `ArchitectureTest` covers the
 * module registry and Blade imports. Nothing here is behavioural: it reads the
 * source, and it never needs a database row.
 */

/* |---| Scanner |---| */

/**
 * PHP source with every comment replaced by its own newlines, so prose naming
 * a banned token cannot trip a scan and line numbers stay honest.
 */
function convStripPhp(string $src): string
{
    $out = '';
    foreach (token_get_all($src) as $token) {
        if (! is_array($token)) {
            $out .= $token;

            continue;
        }
        $out .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)
            ? str_repeat("\n", substr_count($token[1], "\n"))
            : $token[1];
    }

    return $out;
}

/**
 * Comment-stripped PHP sources, keyed by path relative to the repo root.
 *
 * @param  list<string>  $roots  files or directories, relative to the repo root
 * @param  list<string>  $except  relative paths to leave out, with the reason at the call site
 * @return array<string, string>
 */
function convPhp(array $roots, array $except = []): array
{
    $base = rtrim(base_path(), '/').'/';
    $files = [];

    foreach ($roots as $root) {
        $abs = $base.$root;
        $paths = [];
        if (is_file($abs)) {
            $paths = [$abs];
        } elseif (is_dir($abs)) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($abs)) as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $paths[] = $file->getPathname();
                }
            }
        }
        foreach ($paths as $path) {
            $rel = str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($base)));
            if (in_array($rel, $except, true)) {
                continue;
            }
            $files[$rel] = convStripPhp((string) file_get_contents($path));
        }
    }
    ksort($files);

    return $files;
}

/**
 * Blade sources with every `{{-- --}}` comment replaced by its own newlines,
 * keyed by path relative to resources/views.
 *
 * @param  list<string>  $dirs  relative to resources/views
 * @return array<string, string>
 */
function convBlade(array $dirs): array
{
    $base = rtrim(resource_path('views'), '/').'/';
    $files = [];

    foreach ($dirs as $dir) {
        $abs = $base.$dir;
        if (! is_dir($abs)) {
            continue;
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($abs)) as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }
            $src = (string) file_get_contents($file->getPathname());
            $src = (string) preg_replace_callback(
                '/\{\{--.*?--\}\}/s',
                static fn (array $m): string => str_repeat("\n", substr_count($m[0], "\n")),
                $src,
            );
            $files[str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($base)))] = $src;
        }
    }
    ksort($files);

    return $files;
}

/**
 * Every line of every source matching the pattern, as "path:line: text".
 *
 * @param  array<string, string>  $sources
 * @return list<string>
 */
function convHits(array $sources, string $pattern): array
{
    $hits = [];
    foreach ($sources as $path => $src) {
        foreach (explode("\n", $src) as $index => $line) {
            if (preg_match($pattern, $line) === 1) {
                $hits[] = $path.':'.($index + 1).': '.trim($line);
            }
        }
    }

    return $hits;
}

/**
 * Every concrete Livewire component under app/Livewire, by class name.
 *
 * @return list<class-string<Component>>
 */
function convComponents(): array
{
    $root = rtrim(app_path('Livewire'), '/');
    $classes = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $relative = substr($file->getPathname(), strlen($root) + 1, -4);
        $class = 'App\\Livewire\\'.str_replace('/', '\\', $relative);
        if (class_exists($class) && is_subclass_of($class, Component::class) && ! (new ReflectionClass($class))->isAbstract()) {
            $classes[] = $class;
        }
    }
    sort($classes);

    return $classes;
}

/* |---| Access control |---| */

it('never compares employee_id to the nullable Access::employeeId', function () {
    // A null id compiles to `employee_id IS NULL` and hands an unlinked account
    // every unowned row. Scope with toEmployee()/toEmployeeVia(), or pin a
    // "My" screen with ownEmployeeId(), which throws instead of returning null.
    // `$this->employeeId` is a different thing: a #[Locked] non-null mount
    // parameter on Employees\Documents and Payroll\IndividualPayslip, filtered
    // after the scope. Only an Access-shaped variable carries the nullable one.
    $hits = convHits(
        convPhp(['app']),
        '/where\(\s*[\'"]employee_id[\'"]\s*,\s*(?:[\'"]=[\'"]\s*,\s*)?\$(?!this\b)\w+->employeeId\s*\)/',
    );

    expect($hits)->toBe([], 'where(\'employee_id\', $access->employeeId) compiles to IS NULL for an unlinked account. Use the scope helpers or ownEmployeeId().');
});

it('reads all_data only inside Access itself', function () {
    // mayReadAllData() and rowVisibility() are the two readings of the flag.
    // A bare `$actor->allData` test elsewhere either forgets the module (a
    // privilege escalation) or re-derives one of those two by hand.
    $hits = convHits(convPhp(['app'], ['app/Support/Access.php']), '/\$\w+->allData\b/');

    expect($hits)->toBe([], 'Read all_data through Access::mayReadAllData() or rowVisibility(), never the bare property.');
});

it('puts module.all: on no route', function () {
    // The alias redirects a page to itself for a holder without all_data —
    // routes/modules/audit.php explains the loop. Gate all_data inside the
    // component instead, as Audit\Index does.
    expect(convHits(convPhp(['routes']), '/module\.all:/'))->toBe([]);
});

/* |---| Time |---| */

it('never calls ->toDateString() in app/', function () {
    // Timestamps are UTC; toDateString() on one gives the UTC day, which is
    // the wrong local day for several hours of every day. LocalClock::dayKey() is
    // the conversion; DATE columns go through PayrollPeriod::dateKeyFromDate().
    expect(convHits(convPhp(['app']), '/->toDateString\(\)/'))->toBe([]);
});

it('names a time zone only in LocalClock and its setting', function () {
    $hits = convHits(
        convPhp(['app', 'config', 'routes', 'database'], ['app/Support/LocalClock.php', 'config/copems.php']),
        '/[\x27"](?:Africa|America|Antarctica|Asia|Atlantic|Australia|Europe|Indian|Pacific)\/[A-Za-z_\/]+[\x27"]|[\x27"][+-](?!00:00)\d{2}:\d{2}[\x27"]/',
    );

    expect($hits)->toBe([], 'The zone is LocalClock::zone(), named by copems.timezone. A zone or a fixed offset written anywhere else ignores the setting, and an offset ignores daylight saving time.');
});

/* |---| Hosting: no shell, no worker |---| */

it('introduces nothing that needs a queue worker or a daemon', function () {
    $hits = convHits(convPhp(['app']), '/\b(ShouldQueue|Reverb|Horizon|Octane)\b|ShouldBroadcast(?!Now)/');

    expect($hits)->toBe([], 'Production has no worker. Side effects run inline or after the response; broadcasts are ShouldBroadcastNow through Realtime::announce.');

    /** @var array{require?: array<string, string>, "require-dev"?: array<string, string>} $composer */
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, 512, JSON_THROW_ON_ERROR);
    $packages = array_keys(array_merge($composer['require'] ?? [], $composer['require-dev'] ?? []));

    expect(array_values(array_intersect($packages, ['laravel/reverb', 'laravel/horizon', 'laravel/octane'])))->toBe([]);
});

it('never adds spatie/laravel-permission', function () {
    // Authorization is App\Support\Access, resolved fresh per request and
    // never cached. A permission package caches grants by design.
    /** @var array{require?: array<string, string>, "require-dev"?: array<string, string>} $composer */
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, 512, JSON_THROW_ON_ERROR);
    $packages = array_keys(array_merge($composer['require'] ?? [], $composer['require-dev'] ?? []));

    expect(array_values(array_filter($packages, static fn (string $p): bool => str_starts_with($p, 'spatie/laravel-permission'))))->toBe([]);
});

it('calls env() only inside config/', function () {
    // A cached config (production runs one) makes every env() outside config/
    // return null. Add the key to config/copems.php and read config('copems.*').
    expect(convHits(convPhp(['app', 'routes', 'database']), '/\benv\(/'))->toBe([]);
});

/* |---| Database |---| */

it('lets seeders write only through PortSeeder::upsert()', function () {
    // Model::create() drops actor columns silently, Laravel upsert() and
    // insertOrIgnore bypass BaseModel's ULID and DATETIME(3) handling, and
    // WithoutModelEvents skips the minting. The demo accounts (forceFill +
    // save) and ProjectSeeder's syncAssignees() are the documented exceptions
    // and match none of these tokens.
    $hits = array_values(array_filter(
        convHits(convPhp(['database/seeders']), '/::create\(|::upsert\(|insertOrIgnore|WithoutModelEvents/'),
        static fn (string $hit): bool => ! str_contains($hit, 'PortSeeder::upsert('),
    ));

    expect($hits)->toBe([]);
});

it('keeps application migrations off the starter-kit column shortcuts', function () {
    // $table->id() is a bigint, timestamps() is whole-second, foreignId() is
    // bigint again, ENUM cannot be altered on MariaDB without a rebuild, and
    // there are no SoftDeletes anywhere. The framework's own migrations are
    // exempt by nature: users.id stays bigint on purpose.
    $starter = array_map(
        static fn (string $path): string => 'database/migrations/'.basename($path),
        glob(database_path('migrations/0001_01_01_*.php')) ?: [],
    );
    $starter[] = 'database/migrations/2024_01_01_000000_create_passkeys_table.php';

    $sources = convPhp(['database/migrations'], $starter);

    expect(convHits($sources, '/->(id|timestamps|softDeletes)\(\)|->(foreignId|enum)\(|\bSoftDeletes\b/'))->toBe([]);

    // Every string column states its length; ->change() on a framework column
    // keeps that column's own definition and is the one place a bare string()
    // is right.
    $bareStrings = array_values(array_filter(
        convHits($sources, '/->string\(\s*[\'"][^\'"]+[\'"]\s*\)/'),
        static fn (string $hit): bool => ! str_contains($hit, '->change()'),
    ));

    expect($bareStrings)->toBe([], 'Give every string column an explicit length.');
});

it('never puts a unique index on users.employee_id', function () {
    // One employee may hold many active accounts. A unique index here would
    // refuse the second login and, worse, look like a bug fix.
    $files = array_filter(
        convPhp(['database/migrations']),
        static fn (string $src, string $path): bool => str_contains(basename($path), 'users'),
        ARRAY_FILTER_USE_BOTH,
    );
    $hits = array_values(array_filter(
        convHits($files, '/employee_id/'),
        static fn (string $hit): bool => preg_match('/unique\(/', $hit) === 1,
    ));

    expect($hits)->toBe([]);
});

/* |---| UI |---| */

it('uses hris-table and semantic tokens only in the app\'s own views', function () {
    // Starter-kit views under pages/ and the passkey/auth components use the
    // zinc palette and dark: variants; the app's own views never do.
    $views = convBlade(['livewire', 'components/kit', 'layouts', 'partials']);

    expect(convHits($views, '/flux:table\b/'))->toBe([], 'Every table is a raw <table class="hris-table">.')
        ->and(convHits($views, '/\bdark:/'))->toBe([], 'Dark mode redefines the tokens under .dark; no dark: variants.')
        ->and(convHits($views, '/\b(bg|text|border|ring|divide|from|to)-(zinc|gray|slate|neutral|stone|red|green|blue|amber|emerald|yellow|orange|sky|indigo|violet|rose)-\d{2,3}\b/'))
        ->toBe([], 'Semantic tokens (bg-card, text-muted-foreground, text-danger, …) only — no palette classes.');
});

it('draws every dropdown with <x-kit.select>, never the browser\'s own list', function () {
    // The kit select keeps a hidden native <select> for the bindings and
    // draws its own list, the employee picker's look. A flux:select or a raw
    // <select> anywhere else brings the browser's list back.
    $views = convBlade(['']);
    unset($views['components/kit/select.blade.php']);

    expect(convHits($views, '/<flux:select(?![.\w-])/'))->toBe([], 'Use <x-kit.select> (same attributes, same <flux:select.option> children).')
        ->and(convHits($views, '/<select\b/'))->toBe([], 'Use <x-kit.select>; only the kit select draws a native <select>.');
});

it('polls only through pollForChanges, at 60s or the feed cadence', function () {
    // Every tick is a stamp read (WithChangeStamp); anything else costs a row
    // query per tick per open tab against the host's few PHP entry processes.
    // The two punch feeds take their interval from LiveFeed::pollSeconds(),
    // and the open Chat page from ChatPage::pollSeconds(), the same rule. No
    // other view may use the feed cadence: the chat badge sits on every page,
    // so it keeps the standard 60s.
    $views = convBlade(['livewire', 'components', 'layouts', 'partials', 'pages']);
    $polls = convHits($views, '/wire:poll/');
    $sanctioned = '/wire:poll(\.visible)?\.(60s|\{\{ \$pollSeconds \}\}s)="pollForChanges"/';
    $feeds = ['livewire/edtr/live-feed.blade.php', 'livewire/portal/my-punches.blade.php', 'livewire/chat/chat-page.blade.php'];

    $offenders = array_values(array_filter($polls, static fn (string $hit): bool => preg_match($sanctioned, $hit) !== 1
        || (str_contains($hit, '$pollSeconds') && ! in_array(strstr($hit, ':', true), $feeds, true))));

    expect($offenders)->toBe([], 'wire:poll must call pollForChanges at 60s, or at {{ $pollSeconds }}s on the two punch feeds and the Chat page.');

    // The count observed on a green run (bell, inbox, live feed, my punches,
    // community, chat page, chat badge). A drop means a screen stopped
    // polling or the scan stopped seeing it.
    expect(count($polls))->toBeGreaterThanOrEqual(7);
});

/* |---| Module registry |---| */

it('keeps ModuleCode::all() in its literal order', function () {
    // Order is behaviour: the sidebar, the landing page after sign-in and
    // forPath() all follow it. ArchitectureTest checks that config/modules.php
    // agrees with all(); this pins the list itself, so a sort() that moved
    // both together still fails. COMMUNITY first (the sign-in landing page),
    // PORTAL before TASKS, PROFILE last.
    expect(ModuleCode::all())->toBe([
        ModuleCode::COMMUNITY,
        ModuleCode::EMPLOYEES,
        ModuleCode::EDTR,
        ModuleCode::ROSTER,
        ModuleCode::LEAVES,
        ModuleCode::PROJECTS,
        ModuleCode::INVENTORY,
        ModuleCode::PAYROLL,
        ModuleCode::REPORTS,
        ModuleCode::RECORDS,
        ModuleCode::USERS,
        ModuleCode::AUDIT,
        ModuleCode::PORTAL,
        ModuleCode::TASKS,
        ModuleCode::CHAT,
        ModuleCode::PROFILE,
    ]);
});

/* |---| Reflection over the components and models |---| */

it('locks every id-bearing public property on a component, or documents why not', function () {
    // #[Locked] is what stops a client from swapping the id an action will
    // act on. The two sanctioned exceptions — a wire:model draft the save
    // re-validates, and a filter applied after row scoping — each need a
    // comment saying so. This reads that comment: an unlocked *Id property
    // must carry a docblock that mentions "Locked".
    $offenders = [];
    $locked = 0;

    foreach (convComponents() as $class) {
        $reflection = new ReflectionClass($class);
        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->getDeclaringClass()->getName() !== $class || preg_match('/(Id|Ids)$/', $property->getName()) !== 1) {
                continue;
            }
            if ($property->getAttributes(Locked::class) !== []) {
                $locked++;

                continue;
            }
            $doc = $property->getDocComment();
            if ($doc === false || stripos($doc, 'locked') === false) {
                $offenders[] = $class.'::$'.$property->getName();
            }
        }
    }

    expect($offenders)->toBe([], 'An id-bearing public property is neither #[Locked] nor documented as a sanctioned exception.');

    // 102 observed on a green run (including EmployeeSchedulePage::$employeeId,
    // LeaveCredits::$creditsEmployeeId, the WorkManagement submission-mode set,
    // and IncentiveTypesPage::$editingId/$deletingId — RunWorkspace's locked
    // $incentivesFor is outside the *Id scan); a drop means a locked id
    // property was removed or renamed — check that it was deliberate.
    expect($locked)->toBeGreaterThanOrEqual(
        102,
        "Found {$locked} #[Locked] id properties, below the pinned floor.",
    );
});

it('resolves Access inside every Livewire action that writes', function () {
    // The module gate persists on HTTP updates, but direct operations must
    // still resolve their actor. "Writes" is decided by
    // the body: a save/update/delete/insert/create/sync, a DB::transaction, a
    // Storage call, an audit record, or a module operation's handle/apply
    // call. Extracting an operation must not make its screen disappear from
    // this check. "Resolves the actor" is
    // the class name Access — a static call, app(Access::class) or an
    // injected parameter — in the action itself or in a helper the class
    // declares or composes from a trait, one level down ($this->access(),
    // $this->authorizeModule(), OwnsInbox::own()). Actions are the public
    // methods the class declares itself or takes from one of the app's own
    // traits; a vendor trait's public methods are Livewire plumbing, and the
    // lifecycle hooks are not actions.
    $lifecycle = ['mount', 'render', 'boot', 'booted', 'hydrate', 'dehydrate', 'rendering', 'rendered', 'exception'];
    $writes = '/->(handle|save|saveQuietly|update|updateOrCreate|updateOrInsert|upsert|delete|forceDelete|insert|create|firstOrCreate|sync\w*|attach|detach|touch)\(|DB::transaction\(|\bStorage::|::create\(|AuditService::record\(|CorrectPunches::apply\(|PasswordWriter::class/';
    $appRoot = str_replace(DIRECTORY_SEPARATOR, '/', rtrim(app_path(), '/')).'/';

    /** @var array<string, list<string>> $lines comment-stripped source lines, by file */
    $lines = [];
    $body = static function (ReflectionMethod $method) use (&$lines): string {
        $file = (string) $method->getFileName();
        $lines[$file] ??= explode("\n", convStripPhp((string) file_get_contents($file)));
        $start = $method->getStartLine();
        $end = $method->getEndLine();
        if ($start === false || $end === false) {
            return '';
        }

        return implode("\n", array_slice($lines[$file], $start - 1, $end - $start + 1));
    };

    $offenders = [];
    $checked = 0;

    foreach (convComponents() as $class) {
        /** @var array<string, ReflectionMethod> $declared every method the class declares or composes from a trait */
        $declared = [];
        foreach ((new ReflectionClass($class))->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() === $class) {
                $declared[$method->getName()] = $method;
            }
        }

        foreach ($declared as $name => $method) {
            if (! $method->isPublic() || $method->isStatic() || in_array($name, $lifecycle, true)) {
                continue;
            }
            if (! str_starts_with(str_replace(DIRECTORY_SEPARATOR, '/', (string) $method->getFileName()), $appRoot)) {
                continue;
            }
            $source = $body($method);
            preg_match_all('/\$this->(\w+)\(/', $source, $calls);
            foreach (array_unique($calls[1]) as $callee) {
                if ($callee !== $name && isset($declared[$callee])) {
                    $source .= "\n".$body($declared[$callee]);
                }
            }
            $callsOperation = false;
            preg_match_all('/^use ([^;]+);/m', (string) file_get_contents((string) $method->getFileName()), $imports);
            foreach ($imports[1] as $import) {
                [$qualified, $alias] = array_pad(explode(' as ', $import, 2), 2, null);
                if (str_contains($qualified, '\\Application\\Commands\\') || in_array($qualified, [Inbox::class, ReferenceCatalogs::class], true)) {
                    $alias ??= substr($qualified, (int) strrpos($qualified, '\\') + 1);
                    $callsOperation = $callsOperation || str_contains($source, $alias.'::class');
                }
            }
            $callsOperation = $callsOperation || str_contains($source, '\\Application\\Commands\\');
            $callsOperation = $callsOperation || str_contains($source, '\\Platform\\Notifications\\Inbox::class');
            if (! $callsOperation && preg_match($writes, $source) !== 1) {
                continue;
            }
            $checked++;
            if (preg_match('/\bAccess\b/', $source) !== 1) {
                $offenders[] = $class.'::'.$name.'()';
            }
        }
    }

    expect($offenders)->toBe([], 'A Livewire action writes without resolving Access. Re-check mayReadAllData()/hasModule() or ownership at the top of the action.');

    // 178 observed on a green run (including EmployeeSchedulePage::saveDays()/
    // saveDefaultSchedule()/confirmReview(), LeaveCredits::saveCredits(), the
    // WorkManagement submission-mode set, the DTR's four overtime decisions,
    // IncentiveTypesPage::save()/deleteConfirmed() and ShiftTypesPage::save()/
    // confirmReview()); a drop means a writing action disappeared or stopped
    // being recognised as one — check that it was deliberate.
    expect($checked)->toBeGreaterThanOrEqual(178, "Found {$checked} writing actions, below the pinned floor.");
});

it('keeps domain models on BaseModel and users.id on bigint', function () {
    // BaseModel means a ULID char(26) key and millisecond timestamps — the
    // migrations depend on both. users.id is the one bigint, on purpose, and
    // must never be unified with the ULID ids.
    $checked = 0;

    foreach (glob(app_path('Models/*.php')) ?: [] as $path) {
        $class = 'App\\Models\\'.basename($path, '.php');
        if ($class === BaseModel::class) {
            continue;
        }
        /** @var Model $model */
        $model = new $class;
        $checked++;

        if ($class === User::class) {
            expect($model->getKeyType())->toBe('int')
                ->and($model->getIncrementing())->toBeTrue();

            continue;
        }

        expect($model)->toBeInstanceOf(BaseModel::class)
            ->and($model->getKeyType())->toBe('string', "{$class} must use the ULID key")
            ->and($model->getIncrementing())->toBeFalse("{$class} must not auto-increment");
    }

    expect($checked)->toBeGreaterThan(25);
});
