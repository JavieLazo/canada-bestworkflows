<?php

declare(strict_types=1);

use App\Modules\WorkManagement\Domain\Projects\CapacityWindow;
use App\Modules\WorkManagement\Domain\Projects\PlanningHours;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class);

it('keeps blank zero fractional and maximum weekly hours distinct and exact', function () {
    expect(PlanningHours::parse(''))->toBeNull()->and(PlanningHours::parse('0'))->toBe(0)
        ->and(PlanningHours::parse('.5'))->toBe(50)->and(PlanningHours::parse(' 12.34 '))->toBe(1234)->and(PlanningHours::parse('168'))->toBe(16800)
        ->and(PlanningHours::format(1234))->toBe('12.34')->and(PlanningHours::format(0))->toBe('0');
});

it('refuses invalid weekly hours', function (string $hours) {
    expect(fn () => PlanningHours::parse($hours))->toThrow(ValidationException::class);
})->with(['-1', '168.01', '169', '1.001', '1e2', '12 hours', '=1+2']);

it('uses bounded Monday windows without spreading estimates across guessed working days', function () {
    expect(CapacityWindow::weeks('2026-11-02'))->toBe(['2026-11-02', '2026-11-09', '2026-11-16', '2026-11-23'])
        ->and(CapacityWindow::move('2026-11-02', 1))->toBe('2026-11-30')
        ->and(CapacityWindow::move('2000-01-03', -1))->toBe('2000-01-03')
        ->and(CapacityWindow::weeks('2099-12-28')[3])->toBeLessThanOrEqual('2099-12-31');
    expect(fn () => CapacityWindow::validateWeek('2026-11-03'))->toThrow(ValidationException::class);
});
