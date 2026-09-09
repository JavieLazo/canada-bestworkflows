<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Port of `model UserAccount` (prisma/schema.prisma:102-133).
 *
 * The source table is `user_accounts`. Here it maps onto the starter kit's
 * existing `users` table, which Fortify (passwords, two-factor, passkeys) and
 * every session already bind to. That table is EXTENDED rather than replaced.
 * Three divergences follow from that and are deliberate:
 *
 *  1. `users.id` stays `bigint unsigned auto_increment`. It is the one primary
 *     key in this schema that is not a ULID char(26), because `sessions.user_id`
 *     and `passkeys.user_id` already point at it. Every foreign key to an
 *     account in the ported tables is therefore `unsignedBigInteger`, not
 *     `char(26)`.
 *  2. `UserAccount.full_name` maps onto the existing `users.name`. No
 *     `full_name` column is added — App\Models\User::initials() and
 *     App\Actions\Fortify\CreateNewUser both read `name`, and duplicating the
 *     value would let the two drift. The Eloquent model should expose
 *     `full_name` as an accessor over `name`.
 *  3. `UserAccount.password_hash` maps onto the existing `users.password`,
 *     which is made NULLABLE here. That nullability is load-bearing: the source
 *     seeds accounts with no hash at all (UserAccountRow in src/lib/seed.ts:40-51
 *     has no password field) and the first-run setup route sets the first one.
 *     Note the source hashes with scrypt (src/lib/password.ts) while Laravel's
 *     User model casts `password` to 'hashed' (bcrypt): existing hashes do NOT
 *     carry across, which is a data-migration concern, not a schema one.
 *
 * The four TIMESTAMP columns are converted to DATETIME(3) so that every
 * timestamp in the system has one type and one meaning — UTC, milliseconds,
 * no session-time-zone conversion on read or write, and no 2038 ceiling.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t): void {
            // ->after() is a MySQL-only modifier; SQLite ignores it.
            $t->char('role_id', 26)->nullable()->after('password');
            $t->char('employee_id', 26)->nullable()->after('role_id');
            $t->boolean('is_active')->default(true)->after('employee_id');
            $t->dateTime('last_login_at', 3)->nullable()->after('is_active');

            $t->index('employee_id', 'users_employee_idx');

            // Both SET NULL, matching the source: deleting a role or an employee
            // record must not delete the account that referenced it.
            // Indexes first so MySQL reuses users_employee_idx for the second.
            $t->foreign('role_id')->references('id')->on('roles')->nullOnDelete();
            $t->foreign('employee_id')->references('id')->on('employee_profiles')->nullOnDelete();
        });

        Schema::table('users', function (Blueprint $t): void {
            // Nullable so an account can exist before its first password is set.
            $t->string('password')->nullable()->change();

            // TIMESTAMP -> DATETIME(3), UTC. TIMESTAMP converts using the
            // session time zone on every read and write, which shared hosts set
            // inconsistently, and tops out in 2038.
            $t->dateTime('email_verified_at', 3)->nullable()->change();
            $t->dateTime('two_factor_confirmed_at', 3)->nullable()->change();
            $t->dateTime('created_at', 3)->nullable()->change();
            $t->dateTime('updated_at', 3)->nullable()->change();
        });

        $this->dynamicRowFormat('users');
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t): void {
            $t->dropForeign(['role_id']);
            $t->dropForeign(['employee_id']);
            $t->dropIndex('users_employee_idx');
            $t->dropColumn(['role_id', 'employee_id', 'is_active', 'last_login_at']);
        });

        Schema::table('users', function (Blueprint $t): void {
            $t->string('password')->nullable(false)->change();
            $t->timestamp('email_verified_at')->nullable()->change();
            $t->timestamp('two_factor_confirmed_at')->nullable()->change();
            $t->timestamp('created_at')->nullable()->change();
            $t->timestamp('updated_at')->nullable()->change();
        });
    }

    private function dynamicRowFormat(string ...$tables): void
    {
        if (! in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        foreach ($tables as $table) {
            DB::statement("ALTER TABLE `{$table}` ROW_FORMAT=DYNAMIC");
        }
    }
};
