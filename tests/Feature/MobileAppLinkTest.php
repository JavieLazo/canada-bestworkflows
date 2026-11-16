<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Support\Access;

/**
 * The header's App link downloads the eDTR mobile app from the address the
 * installation names in `copems.mobile_app_url`. The app file is uploaded by
 * the operator and never ships in a release, so AN INSTALLATION THAT NAMES NO
 * ADDRESS DRAWS NO LINK — the shell never points at a file that is not there,
 * and never at another installation's host.
 */

/** A signed-in shell page as HTML, for an account that holds one module. */
function mobileAppLinkPage(): string
{
    $role = Role::create(['name' => 'Mobile app link reader', 'all_data' => false, 'is_system' => false]);
    RoleModuleGrant::create(['role_id' => $role->id, 'module' => 'PORTAL']);
    test()->actingAs(User::factory()->create(['role_id' => $role->id]));
    app()->forgetInstance(Access::class);

    return (string) test()->get(route('notifications.index'))->assertOk()->getContent();
}

/**
 * The page's App links, each as its own HTML.
 *
 * @return list<string>
 */
function mobileAppLinks(string $html): array
{
    preg_match_all('/<a\b[^>]*data-mobile-app-link[^>]*>.*?<\/a>/s', $html, $matches);

    return $matches[0];
}

it('draws no App link while the installation names no app address', function () {
    config(['copems.mobile_app_url' => null]);

    expect(mobileAppLinkPage())->not->toContain('data-mobile-app-link');
});

it('draws no App link for an empty app address', function () {
    config(['copems.mobile_app_url' => '']);

    expect(mobileAppLinkPage())->not->toContain('data-mobile-app-link');
});

it('links the header to the app address the installation names', function () {
    config(['copems.mobile_app_url' => 'https://apps.example.test/edtr.apk']);

    $html = mobileAppLinkPage();
    $links = mobileAppLinks($html);
    $link = strpos($html, 'data-mobile-app-link');
    $main = strpos($html, 'data-flux-main');
    if ($link === false || $main === false) {
        throw new RuntimeException('The shell page is missing its App link or its main area.');
    }

    expect($links)->toHaveCount(1)
        ->and($links[0])->toContain('href="https://apps.example.test/edtr.apk"')
        ->toContain('aria-label="Download the eDTR mobile app"')
        ->and(trim(strip_tags($links[0])))->toBe('App')
        ->and($link)->toBeLessThan($main);
});

it('ships with no app address of its own', function () {
    expect(file_get_contents(base_path('config/copems.php')))
        ->toContain("'mobile_app_url' => env('BESTWORKFLOWS_MOBILE_APP_URL', env('COPEMS_MOBILE_APP_URL')),");
});
