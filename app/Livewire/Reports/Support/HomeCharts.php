<?php

declare(strict_types=1);

namespace App\Livewire\Reports\Support;

use App\Modules\Payroll\Application\Queries\PayrollReports;
use App\Modules\Timekeeping\Application\Queries\TimekeepingReports;
use App\Platform\Reports\ReportCriteria;
use App\Support\Access;
use App\Support\HrisFormat;
use App\Support\ManilaClock;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * The graphs on the Reports home. Each one is an owner's own authorized report, drawn small:
 * nothing is counted here, and a graph whose source the role does not hold is not drawn at all.
 * A source too large for one report leaves its graph out rather than failing the page.
 */
final class HomeCharts
{
    private const int PAYROLLS = 6;

    private const int BARS = 5;

    /**
     * @return list<array{title: string, unit: string, kind: 'columns'|'bars', href: string, points: list<array{label: string, value: float, display: string}>}>
     */
    public static function for(Access $actor): array
    {
        $today = ManilaClock::todayKey();
        $charts = [];
        foreach ([self::netPay($actor, $today), self::leave($actor, $today), self::departments($actor, $today)] as $chart) {
            if ($chart !== null) {
                $charts[] = $chart;
            }
        }

        return $charts;
    }

    /** @return array{title: string, unit: string, kind: 'columns', href: string, points: list<array{label: string, value: float, display: string}>}|null */
    private static function netPay(Access $actor, string $today): ?array
    {
        if (! ReportCatalog::allows($actor, 'payroll-summary')) {
            return null;
        }
        // A payroll is often posted before its period ends: the window runs to the newest posted period, not to today.
        $to = max($today, app(PayrollReports::class)->latestPeriod($actor)['to'] ?? $today);
        $from = CarbonImmutable::createFromFormat('!Y-m-d', $to)->subDays(150)->format('Y-m-d');
        try {
            $document = ReportCatalog::generate($actor, ReportCriteria::from(['type' => 'payroll-summary', 'from' => $from, 'to' => $to]));
        } catch (ValidationException) {
            return null;
        }
        $points = [];
        // The owner's rows: run, kind, period from, period to, payslips, employees, gross, deductions, net, employer.
        foreach (array_slice($document->rows, -self::PAYROLLS) as $row) {
            $net = (float) ($row[8] ?? 0);
            $points[] = [
                'label' => CarbonImmutable::createFromFormat('!Y-m-d', (string) ($row[3] ?? $today))->format('M j'),
                'value' => $net, 'display' => self::compact($net),
            ];
        }

        return ['title' => 'Net Pay by Payroll', 'unit' => 'PHP, last '.self::PAYROLLS.' posted payrolls', 'kind' => 'columns',
            'href' => route('reports.show', ['report' => 'payroll-summary']), 'points' => $points];
    }

    /** @return array{title: string, unit: string, kind: 'bars', href: string, points: list<array{label: string, value: float, display: string}>}|null */
    private static function leave(Access $actor, string $today): ?array
    {
        if (! ReportCatalog::allows($actor, 'leave-reports')) {
            return null;
        }
        $from = substr($today, 0, 8).'01';
        try {
            $rows = app(TimekeepingReports::class)->approvedLeaveByType($actor, $from, $today);
        } catch (ValidationException) {
            return null;
        }
        $points = [];
        foreach (array_slice($rows, 0, self::BARS) as $row) {
            $points[] = ['label' => $row['label'], 'value' => $row['value'], 'display' => rtrim(rtrim(number_format($row['value'], 1, '.', ','), '0'), '.')];
        }

        return ['title' => 'Approved Leave This Month', 'unit' => 'Days, '.HrisFormat::date($from).' – '.HrisFormat::date($today), 'kind' => 'bars',
            'href' => route('reports.show', ['report' => 'leave-reports']), 'points' => $points];
    }

    /** @return array{title: string, unit: string, kind: 'bars', href: string, points: list<array{label: string, value: float, display: string}>}|null */
    private static function departments(Access $actor, string $today): ?array
    {
        if (! ReportCatalog::allows($actor, 'employee-list')) {
            return null;
        }
        try {
            $document = ReportCatalog::generate($actor, ReportCriteria::from([
                'type' => 'employee-list', 'view' => 'analysis', 'from' => $today, 'to' => $today,
                'filters' => ['active' => 'ACTIVE', 'analysis' => 'composition', 'groupBy' => 'department', 'crossTab' => 'none'],
            ]));
        } catch (ValidationException) {
            return null;
        }
        $points = [];
        foreach (array_slice($document->charts[0]['points'] ?? [], 0, self::BARS) as $point) {
            $points[] = ['label' => $point['label'], 'value' => (float) $point['value'], 'display' => number_format((float) $point['value'])];
        }

        return ['title' => 'Employees by Department', 'unit' => 'Active employees, largest '.self::BARS.' groups', 'kind' => 'bars',
            'href' => route('reports.show', ['report' => 'employee-list', 'view' => 'analysis']), 'points' => $points];
    }

    /** 3140000 reads "3.14M", 75020 reads "75.0K": a column's cap has no room for more. */
    public static function compact(float $amount): string
    {
        return match (true) {
            abs($amount) >= 1_000_000 => number_format($amount / 1_000_000, 2).'M',
            abs($amount) >= 1_000 => number_format($amount / 1_000, 1).'K',
            default => number_format($amount, 0),
        };
    }
}
