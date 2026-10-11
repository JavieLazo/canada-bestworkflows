<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\EmployeeProfile;
use App\Models\Position;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkSite;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use LogicException;

/**
 * Work sites, the eight employees, and the four demo accounts — the port of
 * seed.ts SITES (622-647), EMPLOYEES (608-620), employee_profiles
 * (1341-1405) and user_accounts (1411-1452).
 *
 * hourly_rate stays 0 so the rate is DERIVED: the monthly rate over the days
 * that month is scheduled, then over the hours in the assigned shift. Seeding
 * a figure here made an override of something nobody chose.
 *
 * The reference's acct-admin becomes the app's existing admin@bestworkflows.local: the
 * account is attached to the System Admin role and linked to Maria Santos
 * (emp-1) so scoped screens show data. An existing account keeps its
 * password; only role, employee link and active flag are asserted.
 */
final class EmployeeSeeder extends PortSeeder
{
    private const SITES = [
        ['site-1', 'Makati Head Office', 14.5547, 121.0244, 120, false],
        ['site-2', 'Quezon City Depot', 14.676, 121.0437, 200, false],
        ['site-3', 'Remote / Field Work', 0.0, 0.0, 0, true],
    ];

    /**
     * The company's own employee numbers, typed by HR on the 201 page and kept
     * out of the EMPLOYEES tuples below because they are identity rather than
     * anything a hire's pay or shift depends on.
     *
     * The two newest hires have none ON PURPOSE. That is how every record
     * starts, it is the state the screens must survive — the identifier beside
     * a name simply disappears — and a demo where everybody has a number would
     * never show it.
     */
    private const ID_NUMBERS = [
        'emp-1' => '2019-0007',
        'emp-2' => '2020-0114',
        'emp-3' => '2021-0286',
        'emp-4' => '2023-0431',
        'emp-6' => '2024-0522',
        'emp-8' => '2024-0638',
    ];

    /** id, code, first, last, position, monthly rate, allowance units, status, days employed */
    private const EMPLOYEES = [
        ['emp-1', '1', 'Maria', 'Santos', 'HR Manager', 62000.0, 5, 'Regular', 1400],
        ['emp-2', '2', 'Jose', 'Rivera', 'Payroll Specialist', 48000.0, 4, 'Regular', 1100],
        ['emp-3', '3', 'Andrea', 'Lim', 'Site Supervisor', 42000.0, 3, 'Regular', 900],
        ['emp-4', '4', 'Paolo', 'Bautista', 'Security Officer', 24000.0, 2, 'Regular', 640],
        ['emp-5', '5', 'Grace', 'Mendoza', 'Security Officer', 24000.0, 2, 'Probationary', 120],
        ['emp-6', '6', 'Nathaniel', 'Cruz', 'Field Technician', 28000.0, 2, 'Contractual', 300],
        ['emp-7', '7', 'Liza', 'Domingo', 'Admin Assistant', 22000.0, 1, 'Probationary', 95],
        ['emp-8', '8', 'Ramon', 'Aquino', 'Driver', 20000.0, 1, 'Job Order', 210],
    ];

    public function run(): void
    {
        $sites = [];
        foreach (self::SITES as [$id, $name, $lat, $lng, $radius, $exempt]) {
            $site = $this->upsert(WorkSite::class, ['id' => $id], [
                'name' => $name,
                'latitude' => $lat,
                'longitude' => $lng,
                'radius_meters' => $radius,
                'geofence_exempt' => $exempt,
                'created_at' => $this->at(300, 9, 0),
            ]);
            $sites[$id] = (string) $site->getKey();
        }

        if (! isset($sites['site-1'], $sites['site-2'], $sites['site-3'])) {
            throw new LogicException('Every demo work site must be saved before its employees.');
        }

        foreach (self::EMPLOYEES as $i => [$id, $code, $first, $last, $position, $monthly, $allowanceUnits, $status, $daysEmployed]) {
            $daysEmployed = (int) $daysEmployed;
            $daysSinceRegular = $daysEmployed > 181 ? $daysEmployed - 180 : 1;
            $match = $this->codeMatch(EmployeeProfile::class, 'employee_code', (int) $code, 'COP', 5);
            $this->upsert(EmployeeProfile::class, $match, $this->positionLink($match, (string) $position) + [
                'id' => $id,
                'employee_id_no' => self::ID_NUMBERS[$id] ?? null,
                'first_name' => $first,
                'middle_name' => null,
                'last_name' => $last,
                'suffix' => null,
                'position_level' => $monthly >= 40000 ? 'Senior' : ($monthly >= 25000 ? 'Mid' : 'Junior'),
                'employment_status' => $status,
                'is_active' => true,
                'work_site_id' => in_array($id, ['emp-6', 'emp-8'], true)
                    ? $sites['site-3']
                    : $sites[$i % 2 === 0 ? 'site-1' : 'site-2'],
                'photo_path' => null,
                'birth_date' => $this->dateKey($daysEmployed + 9000),
                'sex' => $i % 2 === 0 ? 'Female' : 'Male',
                'civil_status' => $i % 3 === 0 ? 'Married' : 'Single',
                'address' => sprintf('Blk %d Lt %d, Barangay Poblacion, Tayabas City', $i + 3, $i + 11),
                'phone' => '09'.substr((string) (170000000 + $i * 1111111), 0, 9),
                'email' => strtolower($first.'.'.$last.'@bestworkflows.io'),
                'residence_cert' => null,
                'emergency_name' => null,
                'emergency_relation' => null,
                'emergency_phone' => null,
                'date_hired' => $this->dateKey($daysEmployed),
                'date_regular' => $status === 'Regular' ? $this->dateKey($daysSinceRegular) : null,
                'date_resigned' => null,
                'monthly_rate' => $monthly,
                'hourly_rate' => 0,
                'allowances' => $allowanceUnits * 250,
                'sss_no' => sprintf('34-%07d-%d', 9000000 + $i * 54321, $i % 10),
                'philhealth_no' => sprintf('08-%09d', 26000000 + $i * 12345),
                'pagibig_no' => '1213'.(89900000 + $i * 4321),
                'tin' => (string) (683000000 + $i * 12345),
                'bank_account_no' => '0124'.(88700000 + $i * 999),
                'bank_code' => 'GOTYPHM2XXX',
                'bank_type' => 'Digital Bank',
                'pay_code' => 'SEMI',
                'agency_code' => 'COP',
                'section_code' => null,
                'tax_code' => null,
                'allow_sss' => true,
                'allow_philhealth' => true,
                'allow_pagibig' => true,
                'allow_withholding_tax' => true,
                'annualize_tax' => false,
                'fixed_tax' => 0,
                'percent_tax' => 0,
                'fixed_sss_ee' => 0,
                'fixed_sss_er' => 0,
                'fixed_philhealth_ee' => 0,
                'fixed_philhealth_er' => 0,
                'fixed_pagibig_ee' => 0,
                'fixed_pagibig_er' => 0,
                'sss_mode' => 'SEMI_MONTHLY',
                'philhealth_mode' => 'SEMI_MONTHLY',
                'pagibig_mode' => 'SEMI_MONTHLY',
                'created_at' => $this->at(min($daysEmployed, 200), 9, 0),
            ]);
        }

        $this->accounts();
    }

    /**
     * The seeded title and its link to the Position list: the one position
     * of that name (ReferenceListSeeder runs first), or no link when none or
     * several carry it. A row already linked keeps its link and the name it
     * shows, so a re-run never undoes a rename or a re-assignment HR made.
     * Before `position_id` exists only the title is written.
     *
     * @param  array<string, mixed>  $match
     * @return array<string, mixed>
     */
    private function positionLink(array $match, string $position): array
    {
        if (! Schema::hasColumn('employee_profiles', 'position_id')) {
            return ['position' => $position];
        }

        $existing = EmployeeProfile::query()->where($match)->first(['id', 'position', 'position_id']);

        if ($existing !== null && $existing->position_id !== null) {
            return ['position' => $existing->position, 'position_id' => $existing->position_id];
        }

        $ids = Position::query()->where('name', $position)->limit(2)->pluck('id')->all();

        return ['position' => $position, 'position_id' => count($ids) === 1 ? $ids[0] : null];
    }

    private function accounts(): void
    {
        $employees = $this->employeeIdsByIndex();

        // email, role name, employee index, last login (days ago, h, m)
        $accounts = [
            ['admin@bestworkflows.local', 'System Admin', 0, [0, 8, 5]],
            ['payroll@bestworkflows.local', 'Payroll Officer', 1, [1, 9, 5]],
            ['supervisor@bestworkflows.local', 'Supervisor', 2, [2, 7, 45]],
            ['employee@bestworkflows.local', 'Employee', 3, [1, 7, 55]],
        ];

        foreach ($accounts as [$email, $roleName, $empIdx, $login]) {
            $user = User::query()->where('email', $email)->first();

            if ($user === null) {
                $user = new User;
                $user->forceFill([
                    'email' => $email,
                    // The reference's demo credential (seed.ts DEMO_ACCOUNTS).
                    'password' => 'bestworkflows123',
                    'email_verified_at' => CarbonImmutable::now(),
                    'last_login_at' => $this->at($login[0], $login[1], $login[2]),
                ]);
            }

            $user->forceFill([
                'role_id' => Role::query()->where('name', $roleName)->value('id'),
                'employee_id' => $employees[$empIdx] ?? null,
                'is_active' => true,
            ]);

            $user->save();
        }
    }
}
