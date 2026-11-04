<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Contracts\AccountNames;
use App\Modules\IdentityAccess\Contracts\ModuleAccounts;
use App\Modules\IdentityAccess\Contracts\ProjectAccounts;
use App\Modules\Organization\Contracts\HolidayCalendar;
use App\Modules\Organization\Contracts\OrganizationDirectory;
use App\Modules\Organization\Contracts\OrganizationReferences;
use App\Modules\Organization\Contracts\PunchSites;
use App\Modules\Timekeeping\Contracts\AttendanceSource;
use App\Modules\Timekeeping\Contracts\LeaveCashOutPoster;
use App\Modules\Timekeeping\Contracts\LeaveCashOutSource;
use App\Modules\Timekeeping\Contracts\LeaveChargePoster;
use App\Modules\Timekeeping\Contracts\LeaveChargeSource;
use App\Modules\Timekeeping\Contracts\OpenPayrollDrafts;
use App\Modules\Timekeeping\Contracts\OpenPunchLimit;
use App\Modules\Timekeeping\Contracts\PostedPayrollDates;
use App\Modules\Timekeeping\Contracts\ScheduleNotices;
use App\Modules\Workforce\Contracts\AssignmentEmployees;
use App\Modules\Workforce\Contracts\ColleagueDirectory;
use App\Modules\Workforce\Contracts\EmployeeDirectory;
use App\Modules\Workforce\Contracts\EmployeeLeaveFacts;
use App\Modules\Workforce\Contracts\EmployeePayContexts;
use App\Modules\Workforce\Contracts\InventoryEmployees;
use App\Modules\Workforce\Contracts\OperationalDirectory;
use App\Modules\Workforce\Contracts\PunchEmployees;
use App\Platform\Notifications\NotificationRecipients;
use App\Support\Access;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use Tests\Support\LegacyDependencies;

/** New module code cannot grow the legacy screen-to-model coupling. */

/** @return array<string, list<string>> */
function boundaryReferences(): array
{
    $parser = (new ParserFactory)->createForNewestSupportedVersion();
    $finder = new NodeFinder;
    $result = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Modules'), FilesystemIterator::SKIP_DOTS)) as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $nodes = $parser->parse((string) file_get_contents($file->getPathname()));
        $nodes = (new NodeTraverser(new NameResolver))->traverse($nodes ?? []);
        $names = $finder->findInstanceOf($nodes, Node\Name::class);
        $path = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen(app_path('Modules')) + 1));
        $result[$path] = array_values(array_unique(array_map(static fn (Node\Name $name): string => $name->toString(), $names)));
    }

    return $result;
}

it('resolves every cross module query contract through the composition root', function () {
    foreach ([EmployeeDirectory::class, EmployeePayContexts::class, EmployeeLeaveFacts::class, AttendanceSource::class, HolidayCalendar::class,
        AssignmentEmployees::class,
        PunchEmployees::class,
        OrganizationReferences::class,
        PunchSites::class,
        ProjectAccounts::class,
        OpenPunchLimit::class,
        PostedPayrollDates::class,
        LeaveChargeSource::class,
        LeaveChargePoster::class,
        LeaveCashOutSource::class,
        LeaveCashOutPoster::class,
        OpenPayrollDrafts::class,
        ScheduleNotices::class,
        NotificationRecipients::class,
        OperationalDirectory::class,
        InventoryEmployees::class,
        OrganizationDirectory::class,
        AccountNames::class,
        // Community and Chat's two.
        ModuleAccounts::class,
        ColleagueDirectory::class,
    ] as $contract) {
        expect(app()->bound($contract))->toBeTrue();
        expect(array_values(class_implements(app($contract)) ?: []))->toContain($contract);
    }
});

it('requires an explicit actor on every public business command', function () {
    $checked = 0;
    foreach (glob(app_path('Modules/*/Application/Commands/*.php')) ?: [] as $file) {
        $class = 'App\\'.str_replace('/', '\\', substr($file, strlen(app_path()) + 1, -4));
        if (! class_exists($class)) {
            throw new LogicException('Missing command class: '.$class);
        }
        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isConstructor()) {
                continue;
            }
            $parameter = $method->getParameters()[0] ?? null;
            expect($parameter?->getType()?->__toString())->toBe(Access::class, $class.'::'.$method->name);
            $checked++;
        }
    }
    expect($checked)->toBeGreaterThanOrEqual(40);
});

it('keeps persistence mutations out of Livewire adapters', function () {
    $parser = (new ParserFactory)->createForNewestSupportedVersion();
    $finder = new NodeFinder;
    $offenders = [];
    $mutators = ['save', 'saveQuietly', 'delete', 'forceDelete', 'update', 'create', 'insert', 'insertOrIgnore', 'upsert', 'updateOrCreate', 'firstOrCreate', 'sync', 'syncAssignees', 'put', 'store', 'storeAs'];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Livewire'), FilesystemIterator::SKIP_DOTS)) as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $nodes = (new NodeTraverser(new NameResolver))->traverse($parser->parse((string) file_get_contents($file->getPathname())) ?? []);
        foreach ($finder->find($nodes, static fn (Node $n): bool => $n instanceof Node\Expr\MethodCall || $n instanceof Node\Expr\NullsafeMethodCall) as $call) {
            if (! $call instanceof Node\Expr\MethodCall && ! $call instanceof Node\Expr\NullsafeMethodCall) {
                continue;
            }
            if (! $call->name instanceof Node\Identifier || ! in_array($call->name->toString(), $mutators, true)) {
                continue;
            }
            $receiver = $call->var;
            while ($receiver instanceof Node\Expr\MethodCall || $receiver instanceof Node\Expr\NullsafeMethodCall) {
                $receiver = $receiver->var;
            }
            $service = $receiver instanceof Node\Expr\FuncCall && $receiver->name instanceof Node\Name && $receiver->name->toString() === 'app' ? ($receiver->args[0]->value ?? null) : null;
            $qualified = $service instanceof Node\Expr\ClassConstFetch && $service->class instanceof Node\Name ? $service->class->toString() : '';
            if (! str_starts_with($qualified, 'App\\Modules\\') && ! str_starts_with($qualified, 'App\\Platform\\')) {
                $offenders[] = substr($file->getPathname(), strlen(base_path()) + 1).':'.$call->getStartLine();
            }
        }
    }
    expect($offenders)->toBe([]);
});

it('keeps module operations independent of Livewire and other modules internals', function () {
    $offenders = [];
    $files = boundaryReferences();
    foreach ($files as $path => $references) {
        $owner = explode('/', $path)[0];
        foreach ($references as $name) {
            if (str_starts_with($name, 'App\\Livewire\\') || str_starts_with($name, 'App\\Http\\Controllers\\')
                || str_starts_with($name, 'Livewire\\') || str_starts_with($name, 'Flux\\')) {
                $offenders[] = $path.' -> '.$name;
            }
            if (str_starts_with($name, 'App\\Modules\\')) {
                $parts = explode('\\', $name);
                if ($parts[2] !== $owner && ($parts[3] ?? '') !== 'Contracts') {
                    $offenders[] = $path.' -> '.$name;
                }
            }
        }
    }
    expect(count($files))->toBeGreaterThanOrEqual(20);
    expect($offenders)->toBe([]);
});

it('allows module code to access only its own persistence models', function () {
    /** @var array<string, list<string>> $ownership */
    $ownership = config('architecture.models');
    $offenders = [];
    foreach (boundaryReferences() as $path => $references) {
        $owner = explode('/', $path)[0];
        foreach ($references as $name) {
            if (str_starts_with($name, 'App\\Models\\')
                && ! in_array(substr($name, strlen('App\\Models\\')), $ownership[$owner] ?? [], true)) {
                $offenders[] = $path.' -> '.$name;
            }
        }
    }
    expect($offenders)->toBe([]);
});

it('does not bypass model ownership with raw table access or joins', function () {
    $tables = [];
    foreach (config('architecture.models') as $owner => $models) {
        foreach ($models as $model) {
            $class = 'App\\Models\\'.$model;
            if (! is_subclass_of($class, Model::class)) {
                throw new LogicException('Unknown owned model: '.$class);
            }
            $tables[$owner][] = (new $class)->getTable();
        }
    }
    $tables['IdentityAccess'][] = 'sessions';
    $tables['Payroll'][] = 'locks';
    $parser = (new ParserFactory)->createForNewestSupportedVersion();
    $finder = new NodeFinder;
    $offenders = [];
    foreach (array_keys(boundaryReferences()) as $path) {
        $owner = explode('/', $path)[0];
        $nodes = $parser->parse((string) file_get_contents(app_path('Modules/'.$path))) ?? [];
        foreach ($finder->find($nodes, static fn (Node $node): bool => $node instanceof Node\Expr\StaticCall || $node instanceof Node\Expr\MethodCall) as $call) {
            if (! $call instanceof Node\Expr\StaticCall && ! $call instanceof Node\Expr\MethodCall) {
                continue;
            }
            if (! $call->name instanceof Node\Identifier || ! in_array($call->name->toString(), ['table', 'join', 'leftJoin', 'rightJoin', 'crossJoin'], true)) {
                continue;
            }
            $table = $call->args[0]->value ?? null;
            if (! $table instanceof Node\Scalar\String_ || ! in_array($table->value, $tables[$owner] ?? [], true)) {
                $offenders[] = $path.':'.$call->getStartLine();
            }
        }
    }
    expect($offenders)->toBe([]);
});

it('keeps contracts free of writable persistence and domain rules free of framework IO', function () {
    $offenders = [];
    foreach (boundaryReferences() as $path => $references) {
        if (! str_contains($path, '/Contracts/') && ! str_contains($path, '/Domain/')) {
            continue;
        }
        foreach ($references as $name) {
            if (str_starts_with($name, 'App\\Models\\') || str_starts_with($name, 'Illuminate\\Database\\')
                || str_starts_with($name, 'Illuminate\\Support\\Facades\\')) {
                $offenders[] = $path.' -> '.$name;
            }
        }
    }
    expect($offenders)->toBe([]);
});

it('has no dependency cycle between business modules', function () {
    $edges = [];
    foreach (boundaryReferences() as $path => $references) {
        $owner = explode('/', $path)[0];
        foreach ($references as $name) {
            if (str_starts_with($name, 'App\\Modules\\')) {
                $target = explode('\\', $name)[2];
                if ($target !== $owner) {
                    $edges[$owner][$target] = true;
                }
            }
        }
    }
    $visit = function (string $module, array $ancestors) use (&$visit, $edges): void {
        expect(in_array($module, $ancestors, true))->toBeFalse('Cycle: '.implode(' -> ', [...$ancestors, $module]));
        foreach (array_keys($edges[$module] ?? []) as $target) {
            $visit($target, [...$ancestors, $module]);
        }
    };
    foreach (array_keys($edges) as $module) {
        $visit($module, []);
    }
});

it('does not eager-load another module through legacy Eloquent relationships', function () {
    $foreign = [];
    foreach (config('architecture.models') as $owner => $models) {
        foreach ($models as $model) {
            $class = 'App\\Models\\'.$model;
            if (! is_subclass_of($class, Model::class)) {
                throw new LogicException('Unknown model '.$class);
            }
            foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                $type = $method->getReturnType();
                if ($method->getDeclaringClass()->name !== $class || $method->getNumberOfRequiredParameters() !== 0
                    || ! $type instanceof ReflectionNamedType
                    || ! is_a($type->getName(), Relation::class, true)) {
                    continue;
                }
                $relation = $method->invoke(new $class);
                if (! $relation instanceof Relation) {
                    continue;
                }
                if (! in_array(class_basename($relation->getRelated()), $models, true)) {
                    $foreign[$owner][$method->name] = true;
                }
            }
        }
    }
    $parser = (new ParserFactory)->createForNewestSupportedVersion();
    $finder = new NodeFinder;
    $offenders = [];
    foreach (array_keys(boundaryReferences()) as $path) {
        $owner = explode('/', $path)[0];
        $nodes = $parser->parse((string) file_get_contents(app_path('Modules/'.$path))) ?? [];
        foreach ($finder->findInstanceOf($nodes, Node\Expr\MethodCall::class) as $call) {
            if (! $call->name instanceof Node\Identifier || ! in_array($call->name->toString(), ['with', 'load', 'loadMissing', 'whereHas', 'whereDoesntHave'], true)) {
                continue;
            }
            $argument = $call->args[0]->value ?? null;
            $strings = $argument instanceof Node\Scalar\String_ ? [$argument] : ($argument instanceof Node\Expr\Array_ ? $finder->findInstanceOf([$argument], Node\Scalar\String_::class) : []);
            foreach ($strings as $string) {
                foreach (explode('.', explode(':', $string->value)[0]) as $name) {
                    if (isset($foreign[$owner][$name])) {
                        $offenders[] = $path.':'.$call->getStartLine().' -> '.$string->value;
                    }
                }
            }
        }
    }
    expect($offenders)->toBe([]);
});

it('assigns every existing persisted business model exactly one owner', function () {
    /** @var array<string, list<string>> $ownership */
    $ownership = config('architecture.models');
    $owned = array_merge(...array_values($ownership));
    $actual = array_values(array_filter(array_map(static fn (string $file): string => basename($file, '.php'), glob(app_path('Models/*.php')) ?: []), static fn (string $name): bool => $name !== 'BaseModel'));
    sort($owned);
    sort($actual);
    expect($owned)->toBe($actual);
});

it('keeps direct model and storage operations out of business presentation including Blade', function () {
    expect(LegacyDependencies::scan(base_path()))->toBe([], 'Presentation reads and writes belong to an owning module query or command.');
});

it('does not return writable persistence from public business queries or commands', function () {
    $checked = 0;
    foreach (['Queries', 'Commands'] as $area) {
        foreach (glob(app_path('Modules/*/Application/'.$area.'/*.php')) ?: [] as $file) {
            $class = 'App\\'.str_replace('/', '\\', substr($file, strlen(app_path()) + 1, -4));
            if (! class_exists($class)) {
                throw new LogicException('Missing application operation: '.$class);
            }
            foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->isConstructor()) {
                    continue;
                }
                $type = $method->getReturnType();
                $types = $type instanceof ReflectionUnionType ? $type->getTypes() : [$type];
                foreach ($types as $part) {
                    if (! $part instanceof ReflectionNamedType || $part->isBuiltin()) {
                        continue;
                    }
                    $name = $part->getName();
                    expect(is_a($name, Model::class, true)
                        || is_a($name, Builder::class, true)
                        || is_a($name, Illuminate\Database\Query\Builder::class, true))
                        ->toBeFalse($class.'::'.$method->name);
                }
                $checked++;
            }
        }
    }
    expect($checked)->toBeGreaterThanOrEqual(60);
});
