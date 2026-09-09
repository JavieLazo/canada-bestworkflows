<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\EmployeeProfile;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkSite;
use Carbon\CarbonImmutable;

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
 * (emp-1) so scoped screens show data. An existing account keeps its name and
 * password; only role, employee link and active flag are asserted.
 */
final class EmployeeSeeder extends PortSeeder
{
    private const SITES = [
        ['site-1', 'Makati Head Office', 14.5547, 121.0244, 120, false],
        ['site-2', 'Quezon City Depot', 14.676, 121.0437, 200, false],
        ['site-3', 'Remote / Field Work', 0.0, 0.0, 0, true],
    ];

    /** id, code, first, last, position, monthly rate, allowance units, status, days employed */
    private const EMPLOYEES = [
        ['emp-1', 'BWF-00001', 'Maria', 'Santos', 'HR Manager', 62000.0, 5, 'Regular', 1400],
        ['emp-2', 'BWF-00002', 'Jose', 'Rivera', 'Payroll Specialist', 48000.0, 4, 'Regular', 1100],
        ['emp-3', 'BWF-00003', 'Andrea', 'Lim', 'Site Supervisor', 42000.0, 3, 'Regular', 900],
        ['emp-4', 'BWF-00004', 'Paolo', 'Bautista', 'Security Officer', 24000.0, 2, 'Regular', 640],
        ['emp-5', 'BWF-00005', 'Grace', 'Mendoza', 'Security Officer', 24000.0, 2, 'Probationary', 120],
        ['emp-6', 'BWF-00006', 'Nathaniel', 'Cruz', 'Field Technician', 28000.0, 2, 'Contractual', 300],
        ['emp-7', 'BWF-00007', 'Liza', 'Domingo', 'Admin Assistant', 22000.0, 1, 'Probationary', 95],
        ['emp-8', 'BWF-00008', 'Ramon', 'Aquino', 'Driver', 20000.0, 1, 'Job Order', 210],
    ];

    public function run(): void
    {
        foreach (self::SITES as [$id, $name, $lat, $lng, $radius, $exempt]) {
            $this->upsert(WorkSite::class, ['id' => $id], [
                'name' => $name,
                'latitude' => $lat,
                'longitude' => $lng,
                'radius_meters' => $radius,
                'geofence_exempt' => $exempt,
                'created_at' => $this->at(300, 9, 0),
            ]);
        }

        foreach (self::EMPLOYEES as $i => [$id, $code, $first, $last, $position, $monthly, $allowanceUnits, $status, $daysEmployed]) {
            $daysEmployed = (int) $daysEmployed;
            $daysSinceRegular = $daysEmployed > 181 ? $daysEmployed - 180 : 1;
            $this->upsert(EmployeeProfile::class, ['employee_code' => $code], [
                'id' => $id,
                'first_name' => $first,
                'middle_name' => null,
                'last_name' => $last,
                'suffix' => null,
                'position' => $position,
                'position_level' => $monthly >= 40000 ? 'Senior' : ($monthly >= 25000 ? 'Mid' : 'Junior'),
                'employment_status' => $status,
                'is_active' => true,
                'work_site_id' => in_array($id, ['emp-6', 'emp-8'], true)
                    ? 'site-3'
                    : ($i % 2 === 0 ? 'site-1' : 'site-2'),
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
                'vacation_credits' => 15,
                'sick_credits' => 15,
                'pay_code' => 'SEMI',
                'agency_code' => 'BWF',
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

    private function accounts(): void
    {
        $employees = $this->employeeIdsByIndex();

        // email, full name, role name, employee index, last login (days ago, h, m)
        $accounts = [
            ['admin@bestworkflows.local', 'Maria Santos', 'System Admin', 0, [0, 8, 5]],
            ['payroll@bestworkflows.local', 'Jose Rivera', 'Payroll Officer', 1, [1, 9, 5]],
            ['supervisor@bestworkflows.local', 'Andrea Lim', 'Supervisor', 2, [2, 7, 45]],
            ['employee@bestworkflows.local', 'Paolo Bautista', 'Employee', 3, [1, 7, 55]],
        ];

        foreach ($accounts as [$email, $name, $roleName, $empIdx, $login]) {
            $user = User::query()->where('email', $email)->first();

            if ($user === null) {
                $user = new User;
                $user->forceFill([
                    'name' => $name,
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
