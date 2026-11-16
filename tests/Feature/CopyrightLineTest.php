<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Support\Access;

/**
 * The copyright line — "© 2026 Designed and Developed By CyberOnions and
 * Partners Corporation", the name linked to cyberonions.io — closes the
 * sign-in pages and every page of the signed-in shell's centred column,
 * drawn once on each from one partial. It
 * is the ONLY place either page names CyberOnions: the installation's brand
 * stays BestWorkflows'. The link leaves the app, so it opens in a new tab without a
 * reference back to this window, and the line never reaches paper.
 */

/**
 * The page's copyright footers, each as its own HTML.
 *
 * @return list<string>
 */
function copyrightFooters(string $html): array
{
    preg_match_all('/<footer[^>]*data-copyright[^>]*>.*?<\/footer>/s', $html, $matches);

    return $matches[0];
}

it('closes the sign-in page with the linked copyright line and names CyberOnions nowhere else', function () {
    $html = (string) $this->get(route('login'))->assertOk()->getContent();
    $footers = copyrightFooters($html);

    expect($footers)->toHaveCount(1)
        ->and(substr_count($html, 'CyberOnions'))->toBe(1);

    $text = (string) preg_replace('/\s+/', ' ', trim(html_entity_decode(strip_tags($footers[0]))));

    expect($text)->toBe('© 2026 Designed and Developed By CyberOnions and Partners Corporation')
        ->and($footers[0])->toContain('href="https://cyberonions.io/"')
        ->toContain('target="_blank"')
        ->toContain('rel="noopener noreferrer"')
        ->toContain('print:hidden');
});

it('closes a signed-in page with the same line, inside the page column and after its content', function () {
    $role = Role::create(['name' => 'Copyright line reader', 'all_data' => false, 'is_system' => false]);
    RoleModuleGrant::create(['role_id' => $role->id, 'module' => 'PORTAL']);
    test()->actingAs(User::factory()->create(['role_id' => $role->id]));
    app()->forgetInstance(Access::class);

    $html = (string) $this->get(route('notifications.index'))->assertOk()->getContent();
    $footers = copyrightFooters($html);
    $main = strpos($html, 'data-flux-main');
    $column = strpos($html, 'max-w-[1400px]');
    $footer = strpos($html, 'data-copyright');
    if ($main === false || $column === false || $footer === false) {
        throw new RuntimeException('The shell page is missing its main area, its page column or its copyright line.');
    }

    expect($footers)->toHaveCount(1)
        ->and($footers[0])->toContain('href="https://cyberonions.io/"')
        ->and($main)->toBeLessThan($footer)
        ->and($column)->toBeLessThan($footer);
});
