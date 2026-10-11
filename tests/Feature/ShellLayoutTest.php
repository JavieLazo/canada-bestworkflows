<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Support\Access;

/**
 * The app shell is a grid from the body's FIRST byte. Flux only turns the body
 * into its grid once `*:has(>[data-flux-main])` (flux.css) sees `<flux:main>`,
 * which comes after the sidebar and header. The live host delivers a page in
 * pieces and the browser paints between them, so without `hris-shell` the
 * frame before `main` arrives stacks the header UNDER the sidebar, near the
 * bottom of the screen. The utility copies Flux's own sidebar-first
 * template, so the two must stay equal or the page jumps when Flux's rule
 * takes over.
 */

/** A signed-in account with one module grant, and a fresh Access for it. */
function shellLayoutUser(): User
{
    $role = Role::create(['name' => 'Shell layout tester', 'all_data' => false, 'is_system' => false]);
    RoleModuleGrant::create(['role_id' => $role->id, 'module' => 'PORTAL']);
    $user = User::factory()->create(['role_id' => $role->id]);

    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return $user;
}

/** The body of the first CSS rule headed $header, up to its closing brace. */
function shellLayoutRule(string $css, string $header): string
{
    $at = strpos($css, $header);
    if ($at === false) {
        throw new RuntimeException("No CSS rule headed {$header}.");
    }
    $end = strpos($css, '}', $at);

    return substr($css, $at, ($end === false ? strlen($css) : $end) - $at);
}

/** One declaration from that rule, whitespace and quote style normalised. */
function shellLayoutDeclaration(string $css, string $header, string $property): string
{
    if (preg_match('/[{;\s]'.preg_quote($property, '/').':\s*([^;]+);/', shellLayoutRule($css, $header), $match) !== 1) {
        throw new RuntimeException("No {$property} in the rule headed {$header}.");
    }

    return (string) preg_replace('/\s+/', ' ', str_replace("'", '"', trim($match[1])));
}

/**
 * Every property that rule declares, sorted.
 *
 * @return list<string>
 */
function shellLayoutProperties(string $css, string $header): array
{
    preg_match_all('/[{;]\s*([a-z-]+)\s*:/', shellLayoutRule($css, $header), $matches);
    $names = $matches[1];
    sort($names);

    return $names;
}

it('puts the shell grid on the body, with the header straight after the sidebar', function () {
    shellLayoutUser();

    $html = $this->get(route('notifications.index'))->assertOk()->getContent();
    if ($html === false) {
        throw new RuntimeException('The shell page rendered no body to inspect.');
    }
    if (preg_match('/<body class="([^"]*)"/', $html, $body) !== 1) {
        throw new RuntimeException('The shell page rendered no <body class>.');
    }

    // hris-shell copies Flux's SIDEBAR-FIRST areas, which Flux itself only
    // applies while the header is the sidebar's next sibling.
    expect(explode(' ', $body[1]))->toContain('hris-shell')
        ->and(preg_match('/<\/ui-sidebar>\s*<header\b[^>]*\bdata-flux-header\b/', $html))->toBe(1);
});

it('keeps hris-shell on the same template as Flux\'s sidebar-first shell', function () {
    $app = (string) file_get_contents(resource_path('css/app.css'));
    $flux = (string) file_get_contents(base_path('vendor/livewire/flux/dist/flux.css'));

    // A Flux upgrade that adds, drops or renames a declaration here has to be
    // reviewed against hris-shell rather than pass by omission. `grid-area:
    // body` is the one hris-shell leaves out: the body never sits in a grid.
    expect(shellLayoutProperties($flux, '*:has(>[data-flux-main])'))
        ->toBe(['display', 'grid-area', 'grid-template-areas', 'grid-template-columns', 'grid-template-rows']);

    // Display, rows and columns come from Flux's base shell rule, the areas
    // from its sidebar-before-header override.
    foreach (['display', 'grid-template-rows', 'grid-template-columns'] as $property) {
        expect(shellLayoutDeclaration($app, '@utility hris-shell', $property))
            ->toBe(shellLayoutDeclaration($flux, '*:has(>[data-flux-main])', $property));
    }
    expect(shellLayoutDeclaration($app, '@utility hris-shell', 'grid-template-areas'))
        ->toBe(shellLayoutDeclaration($flux, '*:has(>[data-flux-sidebar]+[data-flux-header])', 'grid-template-areas'));
});
