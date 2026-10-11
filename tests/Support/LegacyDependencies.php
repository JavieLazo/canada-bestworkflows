<?php

declare(strict_types=1);

namespace Tests\Support;

use FilesystemIterator;
use Illuminate\Support\Facades\Blade;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/** Measures legacy model/IO calls so extraction can reduce, but never silently grow, coupling. */
final class LegacyDependencies
{
    /** @return array<string, array<string, int>> */
    public static function scan(string $root): array
    {
        $parser = (new ParserFactory)->createForNewestSupportedVersion();
        $finder = new NodeFinder;
        $result = [];
        foreach (['app/Livewire', 'app/Http/Controllers', 'resources/views'] as $area) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$area, FilesystemIterator::SKIP_DOTS)) as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $source = (string) file_get_contents($file->getPathname());
                if ($area === 'resources/views') {
                    $source = Blade::compileString($source);
                }
                $nodes = $parser->parse($source);
                $nodes = (new NodeTraverser(new NameResolver))->traverse($nodes ?? []);
                $calls = $finder->find($nodes, static fn (Node $node): bool => $node instanceof Node\Expr\StaticCall
                    || $node instanceof Node\Expr\New_ || $node instanceof Node\Expr\ClassConstFetch);
                $path = substr($file->getPathname(), strlen($root) + 1);
                foreach ($calls as $call) {
                    if (! ($call instanceof Node\Expr\StaticCall || $call instanceof Node\Expr\New_ || $call instanceof Node\Expr\ClassConstFetch)
                        || ! $call->class instanceof Node\Name) {
                        continue;
                    }
                    $name = $call->class->toString();
                    if (str_starts_with($name, 'App\\Models\\') || in_array($name, ['Illuminate\\Support\\Facades\\DB', 'Illuminate\\Support\\Facades\\Storage'], true)) {
                        $result[$path][$name] = ($result[$path][$name] ?? 0) + 1;
                    }
                }
                if (isset($result[$path])) {
                    ksort($result[$path]);
                }
            }
        }
        ksort($result);

        return $result;
    }
}
