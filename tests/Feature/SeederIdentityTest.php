<?php

declare(strict_types=1);

use App\Models\BaseModel;
use App\Models\ChatMessage;
use App\Models\CommunityComment;
use App\Models\CommunityGroup;
use App\Models\CommunityPost;
use App\Models\EmployeeProfile;
use App\Models\PayrollIncentiveType;
use App\Models\Position;
use App\Models\Project;
use App\Models\Shift;
use App\Models\User;
use App\Models\WorkSite;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\EmployeeSeeder;
use Database\Seeders\IncentiveTypeSeeder;
use Database\Seeders\PortSeeder;
use Database\Seeders\ProductionSeeder;
use Database\Seeders\ReferenceListSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\ShiftSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Seed labels identify fixtures, never new primary keys. Generated keys,
 * legacy rows, editable catalog labels and every dependent link must coexist
 * without duplication. Production seeding stays configuration-only.
 */

/** @return array<string, list<string>> */
function seedIdentityRows(): array
{
    $rows = [];
    foreach (config('architecture.models') as $models) {
        foreach ($models as $name) {
            $class = 'App\\Models\\'.$name;
            if (! is_subclass_of($class, BaseModel::class)) {
                continue;
            }
            $model = new $class;
            $ids = [];
            foreach ($class::query()->orderBy('id')->pluck('id') as $id) {
                if (! is_string($id)) {
                    throw new LogicException($model->getTable().' must store string domain keys.');
                }
                $ids[] = $id;
            }
            $rows[$model->getTable()] = $ids;
        }
    }

    return $rows;
}

/** @param array<string, list<string>> $before */
function seedIdentityAssertNewKeys(array $before): void
{
    $created = 0;
    foreach (seedIdentityRows() as $table => $ids) {
        foreach (array_diff($ids, $before[$table] ?? []) as $id) {
            expect(Str::isUlid($id))->toBeTrue($table.' must generate its new primary keys');
            $created++;
        }
    }
    expect($created)->toBeGreaterThan(0);
}

it('mints new production seed rows through BaseModel and never adds demo data', function () {
    $before = seedIdentityRows();
    $this->seed(ProductionSeeder::class);
    seedIdentityAssertNewKeys($before);

    expect(EmployeeProfile::query()->count())->toBe(0)
        ->and(WorkSite::query()->count())->toBe(0)
        ->and(User::query()->count())->toBe(0);

    $once = seedIdentityRows();
    $this->seed(ProductionSeeder::class);
    expect(seedIdentityRows())->toBe($once);
});

it('keeps demo links and identities stable while users retain bigint keys', function () {
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-10-12 06:00:00', 'UTC'));
    $before = seedIdentityRows();
    $this->seed(DatabaseSeeder::class);
    seedIdentityAssertNewKeys($before);

    expect(EmployeeProfile::query()->findOrFail(seededId(EmployeeProfile::class, 'emp-6'))->work_site_id)
        ->toBe(seededId(WorkSite::class, 'site-3'))
        ->and(Project::query()->findOrFail(seededId(Project::class, 'prj-1'))->site_id)
        ->toBe(seededId(WorkSite::class, 'site-1'));

    $reply = CommunityComment::query()->findOrFail(seededId(CommunityComment::class, 'ccmt-20'));
    expect($reply->parent_id)->toBe(seededId(CommunityComment::class, 'ccmt-2'))
        ->and($reply->post_id)->toBe(seededId(CommunityPost::class, 'cpost-1'))
        ->and(ChatMessage::query()->findOrFail(seededId(ChatMessage::class, 'cmsg-3-5'))->reply_to_id)
        ->toBe(seededId(ChatMessage::class, 'cmsg-3-4'))
        ->and(CommunityComment::query()->findOrFail(seededId(CommunityComment::class, 'ccmt-19'))->body)
        ->toContain('@[g:'.seededId(CommunityGroup::class, 'cgrp-5').']');

    $users = User::query()->orderBy('id')->pluck('id')->all();
    expect($users)->toHaveCount(8);
    foreach ($users as $id) {
        expect($id)->toBeInt()->toBeGreaterThan(0);
    }

    $once = seedIdentityRows();
    $identities = DB::table('seed_identities')->orderBy('table_name')->orderBy('seed_key')->get()->toArray();
    $this->seed(DatabaseSeeder::class);
    expect(seedIdentityRows())->toBe($once)
        ->and(User::query()->orderBy('id')->pluck('id')->all())->toBe($users)
        ->and(DB::table('seed_identities')->orderBy('table_name')->orderBy('seed_key')->get()->toArray())->toEqual($identities);
});

it('adopts legacy catalog keys without rewriting their renamed adds-only rows', function () {
    $type = new PayrollIncentiveType;
    $type->forceFill(['id' => 'ity-1', 'code' => '41', 'name' => 'HR renamed benefit', 'is_taxable' => true])->save();
    $position = new Position;
    $position->forceFill(['id' => 'pos-1', 'code' => '42', 'name' => 'HR renamed position', 'sort_order' => 19])->save();

    $this->seed([IncentiveTypeSeeder::class, ReferenceListSeeder::class]);
    expect(seededId(PayrollIncentiveType::class, 'ity-1'))->toBe('ity-1')
        ->and(seededId(Position::class, 'pos-1'))->toBe('pos-1')
        ->and($type->fresh()->only(['code', 'name', 'is_taxable']))
        ->toBe(['code' => '41', 'name' => 'HR renamed benefit', 'is_taxable' => true])
        ->and($position->fresh()->only(['code', 'name', 'sort_order']))
        ->toBe(['code' => '42', 'name' => 'HR renamed position', 'sort_order' => 19])
        ->and(PayrollIncentiveType::query()->where('name', 'Performance Bonus')->exists())->toBeFalse();
});

it('resolves imported BestWorkflows employees and preserves their codes when reseeded', function (bool $legacyKeys) {
    $employees = [];
    foreach (range(1, 8) as $number) {
        $employee = new EmployeeProfile;
        if ($legacyKeys) {
            $employee->forceFill(['id' => 'emp-'.$number]);
        }
        $employee->forceFill([
            'employee_code' => sprintf('BWF-%05d', $number),
            'first_name' => 'Imported',
            'last_name' => 'Employee '.$number,
            'employment_status' => 'Regular',
            'date_hired' => '2025-01-01',
            'is_active' => true,
        ])->save();
        $employees[$number] = (string) $employee->getKey();
    }

    // Individual seeders must resolve imported codes before EmployeeSeeder
    // has registered their generated or legacy primary keys.
    $this->seed(ShiftSeeder::class);
    expect(Shift::query()->count())->toBe(8);
    $shifts = [];
    foreach ($employees as $number => $employeeId) {
        $shifts[$number] = seededId(Shift::class, 'shift-'.$number);
        expect(Shift::query()->findOrFail($shifts[$number])->employee_id)->toBe($employeeId);
    }

    $this->seed([RoleSeeder::class, ReferenceListSeeder::class, EmployeeSeeder::class]);
    $this->seed([EmployeeSeeder::class, ShiftSeeder::class]);

    expect(EmployeeProfile::query()->count())->toBe(8)
        ->and(Shift::query()->count())->toBe(8)
        ->and(User::query()->where('email', 'admin@bestworkflows.local')->value('employee_id'))->toBe($employees[1]);
    foreach ($employees as $number => $employeeId) {
        expect(seededId(EmployeeProfile::class, 'emp-'.$number))->toBe($employeeId)
            ->and(EmployeeProfile::query()->findOrFail($employeeId)->employee_code)->toBe(sprintf('BWF-%05d', $number))
            ->and(seededId(Shift::class, 'shift-'.$number))->toBe($shifts[$number])
            ->and(Shift::query()->findOrFail($shifts[$number])->employee_id)->toBe($employeeId);
    }
})->with(['generated primary keys' => [false], 'legacy primary keys' => [true]]);

it('adopts existing natural-key rows and remembers them after a rename', function () {
    $type = new PayrollIncentiveType;
    $type->forceFill(['code' => '88', 'name' => 'Performance Bonus', 'is_taxable' => true])->save();
    $originalId = (string) $type->getKey();

    $this->seed(IncentiveTypeSeeder::class);
    expect(seededId(PayrollIncentiveType::class, 'ity-1'))->toBe($originalId);
    // Registry recovery can adopt the existing natural key without minting
    // another business row. Once renamed, the registry is authoritative.
    DB::table('seed_identities')->where('table_name', 'payroll_incentive_types')->where('seed_key', 'ity-1')->delete();
    $this->seed(IncentiveTypeSeeder::class);
    expect(seededId(PayrollIncentiveType::class, 'ity-1'))->toBe($originalId)
        ->and(PayrollIncentiveType::query()->count())->toBe(6);
    $type->forceFill(['name' => 'Company performance reward', 'description' => 'Keep this policy'])->save();

    $this->seed(IncentiveTypeSeeder::class);
    expect(PayrollIncentiveType::query()->count())->toBe(6)
        ->and(seededId(PayrollIncentiveType::class, 'ity-1'))->toBe($originalId)
        ->and($type->fresh()->only(['code', 'name', 'description', 'is_taxable']))
        ->toBe(['code' => '88', 'name' => 'Company performance reward', 'description' => 'Keep this policy', 'is_taxable' => true]);

    // Deleted seed data may be restored, but its old key must never come back.
    $type->delete();
    $this->seed(IncentiveTypeSeeder::class);
    $replacement = seededId(PayrollIncentiveType::class, 'ity-1');
    expect(Str::isUlid($replacement))->toBeTrue()
        ->and($replacement)->not->toBe($originalId)
        ->and(PayrollIncentiveType::query()->count())->toBe(6);
});

it('rolls the seed row back if its identity cannot be recorded', function () {
    $seeder = new class extends PortSeeder
    {
        public function insertFixture(): Model
        {
            return $this->upsert(WorkSite::class, ['id' => 'site-atomic'], [
                'name' => 'Atomic seed site', 'latitude' => 0, 'longitude' => 0,
                'radius_meters' => 0, 'geofence_exempt' => true,
            ]);
        }
    };
    $connection = DB::connection();
    $property = new ReflectionProperty($connection, 'beforeExecutingCallbacks');
    $previousCallbacks = $property->getValue($connection);
    $connection->beforeExecuting(static function (string $query): void {
        if (preg_match('/^insert into ["`]?seed_identities["`]?/i', $query) === 1) {
            throw new RuntimeException('Seed registry unavailable');
        }
    });

    try {
        expect(fn () => $seeder->insertFixture())->toThrow(RuntimeException::class, 'Seed registry unavailable');
        expect(WorkSite::query()->where('name', 'Atomic seed site')->exists())->toBeFalse();
    } finally {
        // The connection belongs to this test application; clear the callback
        // before RefreshDatabase rolls its transaction back.
        $property->setValue($connection, $previousCallbacks);
    }
});
