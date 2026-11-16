<?php

declare(strict_types=1);

use App\Models\ProjectClient;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/../Support/ClientProjectFixtures.php';

it('reruns the client metadata migration and refuses to lose a retained brief', function () {
    $client = cpaClient(cpaUser());
    $migration = require database_path('migrations/2026_11_06_0001_add_client_description.php');
    $migration->up();
    $migration->up();
    ProjectClient::query()->whereKey($client->id)->update(['description' => 'Keep this brief']);
    expect(fn () => $migration->down())->toThrow(RuntimeException::class);
    expect(ProjectClient::query()->findOrFail($client->id)->description)->toBe('Keep this brief');
    ProjectClient::query()->whereKey($client->id)->update(['description' => null]);
    $migration->down();
    $migration->down();
    expect(Schema::hasColumn('project_clients', 'description'))->toBeFalse();
    $migration->up();
    expect(ProjectClient::query()->findOrFail($client->id)->name)->toBe($client->name);
});
