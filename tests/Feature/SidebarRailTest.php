<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Support\Access;

/**
 * Collapsed, the sidebar is a rail of icons that opens OVER the page while the
 * pointer is on it, so a module drawn as a tree can list its pages. Three
 * things hold that up, and none of them fails loudly in a browser:
 *
 *  - whatever is drawn only in the rail carries the `sidebar-rail` variant.
 *    Flux's `in-data-flux-sidebar-collapsed-desktop:` still matches while the
 *    sidebar is open over the page, and would leave it a wide strip of icons;
 *  - the open sidebar takes Flux's full width and gives the difference back as
 *    a negative margin, so the page under it does not move. Both widths are
 *    Flux's, restated in app.css, and a Flux upgrade can move them;
 *  - both rules ask for a device that hovers. A touch screen has no pointer to
 *    leave, so there the rail has to stay a rail.
 */

/** A signed-in account holding a tree module, a plain one, Chat and My Profile. */
function sidebarRailActor(): void
{
    $role = Role::create(['name' => 'Sidebar rail tester', 'all_data' => false, 'is_system' => false]);
    foreach ([ModuleCode::PORTAL, ModuleCode::RECORDS, ModuleCode::CHAT, ModuleCode::PROFILE] as $module) {
        RoleModuleGrant::create(['role_id' => $role->id, 'module' => $module]);
    }

    test()->actingAs(User::factory()->create(['role_id' => $role->id]));
    app()->forgetInstance(Access::class);
}

/**
 * The sidebar element of a rendered shell page: its opening tag, and what is
 * inside it.
 *
 * @return array{tag: string, inside: string}
 */
function sidebarRailMarkup(string $html): array
{
    if (preg_match('/<ui-sidebar\s[^>]*>/', $html, $tag, PREG_OFFSET_CAPTURE) !== 1) {
        throw new RuntimeException('The shell page rendered no sidebar.');
    }
    $from = $tag[0][1] + strlen($tag[0][0]);
    $to = strpos($html, '</ui-sidebar>', $from);
    if ($to === false) {
        throw new RuntimeException('The sidebar is never closed.');
    }

    return ['tag' => $tag[0][0], 'inside' => substr($html, $from, $to - $from)];
}

/** The block that starts at the first $header in $css, braces and all. */
function sidebarRailBlock(string $css, string $header): string
{
    $at = strpos($css, $header);
    $open = $at === false ? false : strpos($css, '{', $at);
    if ($at === false || $open === false) {
        throw new RuntimeException("No CSS block headed {$header}.");
    }

    $depth = 0;
    for ($i = $open, $length = strlen($css); $i < $length; $i++) {
        $depth += match ($css[$i]) {
            '{' => 1,
            '}' => -1,
            default => 0,
        };
        if ($depth === 0) {
            return (string) preg_replace('/\s+/', ' ', substr($css, $at, $i - $at + 1));
        }
    }

    throw new RuntimeException("The CSS block headed {$header} is never closed.");
}

it('draws the rail-only parts of the sidebar with the rail variant', function () {
    sidebarRailActor();

    $html = $this->get(route('notifications.index'))->assertOk()->getContent();
    if ($html === false) {
        throw new RuntimeException('The shell page rendered no body to inspect.');
    }
    $sidebar = sidebarRailMarkup($html);

    // The element that opens over the page, and the listener that keeps a
    // click on its own padding from Flux's click-to-expand.
    expect($sidebar['tag'])->toContain('hris-sidebar')
        ->toContain('x-on:click.self.capture=')
        ->toContain('stopImmediatePropagation');

    // Nothing inside is styled by the bare collapsed state, which the open
    // sidebar still has.
    expect($sidebar['inside'])->not->toContain('in-data-flux-sidebar-collapsed-desktop:');

    // The labels, a tree's pages and the Chat count are the rail's to hide.
    expect(preg_match('/<span class="[^"]*\bsidebar-rail:hidden\b[^"]*">Records Management<\/span>/', $sidebar['inside']))->toBe(1)
        ->and(preg_match('/<div x-show="open" x-cloak class="sidebar-rail:hidden">/', $sidebar['inside']))->toBe(1)
        ->and(preg_match('/wire:name="chat\.unread-badge" class="[^"]*\bsidebar-rail:hidden\b/', $sidebar['inside']))->toBe(1)
        ->and(preg_match('/<span class="[^"]*\bsidebar-rail:hidden\b[^"]*">Sign out<\/span>/', $sidebar['inside']))->toBe(1);
});

it('opens the collapsed sidebar at Flux\'s widths without widening its column', function () {
    $stub = (string) file_get_contents(base_path('vendor/livewire/flux/stubs/resources/views/flux/sidebar/index.blade.php'));
    $open = sidebarRailBlock((string) file_get_contents(resource_path('css/app.css')), '@utility hris-sidebar');

    // Flux's sidebar: its width, its padding, and the rail it collapses to.
    if (preg_match('/\[:where\(&\)\]:w-(\d+) p-(\d+)\b/', $stub, $wide) !== 1
        || preg_match('/data-flux-sidebar-collapsed-desktop:w-(\d+) data-flux-sidebar-collapsed-desktop:px-\d+\b/', $stub, $rail) !== 1) {
        throw new RuntimeException("Flux's sidebar stub no longer states its sizes in the shape this test reads.");
    }

    // The open sidebar is Flux's wide one again, and its margin hands back
    // the difference, so the shell's `min-content` column stays the rail's.
    expect($open)->toContain('&[data-flux-sidebar-collapsed-desktop]:hover {')
        ->toContain("width: calc(var(--spacing) * {$wide[1]});")
        ->toContain("padding-inline: calc(var(--spacing) * {$wide[2]});")
        ->toContain("margin-inline-end: calc(var(--spacing) * ({$rail[1]} - {$wide[1]}));");
});

it('leaves the rail a rail on a screen that cannot hover', function () {
    $css = (string) file_get_contents(resource_path('css/app.css'));

    // The open sidebar exists only where a pointer can leave it again …
    expect(sidebarRailBlock($css, '@utility hris-sidebar'))
        ->toMatch('/^@utility hris-sidebar \{ @media \(hover: hover\) \{ &\[data-flux-sidebar-collapsed-desktop\]:hover \{[^{}]*\} \} \}$/');

    // … and the rail's own styling holds under a pointer only there: without
    // hover it follows the collapsed state alone.
    expect(sidebarRailBlock($css, '@custom-variant sidebar-rail'))
        ->toContain('&:where([data-flux-sidebar-collapsed-desktop]:not(:hover) *) { @slot; }')
        ->toContain('@media (hover: none) { &:where([data-flux-sidebar-collapsed-desktop] *) { @slot; } }');
});
