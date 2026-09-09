<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

/*
 * Where the application root is, relative to this file.
 *
 * Normally it is the parent of public/. But on a cPanel primary domain the
 * document root is fixed at public_html, which is a SIBLING of the app rather
 * than its parent — the app is deployed alongside it in `bestworkflows/`, and
 * `../vendor` would resolve to the account home, one level too high. Pick
 * whichever layout is actually on disk, so the same file boots under
 * `php artisan serve`, on a host whose docroot can be pointed at public/, and
 * on one locked to public_html alike. bootstrap/app.php makes the matching
 * adjustment for public_path() once the app is up.
 */
$appRoot = is_file(__DIR__.'/../vendor/autoload.php')
    ? __DIR__.'/..'
    : __DIR__.'/../bestworkflows';

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = $appRoot.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require $appRoot.'/vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once $appRoot.'/bootstrap/app.php';

$app->handleRequest(Request::capture());
