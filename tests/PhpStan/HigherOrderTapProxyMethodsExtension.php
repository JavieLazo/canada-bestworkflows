<?php

declare(strict_types=1);

namespace Tests\PhpStan;

use Illuminate\Foundation\Testing\TestCase;
use Pest\Support\HigherOrderTapProxy;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\MethodsClassReflectionExtension;
use PHPStan\Reflection\ReflectionProvider;

/**
 * PHPStan-only. Teaches the analyser that Pest's no-argument `test()` proxy
 * forwards every method call to the test case that is currently running.
 *
 * `test()` with no description returns a `HigherOrderTapProxy` whose `__call`
 * hands the call to the running test case, and Pest declares no `@mixin` on
 * it. So `test()->actingAs($user)` inside a global fixture helper — the form
 * `testing.md` prescribes, because a helper function has no `$this` — reads to
 * PHPStan as a call to an undefined method. pest-plugin-phpstan types `$this`
 * inside closures but not this proxy; seventeen such calls survived it.
 *
 * A stub with `@mixin` cannot express this. PHPStan validates stub PHPDocs
 * with a source locator that sees only other stubs, its own classes and PHP's
 * internals (StubSourceLocatorFactory::create), so any project or vendor class
 * named inside a stub is "unknown", and `scanFiles` is not consulted there.
 * This extension is the mechanism PHPStan provides for exactly this shape, and
 * the one the Pest plugin itself uses for `TestCall`.
 *
 * It is a type correction, not an ignore: a method Laravel's TestCase does not
 * have still fails. Laravel's base class rather than Tests\TestCase because it
 * owns everything the helpers reach through the proxy — actingAs, travelTo,
 * get, assertDatabaseHas — and Tests\TestCase adds nothing they call.
 *
 * Registered in phpstan.neon under `services`. Never executed by the suite.
 */
final class HigherOrderTapProxyMethodsExtension implements MethodsClassReflectionExtension
{
    public function __construct(private readonly ReflectionProvider $reflectionProvider) {}

    public function hasMethod(ClassReflection $classReflection, string $methodName): bool
    {
        return $classReflection->getName() === HigherOrderTapProxy::class
            && $this->reflectionProvider->getClass(TestCase::class)->hasMethod($methodName);
    }

    public function getMethod(ClassReflection $classReflection, string $methodName): MethodReflection
    {
        return $this->reflectionProvider->getClass(TestCase::class)->getNativeMethod($methodName);
    }
}
