<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

/** Date overrides recreate and retry with every named index and foreign key intact. */
it('recreates date override storage after repeated down and up calls', function () {
    $migration = require base_path('database/migrations/2026_09_28_0002_create_roster_day_overrides_table.php');
    try {
        $migration->down();
        $migration->down();
        expect(Schema::hasTable('roster_day_overrides'))->toBeFalse();
        $migration->up();
        $migration->up();
        expect(Schema::hasIndex('roster_day_overrides', 'rdate_employee_date_unq', 'unique'))->toBeTrue()
            ->and(Schema::hasIndex('roster_day_overrides', 'rdate_type_idx'))->toBeTrue()
            ->and(Schema::hasIndex('roster_day_overrides', 'rdate_site_idx'))->toBeTrue()
            ->and(Schema::hasForeignKey('roster_day_overrides', ['employee_id']))->toBeTrue()
            ->and(Schema::hasForeignKey('roster_day_overrides', ['shift_type_id']))->toBeTrue()
            ->and(Schema::hasForeignKey('roster_day_overrides', ['site_id']))->toBeTrue();
    } finally {
        $migration->up();
    }
});
