<?php

declare(strict_types=1);

use App\Livewire\Kit\CsvImport;
use App\Livewire\Projects\Index as ProjectsIndex;
use App\Livewire\Projects\Show;
use App\Modules\WorkManagement\Application\Commands\CreateProject;
use App\Modules\WorkManagement\Application\ProjectDraft;
use App\Support\Access;
use Livewire\Livewire;

require_once __DIR__.'/../Support/ClientProjectFixtures.php';

it('keeps client or internal context visible in the library and project details', function () {
    $owner = cpaUser(allData: true);
    $client = cpaClient($owner);
    $project = cpaProject($owner, $client);
    $internal = app(CreateProject::class)->handle(Access::forUser($owner), new ProjectDraft('Internal planning', '', 'NONE', 'ACTIVE', '2026-01-01', '', (string) $owner->id));
    cpaLogin($owner);
    $page = Livewire::test(ProjectsIndex::class)->set('section', 'projects')->assertSeeHtml('title="'.$client->name.'"')->assertSeeHtml('title="Internal"');
    $page->set('client', $client->id)->assertSeeHtml('title="'.$client->name.'"');
    $page->set('projectView', 'list')->assertSeeHtml('title="'.$client->name.'"')->assertSeeHtml('<th>Client</th>');
    $page->set('client', 'ALL')->assertSeeHtml('title="Internal"');
    Livewire::test(Show::class, ['project' => $project->id])->assertSeeHtml('title="'.$client->name.'"')->set('taskView', 'details')->assertSee('Project lead');
    Livewire::test(Show::class, ['project' => $internal->id])->assertSeeHtml('title="Internal"');
});

it('opens the existing importer from a matching menu and still enforces its authority', function () {
    $owner = cpaUser(allData: true);
    cpaLogin($owner);
    $page = Livewire::test(CsvImport::class, ['table' => 'projects', 'showTrigger' => false]);
    $page->dispatch('open-csv-import', table: 'projects.tasks')->assertSet('open', false);
    $page->dispatch('open-csv-import', table: 'projects', context: 'another-project')->assertSet('open', false);
    $page->dispatch('open-csv-import', table: 'projects')->assertSet('open', true);
    cpaLogin(cpaUser());
    Livewire::test(CsvImport::class, ['table' => 'projects', 'showTrigger' => false])->dispatch('open-csv-import', table: 'projects')->assertForbidden();
});
