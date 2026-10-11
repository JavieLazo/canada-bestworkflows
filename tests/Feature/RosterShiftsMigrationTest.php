<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

/** The weekday-hours schema supports repeated rollbacks and retry-safe creation. */
it('recreates weekday shifts with the named unique, indexes and foreign keys on repeated up calls', function () {
    $migration = require base_path('database/migrations/2026_09_28_0001_create_roster_shifts_table.php');
    try {
        $migration->down();
        $migration->down();
        expect(Schema::hasTable('roster_shifts'))->toBeFalse();
        $migration->up();
        $migration->up();
        expect(Schema::hasIndex('roster_shifts', 'rshift_employee_weekday_unq', 'unique'))->toBeTrue()
            ->and(Schema::hasIndex('roster_shifts', 'rshift_type_idx'))->toBeTrue()
            ->and(Schema::hasForeignKey('roster_shifts', ['employee_id']))->toBeTrue()
            ->and(Schema::hasForeignKey('roster_shifts', ['shift_type_id']))->toBeTrue();
    } finally {
        $migration->up();
    }
});
