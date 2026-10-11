<?php

declare(strict_types=1);

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Livewire\Kit\CsvImport;
use App\Models\AuditLog;
use App\Models\LeaveType;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Platform\Transfer\ImportLayout;
use App\Platform\Transfer\ImportOutcome;
use App\Platform\Transfer\ImportRow;
use App\Platform\Transfer\ImportRunner;
use App\Platform\Transfer\TableImport;
use App\Platform\Transfer\TableTransfers;
use App\Support\Access;
use App\Support\Csv;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The shared CSV transfer, driven through the Leave Settings list: an export
 * is the table's own scoped, searched and sorted rows; the template is its
 * header; an import previews through the owner's real command and rolls it
 * back, then saves every row in one transaction — or, if any row fails,
 * nothing at all. A refused file's upload is deleted at once. Only the
 * table's writers are offered the import, and every action — down to one
 * row() — re-checks.
 */

/** @param list<string> $modules */
function xferActor(array $modules, bool $allData): User
{
    $role = new Role;
    $role->forceFill(['name' => 'Transfer '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();
    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }
    $user = User::factory()->create(['role_id' => $role->getKey()]);
    test()->actingAs($user);

    return $user;
}

function xferLeaveType(string $code, string $name): LeaveType
{
    $type = new LeaveType;
    $type->forceFill(['code' => $code, 'name' => $name])->save();

    return $type;
}

/**
 * @param  list<list<string>>  $rows
 * @param  list<string>  $headers
 */
function xferFile(array $rows, array $headers = ['ID', 'Leave']): UploadedFile
{
    return UploadedFile::fake()->createWithContent('leave-types.csv', Csv::build($headers, $rows));
}

/** @return Testable<CsvImport> */
function xferDialog(): Testable
{
    return Livewire::test(CsvImport::class, ['table' => 'records.leave-types', 'template' => '/records/leave-types/export?template=1']);
}

/** @return list<string> Livewire's temporary uploads still on disk */
function xferUploads(): array
{
    return array_values(FileUploadConfiguration::storage()->allFiles(FileUploadConfiguration::path()));
}

beforeEach(function () {
    Storage::fake('local');
    xferLeaveType('1', 'Paternity Leave');
    xferLeaveType('2', 'Sick Leave');
    xferLeaveType('3', 'Emergency Leave');
});

it('exports the rows the list shows, searched and sorted as on screen, never cached', function () {
    xferActor([ModuleCode::RECORDS], allData: false);

    $response = $this->get('/records/leave-types/export?q=er&sort=name&dir=desc')->assertOk();

    expect($response->streamedContent())->toBe(Csv::BOM.'"ID","Leave"'."\r\n".'"1","Paternity Leave"'."\r\n".'"3","Emergency Leave"')
        ->and($response->headers->get('Content-Disposition'))->toStartWith('attachment; filename="leave-types-')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

it('serves the header alone as the template, and ignores a table key in the query string', function () {
    xferActor([ModuleCode::RECORDS], allData: false);

    $response = $this->get('/records/leave-types/export?template=1&table=records.sites')->assertOk();

    expect($response->streamedContent())->toBe(Csv::BOM.'"ID","Leave"')
        ->and($response->headers->get('Content-Disposition'))->toBe('attachment; filename="leave-types-template.csv"');
});

it('turns the export away from an actor without the page grant, as the page itself', function () {
    xferActor([ModuleCode::EMPLOYEES], allData: true);

    $response = $this->get('/records/leave-types/export')->assertRedirect();

    expect($response->headers->get('Content-Disposition'))->toBeNull();
});

it('previews an import through the real command and keeps none of it', function () {
    xferActor([ModuleCode::RECORDS], allData: true);

    xferDialog()
        ->call('openDialog')
        ->set('file', xferFile([['', 'Study Leave'], ['2', 'Sick Leave With Pay']]))
        ->assertSet('report.created', 1)
        ->assertSet('report.updated', 1)
        ->assertSet('report.errors', [])
        ->assertSee('Ready to import');

    expect(LeaveType::query()->count())->toBe(3)
        ->and(LeaveType::query()->where('code', '2')->value('name'))->toBe('Sick Leave')
        ->and(AuditLog::query()->count())->toBe(0);
});

it('saves every row and audits each change once confirmed, then refreshes the page', function () {
    xferActor([ModuleCode::RECORDS], allData: true);
    $uploads = xferUploads();

    xferDialog()
        ->call('openDialog')
        ->set('file', xferFile([['', 'Study Leave'], ['2', 'Sick Leave With Pay']]))
        ->call('import')
        ->assertSet('open', false)
        ->assertSet('report', null)
        ->assertSet('file', null)
        ->assertDispatched('toast-show', fn (string $name, array $params): bool => ($params['slots']['text'] ?? null) === 'Imported: 1 added, 1 updated.');

    expect(xferUploads())->toBe($uploads)
        ->and(LeaveType::query()->where('name', 'Study Leave')->value('code'))->toBe('4')
        ->and(LeaveType::query()->where('code', '2')->value('name'))->toBe('Sick Leave With Pay')
        ->and(AuditLog::query()->where('action', AuditEvent::REFERENCE_CREATED->value)->count())->toBe(1)
        ->and(AuditLog::query()->where('action', AuditEvent::REFERENCE_UPDATED->value)->count())->toBe(1);
});

it('saves nothing when any row fails, names every failing row, and deletes the refused upload', function () {
    xferActor([ModuleCode::RECORDS], allData: true);
    $uploads = xferUploads();

    xferDialog()
        ->call('openDialog')
        ->set('file', xferFile([['', 'Bereavement Leave'], ['1', ''], ['99', 'Study Leave'], ['', 'Emergency Leave']]))
        ->assertSet('report.failed', 3)
        ->assertSet('report.errors', [
            ['row' => 3, 'message' => 'Name is required.'],
            ['row' => 4, 'message' => 'ID 99 is not on the list. Leave ID blank to add a new leave type.'],
            ['row' => 5, 'message' => '"Emergency Leave" is already on the list.'],
        ])
        ->assertSet('file', null)
        ->assertSet('pickedName', 'leave-types.csv')
        ->assertSee('leave-types.csv')
        ->call('import')
        ->assertDispatched('toast-show', fn (string $name, array $params): bool => ($params['slots']['text'] ?? null) === 'Choose a CSV file first.');

    expect(xferUploads())->toBe($uploads)
        ->and(LeaveType::query()->count())->toBe(3)
        ->and(LeaveType::query()->where('name', 'Bereavement Leave')->exists())->toBeFalse()
        ->and(AuditLog::query()->count())->toBe(0);
});

it('counts an untouched export as unchanged and writes no audit row', function () {
    xferActor([ModuleCode::RECORDS], allData: true);
    $export = $this->get('/records/leave-types/export')->streamedContent();

    xferDialog()
        ->call('openDialog')
        ->set('file', UploadedFile::fake()->createWithContent('leave-types.csv', $export))
        ->assertSet('report.unchanged', 3)
        ->call('import');

    expect(AuditLog::query()->count())->toBe(0);
});

it('deletes the last upload when another file is picked, and the rest on Cancel', function () {
    xferActor([ModuleCode::RECORDS], allData: true);
    $uploads = xferUploads();

    $dialog = xferDialog()
        ->call('openDialog')
        ->set('file', xferFile([['', 'Bereavement Leave']]))
        ->set('file', xferFile([['', 'Study Leave']]))
        ->assertSet('report.created', 1);
    // The second pick's file and Livewire's note beside it, nothing of the first.
    expect(array_diff(xferUploads(), $uploads))->toHaveCount(2);

    $dialog->set('open', false)->assertSet('file', null)->assertSet('pickedName', '');
    expect(xferUploads())->toBe($uploads)
        ->and(LeaveType::query()->count())->toBe(3);
});

it('keeps nothing when a row stops passing between the preview and the import', function () {
    xferActor([ModuleCode::RECORDS], allData: true);
    $uploads = xferUploads();
    $dialog = xferDialog()
        ->call('openDialog')
        ->set('file', xferFile([['', 'Bereavement Leave'], ['2', 'Sick Leave With Pay']]))
        ->assertSet('report.errors', []);

    xferLeaveType('9', 'Bereavement Leave'); // someone else adds it meanwhile

    $dialog->call('import')
        ->assertSet('open', true)
        ->assertSet('report.errors', [['row' => 2, 'message' => '"Bereavement Leave" is already on the list.']])
        ->assertSet('file', null)
        ->assertSet('pickedName', 'leave-types.csv')
        ->assertDispatched('toast-show', fn (string $name, array $params): bool => ($params['slots']['text'] ?? null) === 'Nothing was imported. Fix the rows listed, then choose the file again.');

    expect(xferUploads())->toBe($uploads)
        ->and(LeaveType::query()->where('code', '2')->value('name'))->toBe('Sick Leave')
        ->and(AuditLog::query()->count())->toBe(0);
});

it('logs a failed row by class, code and line, never the SQL or the values it carried', function () {
    xferActor([ModuleCode::RECORDS], allData: true);
    $import = new class implements TableImport
    {
        public function layout(Access $actor, string $context): ImportLayout
        {
            return new ImportLayout('things', 'ID', ['ID', 'Name']);
        }

        public function allows(Access $actor, string $context): bool
        {
            return true;
        }

        public function row(Access $actor, string $context, ImportRow $row): ImportOutcome
        {
            throw new QueryException('sqlite', 'update employee_profiles set bank_account_no = ? where id = ?', [$row->text('Name'), 'x'], new PDOException('SQLSTATE[HY000]: General error'));
        }
    };
    $log = Log::spy();

    $report = app(ImportRunner::class)->preview(app(Access::class), $import, '', Csv::build(['ID', 'Name'], [['', '0042-7788-9900']]));

    expect($report->errors)->toBe([['row' => 2, 'message' => 'The database refused this row.']]);
    $log->shouldHaveReceived('error')
        ->withArgs(function (string $message, array $context): bool {
            $logged = (string) json_encode($context);

            return $message === '[import] row failed' && $context['error'] === QueryException::class && $context['row'] === 2
                && ! str_contains($logged, '0042-7788-9900') && ! str_contains($logged, 'bank_account_no');
        })
        ->once();
});

it('refuses a direct row() call from an actor no import allows, on every importable table', function () {
    xferActor([ModuleCode::RECORDS, ModuleCode::EMPLOYEES, ModuleCode::LEAVES, ModuleCode::ROSTER, ModuleCode::PROJECTS, ModuleCode::PAYROLL], allData: false);
    $transfers = app(TableTransfers::class);

    $refused = [];
    foreach ($transfers->importKeys() as $key) {
        try {
            // 'Leave' is the fixture list's own name column: a row that got past the gate would add a leave type.
            $transfers->import($key)->row(app(Access::class), '', new ImportRow(2, ['ID' => '', 'Name' => 'Guard', 'Leave' => 'Study Leave']));
            $refused[$key] = null;
        } catch (HttpException $e) {
            $refused[$key] = $e->getStatusCode();
        }
    }

    expect($refused)->toHaveCount(count($transfers->importKeys()))
        ->and(array_unique(array_values($refused)))->toBe([403])
        ->and(LeaveType::query()->count())->toBe(3);
});

it('refuses a file whose header is missing a column', function () {
    xferActor([ModuleCode::RECORDS], allData: true);

    xferDialog()
        ->set('file', xferFile([['', 'Study Leave']], ['Code', 'Title']))
        ->assertSet('report.errors', [['row' => 1, 'message' => 'Missing columns: ID, Leave. Start from the template or an export of this table.']]);
});

it('offers no import to a reader without all_data, and refuses its actions', function () {
    xferActor([ModuleCode::RECORDS], allData: false);

    xferDialog()->assertDontSee('Import CSV')
        ->set('file', xferFile([['', 'Study Leave']]))
        ->assertForbidden();

    expect(LeaveType::query()->count())->toBe(3);
});

it('refuses a table key nobody registered', function () {
    xferActor([ModuleCode::RECORDS], allData: true);

    Livewire::test(CsvImport::class, ['table' => 'records.nope'])->assertNotFound();
});
