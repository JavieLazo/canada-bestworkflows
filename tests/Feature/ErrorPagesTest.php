<?php

declare(strict_types=1);

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Every error a browser can be handed is the app's own page: the company mark,
 * what happened in plain words, and a link only where there is somewhere
 * useful to go.
 *
 * AN ERROR PAGE STANDS ON ITS OWN. It is shown when something else has failed,
 * so it reads no database, needs no Vite build and shows nothing of the failure
 * itself — and the 503 page is drawn from the command line by `artisan down`,
 * with no request and no exception to lean on.
 */
function errorPageHtml(Throwable $e, string $path = '/somewhere'): string
{
    return (string) app(ExceptionHandler::class)
        ->render(Request::create($path), $e)
        ->getContent();
}

it('draws its own page for each status a browser can be handed', function (int $status, string $heading) {
    $response = app(ExceptionHandler::class)->render(Request::create('/somewhere'), new HttpException($status));
    $html = (string) $response->getContent();

    expect($response->getStatusCode())->toBe($status)
        ->and($html)->toContain('<h1>'.$heading.'</h1>')
        ->toContain('Error '.$status)
        ->toContain('bestworkflows-horizontal-view.png')
        ->toContain('data-copyright');
})->with([
    'not signed in' => [401, 'Sign In Required'],
    'payment required' => [402, 'This Request Could Not Be Completed'],
    'not allowed' => [403, 'No Access to This Page'],
    'not found' => [404, 'Page Not Found'],
    'wrong method' => [405, 'This Request Could Not Be Completed'],
    'expired' => [419, 'Page Expired'],
    'refused input' => [422, 'This Request Could Not Be Completed'],
    'rate limited' => [429, 'Too Many Attempts'],
    'server error' => [500, 'Something Went Wrong'],
    'another server error' => [502, 'Something Went Wrong'],
]);

it('answers an address that does not exist with the Page Not Found page', function () {
    $this->get('/no-such-page-anywhere')
        ->assertNotFound()
        ->assertSee('Page Not Found')
        ->assertSee('Go to Home');
});

it('still answers an API caller with JSON, not a page', function () {
    $this->getJson('/no-such-page-anywhere')
        ->assertNotFound()
        ->assertJsonStructure(['message'])
        ->assertDontSee('<html', false);
});

it('needs no build, no script bundle and no database to draw a page', function () {
    DB::enableQueryLog();

    $html = errorPageHtml(new HttpException(404)).errorPageHtml(new HttpException(403));

    expect(DB::getQueryLog())->toBe([])
        ->and($html)->toContain('<style>')
        ->not->toContain('/build/')
        ->not->toContain('<script src');
});

it('shows the reason a request was refused, but never the framework\'s stock phrase', function () {
    expect(errorPageHtml(new HttpException(403, 'Account disabled — contact HR.')))
        ->toContain('Account disabled — contact HR.')
        ->not->toContain('ask your administrator');

    expect(errorPageHtml(new HttpException(403, 'This action is unauthorized.')))
        ->not->toContain('This action is unauthorized.')
        ->toContain('Your account is not allowed to open this page or do this action.');
});

it('escapes a refusal reason instead of drawing it as markup', function () {
    expect(errorPageHtml(new HttpException(403, '<b>bold</b>')))
        ->toContain('&lt;b&gt;bold&lt;/b&gt;')
        ->not->toContain('<b>bold</b>');
});

it('never shows what a missing record or a failure said about itself', function () {
    config(['app.debug' => false]);

    expect(errorPageHtml(new HttpException(404, 'No query results for model [App\\Models\\Employee].')))
        ->toContain('Page Not Found')
        ->not->toContain('App\\Models');

    expect(errorPageHtml(new RuntimeException('SQLSTATE[HY000] secret detail')))
        ->toContain('Something Went Wrong')
        ->toContain('Error 500')
        ->not->toContain('SQLSTATE')
        ->not->toContain('secret detail');
});

it('draws the maintenance page the way artisan down does, with no request or exception', function () {
    // The command hands the page one value and nothing else.
    $html = view('errors.503', ['retryAfter' => null])->render();

    expect($html)->toContain('<h1>We Are Updating the System</h1>')
        ->toContain('Your saved records are safe.')
        ->toContain('Error 503')
        ->not->toContain('http://localhost');
});

it('offers a link only where it leads somewhere useful', function (int $status, ?string $link) {
    $html = errorPageHtml(new HttpException($status));

    if ($link === null) {
        // Every address answers 503 while the site is closed, and a failed
        // or refused request has nowhere better to send the reader.
        expect($html)->not->toContain('<a class="error-link"');

        return;
    }

    expect(substr_count($html, '<a class="error-link"'))->toBe(1)
        ->and($html)->toContain('>'.$link.'</a>');
})->with([
    'not signed in' => [401, 'Sign In'],
    'not allowed' => [403, 'Go to Home'],
    'not found' => [404, 'Go to Home'],
    'expired' => [419, null],
    'refused input' => [422, null],
    'rate limited' => [429, null],
    'server error' => [500, null],
    'site closed' => [503, null],
]);
