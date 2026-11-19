<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

it('selects the application public directory before considering the cPanel sibling fallback', function (bool $ownIndex, bool $siblingIndex, string $expected) {
    $root = dirname(__DIR__, 2);
    $temporary = (realpath(sys_get_temp_dir()) ?: sys_get_temp_dir()).'/bestworkflows-public-path-'.bin2hex(random_bytes(8));
    $files = new Filesystem;
    $files->makeDirectory($temporary.'/bestworkflows/bootstrap', 0755, true);
    $files->copy($root.'/bootstrap/app.php', $temporary.'/bestworkflows/bootstrap/app.php');

    try {
        foreach (['bestworkflows/public' => $ownIndex, 'public_html' => $siblingIndex] as $path => $exists) {
            if ($exists) {
                $files->makeDirectory($temporary.'/'.$path, 0755, true);
                $files->put($temporary.'/'.$path.'/index.php', '<?php');
            }
        }

        // Configure an isolated application without booting a kernel or
        // loading an environment/database. The subprocess also isolates
        // Laravel's global container from the rest of the unit suite.
        $process = new Process([
            PHP_BINARY, '-r', 'require $argv[1]; $app = require $argv[2]; echo $app->publicPath();',
            $root.'/vendor/autoload.php', $temporary.'/bestworkflows/bootstrap/app.php',
        ]);
        $process->mustRun();
        // Which folder was chosen is the rule; Windows writes the same path
        // with backslashes, so both sides are compared with one separator.
        $chosen = str_replace('\\', '/', $process->getOutput());
        expect($chosen)->toBe(str_replace('\\', '/', $temporary.'/'.$expected));
    } finally {
        $files->deleteDirectory($temporary);
    }
})->with([
    'subdomain beside an existing primary site' => [true, true, 'bestworkflows/public'],
    'standalone standard layout' => [true, false, 'bestworkflows/public'],
    'split cPanel primary-domain layout' => [false, true, 'public_html'],
    'default before public files are installed' => [false, false, 'bestworkflows/public'],
]);
