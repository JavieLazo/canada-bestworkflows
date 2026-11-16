<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application;

use App\Platform\Reports\ReportCriteria;
use App\Platform\Reports\ReportDocument;
use App\Support\EmployeeIdentity;
use App\Support\ManilaClock;
use DateTimeImmutable;
use Illuminate\Validation\ValidationException;

/** Builds management tables from an already-scoped cohort; counts never infer historical headcount or statutory compliance. */
final class EmployeeAnalyticsDocument
{
    /** @var array<string, string> */
    public const array QUALITY_FIELDS = [
        'employee_id' => 'Employee ID', 'position' => 'Position', 'department' => 'Department',
        'worksite' => 'Worksite', 'email' => 'Email', 'mobile' => 'Mobile number',
        'birth_date' => 'Birth date', 'sex' => 'Sex at birth', 'civil_status' => 'Civil status', 'education' => 'Education',
    ];

    /** @return array<string, string> */
    public static function topics(): array
    {
        return ['composition' => 'Workforce Composition', 'demographics' => 'Demographics', 'development' => 'Education and Development', 'service' => 'Service and Recorded Movements', 'quality' => 'Record Quality'];
    }

    /** @return array<string, string> */
    public static function groupings(): array
    {
        return ['department' => 'Department', 'unit' => 'Unit', 'position' => 'Position', 'worksite' => 'Worksite', 'employment' => 'Employment Status', 'active' => 'Active Status', 'sex' => 'Sex at Birth', 'age' => 'Age Band', 'tenure' => 'Tenure Band', 'education' => 'Highest Recorded Education'];
    }

    /** @return array<string, string> */
    public static function crossTabs(): array
    {
        return ['none' => 'No comparison', 'sex' => 'Sex at Birth', 'employment' => 'Employment Status', 'active' => 'Active Status', 'age' => 'Age Band', 'tenure' => 'Tenure Band', 'education' => 'Highest Recorded Education'];
    }

    /**
     * @param  list<array<string, mixed>>  $facts
     * @param  list<array{employee_id: string, date_to: string, hours: ?float, type: string}>  $trainings
     * @param  list<array{employee_id: string, name: string, expires: ?string}>  $eligibilities
     */
    public static function build(ReportCriteria $criteria, array $facts, array $trainings, array $eligibilities, string $coverage): ReportDocument
    {
        $topic = $criteria->filters['analysis'] ?? 'composition';
        $group = $criteria->filters['groupBy'] ?? 'department';
        $cross = $criteria->filters['crossTab'] ?? 'sex';
        $primary = self::matrix($facts, $group, $cross);
        $count = count($facts);
        $active = count(array_filter($facts, static fn (array $fact): bool => $fact['active'] === 'Active'));
        $summary = [self::metric('Employees', $count), self::metric('Active', $active), self::metric('Inactive', $count - $active), self::metric('Departments Represented', count(array_unique(array_filter(array_column($facts, 'department'), static fn (mixed $value): bool => $value !== ''))))];
        $sections = [];
        $notes = [
            $coverage,
            'Management analysis of current employee records as of '.ManilaClock::todayKey().'. Employment status is the recorded company classification; this is not a prescribed government submission or an official appointment classification.',
            'Every count and panel uses the same permitted, filtered employees. Shares use that full cohort, including Not recorded; unknown values are never treated as zero. Displayed shares may differ slightly from 100% because of formatting.',
            'Department, unit, position and worksite are current labels. The position catalog is not an authorized staffing or vacancy register.',
        ];
        if ($topic === 'composition') {
            $sections = [self::distribution($facts, 'employment'), self::distribution($facts, 'active'), self::distribution($facts, 'age'), self::distribution($facts, 'tenure')];
        } elseif ($topic === 'demographics') {
            $ages = array_values(array_filter(array_column($facts, 'age'), static fn (mixed $value): bool => $value !== null));
            $summary = [self::metric('Employees', $count), self::metric('Known Ages', count($ages)), self::metric('Age Not Recorded', $count - count($ages)), self::metric('Average Age (years)', $ages === [] ? '—' : number_format(array_sum($ages) / count($ages), 1, '.', '')), self::metric('Median Age (years)', self::median($ages)), self::metric('Sex Not Recorded', count(array_filter($facts, static fn (array $fact): bool => $fact['sex'] === '')))];
            $sections = [self::distribution($facts, 'sex'), self::distribution($facts, 'age'), self::distribution($facts, 'civil_status'), self::distribution($facts, 'tenure')];
            $notes[] = 'Age is completed calendar years; averages and medians use only known non-future birth dates. Sex means sex at birth as recorded. Civil status Other retains its recorded category.';
        } elseif ($topic === 'development') {
            [$summary, $sections] = self::development($criteria, $facts, $trainings, $eligibilities);
            $notes[] = 'Highest recorded education describes attendance or study, not verified completion. Structured schools take precedence over legacy details within each level. Graduate studies is not a separate masters or doctorate classification.';
            $notes[] = 'Training counts employee training records completed during the selected period, using the recorded end date through '.min($criteria->to, ManilaClock::todayKey()).' (Manila). Future end dates are excluded. One event attended by several employees creates several records; hours are reported participant-hours, with missing hours counted separately.';
            $notes[] = 'Eligibility entries are current records. An absent expiry date means Not recorded, not lifetime validity; this analysis does not assess qualification requirements.';
        } elseif ($topic === 'service') {
            [$summary, $sections] = self::service($criteria, $facts);
            $notes[] = 'Joined, regularized and resigned counts use the dates currently recorded on each employee. They do not reconstruct historical headcount, turnover, promotions, transfers or all types of separation. Rehires and breaks in service are not modeled as separate service episodes.';
            $notes[] = 'Tenure is completed years from the recorded date joined to today, ending on an earlier recorded resignation. Active status remains the independent current employee flag.';
        } else {
            [$summary, $sections] = self::quality($facts);
            $notes[] = 'Completeness measures only the ten named fields. It is not a legal requirement or proof that a value is correct. Unit is optional. Whitespace and null are missing; recorded zero is retained.';
            $notes[] = 'Exception flags request a record review; they do not change the employee record or infer employment decisions. No salary, government identifier or private declaration is analyzed.';
        }

        $charts = [self::chart($facts, $group, 'Employees by '.self::groupings()[$group])];
        $secondary = match ($topic) {
            'composition' => 'employment', 'demographics' => 'age', 'development' => 'education', 'service' => 'tenure', default => null
        };
        if ($secondary !== null && $secondary !== $group) {
            $charts[] = self::chart($facts, $secondary, self::groupings()[$secondary].' Distribution');
        } elseif ($topic === 'quality') {
            $points = [];
            foreach (self::QUALITY_FIELDS as $key => $label) {
                $points[] = ['label' => $label, 'value' => count(array_filter($facts, static fn (array $fact): bool => $fact[$key] === ''))];
            }
            $charts[] = ['title' => 'Missing Information by Field', 'unit' => 'employees', 'points' => $points, 'note' => 'One employee can be missing more than one field; counts are not additive.'];
        }

        return new ReportDocument(self::topics()[$topic], $primary['columns'], $primary['rows'], $notes, $sections, $summary, $primary['totals'], $primary['numericColumns'], [], $charts);
    }

    /** @param list<array<string, mixed>> $facts
     * @return array{title: string, kind: 'table', columns: list<string>, rows: list<list<string>>, notes: list<string>, totals: list<string>, numericColumns: list<int>}
     */
    private static function matrix(array $facts, string $group, string $cross): array
    {
        $counts = [];
        $crosses = [];
        foreach ($facts as $fact) {
            $label = self::bucket($fact, $group);
            $counts[$label]['count'] = ($counts[$label]['count'] ?? 0) + 1;
            if ($cross !== 'none') {
                $column = self::bucket($fact, $cross);
                $counts[$label]['cross'][$column] = ($counts[$label]['cross'][$column] ?? 0) + 1;
                $crosses[$column] = ($crosses[$column] ?? 0) + 1;
            }
        }
        if (count($crosses) > 20) {
            throw ValidationException::withMessages(['filters.crossTab' => 'This comparison has more than 20 categories. Narrow the cohort or choose another comparison.']);
        }
        uksort($counts, static fn (string $left, string $right): int => self::bucketOrder($left, $right, $group));
        uksort($crosses, static fn (string $left, string $right): int => self::bucketOrder($left, $right, $cross));
        $columns = [self::groupings()[$group] ?? 'Civil Status', 'Employees', 'Share of Cohort'];
        $columns = [...$columns, ...array_map(static fn (int|string $key): string => (string) $key, array_keys($crosses))];
        if (count($counts) * count($columns) > ReportDocument::MAX_ROWS) {
            throw ValidationException::withMessages(['filters.groupBy' => 'This comparison exceeds 5,000 table cells. Choose a broader grouping, remove the comparison or narrow the employee filters.']);
        }
        $rows = [];
        foreach ($counts as $label => $values) {
            $row = [(string) $label, (string) $values['count'], self::share($values['count'], count($facts))];
            foreach ($crosses as $column => $unused) {
                $row[] = (string) ($values['cross'][$column] ?? 0);
            }
            $rows[] = $row;
        }
        $totals = ['Total', (string) count($facts), self::share(count($facts), count($facts)), ...array_map(static fn (int $value): string => (string) $value, array_values($crosses))];
        $title = (self::groupings()[$group] ?? 'Civil Status').($cross === 'none' ? ' Distribution' : ' by '.self::crossTabs()[$cross]);

        return self::section($title, $columns, $rows, $cross === 'none' ? [] : ['Each comparison column is a count of employees; the comparison cells in each row add to Employees.'], $totals, range(1, count($columns) - 1));
    }

    /** @param list<array<string, mixed>> $facts
     * @return array{title: string, kind: 'table', columns: list<string>, rows: list<list<string>>, notes: list<string>, totals: list<string>, numericColumns: list<int>}
     */
    private static function distribution(array $facts, string $dimension): array
    {
        return self::matrix($facts, $dimension, 'none');
    }

    /** @param array<string, mixed> $fact */
    private static function bucket(array $fact, string $key): string
    {
        if ($key === 'age') {
            $age = $fact['age'];

            return match (true) {
                $age === null => 'Not recorded', $age < 25 => 'Under 25', $age < 35 => '25–34', $age < 45 => '35–44', $age < 55 => '45–54', $age < 65 => '55–64', default => '65 and above'
            };
        }
        if ($key === 'tenure') {
            $years = $fact['tenure'];

            return match (true) {
                $years === null => 'Not recorded', $years < 1 => 'Under 1 year', $years < 5 => '1–4 years', $years < 10 => '5–9 years', $years < 20 => '10–19 years', default => '20 years and above'
            };
        }
        if ($key === 'education') {
            return EmployeeFields::EDUCATION_LEVELS[$fact['education_level']] ?? 'Not recorded';
        }
        if ($key === 'unit') {
            return ($fact['department'] !== '' ? $fact['department'].' / ' : '').($fact['unit'] !== '' ? $fact['unit'] : 'No unit recorded');
        }
        $value = trim((string) ($fact[$key] ?? ''));
        if ($key === 'employment' && in_array(EmployeeFields::statusKey($value), ['REGULAR', 'PROBATIONARY', 'CONTRACTUAL', 'JOB_ORDER'], true)) {
            return EmployeeFields::employmentStatusLabel(EmployeeFields::statusKey($value));
        }

        return $value === '' ? 'Not recorded' : $value;
    }

    private static function bucketOrder(string $left, string $right, string $dimension): int
    {
        if ($left === 'Not recorded' || $right === 'Not recorded') {
            return ($left === 'Not recorded' ? 1 : 0) <=> ($right === 'Not recorded' ? 1 : 0);
        }
        $order = match ($dimension) {
            'age' => ['Under 25', '25–34', '35–44', '45–54', '55–64', '65 and above'],
            'tenure' => ['Under 1 year', '1–4 years', '5–9 years', '10–19 years', '20 years and above'],
            'education' => array_values(EmployeeFields::EDUCATION_LEVELS),
            default => [],
        };

        return $order === [] ? strnatcasecmp($left, $right) : array_search($left, $order, true) <=> array_search($right, $order, true);
    }

    /**
     * @param  list<array<string, mixed>>  $facts
     * @param  list<array{employee_id: string, date_to: string, hours: ?float, type: string}>  $trainings
     * @param  list<array{employee_id: string, name: string, expires: ?string}>  $eligibilities
     * @return array{list<array{label: string, value: string}>, list<array{title: string, kind: 'table', columns: list<string>, rows: list<list<string>>, notes: list<string>, totals: list<string>, numericColumns: list<int>}>}
     */
    private static function development(ReportCriteria $criteria, array $facts, array $trainings, array $eligibilities): array
    {
        $trainingGroups = [];
        $hours = 0.0;
        $missing = 0;
        $participants = [];
        foreach ($trainings as $training) {
            $type = $training['type'] === '' ? 'Not recorded' : $training['type'];
            $trainingGroups[$type]['entries'] = ($trainingGroups[$type]['entries'] ?? 0) + 1;
            $trainingGroups[$type]['employees'][$training['employee_id']] = true;
            $participants[$training['employee_id']] = true;
            $unknown = $training['hours'] === null;
            $trainingGroups[$type]['missing'] = ($trainingGroups[$type]['missing'] ?? 0) + ($unknown ? 1 : 0);
            $trainingGroups[$type]['hours'] = ($trainingGroups[$type]['hours'] ?? 0.0) + ($training['hours'] ?? 0.0);
            $hours += $training['hours'] ?? 0.0;
            $missing += $unknown ? 1 : 0;
        }
        uksort($trainingGroups, strnatcasecmp(...));
        $trainingRows = [];
        foreach ($trainingGroups as $type => $group) {
            $trainingRows[] = [(string) $type, (string) $group['entries'], (string) count($group['employees']), number_format($group['hours'], 2, '.', ''), (string) $group['missing']];
        }
        $eligible = [];
        $expiry = ['Expired' => 0, 'Expires within 90 days' => 0, 'Later expiry' => 0, 'Expiry not recorded' => 0];
        $eligibilityGroups = [];
        $today = ManilaClock::todayKey();
        $soon = (new DateTimeImmutable($today))->modify('+90 days')->format('Y-m-d');
        foreach ($eligibilities as $entry) {
            $eligible[$entry['employee_id']] = true;
            $name = $entry['name'] === '' ? 'Not recorded' : $entry['name'];
            $eligibilityGroups[$name]['entries'] = ($eligibilityGroups[$name]['entries'] ?? 0) + 1;
            $eligibilityGroups[$name]['employees'][$entry['employee_id']] = true;
            $bucket = match (true) {
                $entry['expires'] === null => 'Expiry not recorded', $entry['expires'] < $today => 'Expired', $entry['expires'] <= $soon => 'Expires within 90 days', default => 'Later expiry'
            };
            $expiry[$bucket]++;
        }
        uksort($eligibilityGroups, strnatcasecmp(...));
        $eligibilityRows = [];
        foreach ($eligibilityGroups as $name => $group) {
            $eligibilityRows[] = [(string) $name, (string) $group['entries'], (string) count($group['employees'])];
        }
        $expiryRows = [];
        foreach ($expiry as $label => $total) {
            $expiryRows[] = [$label, (string) $total];
        }
        $educationCount = count(array_filter($facts, static fn (array $fact): bool => $fact['education_level'] !== 'NONE'));
        $summary = [self::metric('Employees', count($facts)), self::metric('Education Recorded', $educationCount), self::metric('Training Participants', count($participants)), self::metric('Training Records', count($trainings)), self::metric('Known Training Hours', number_format($hours, 2, '.', '')), self::metric('Hours Not Recorded', $missing)];
        $sections = [
            self::distribution($facts, 'education'),
            self::section('Training Completed: '.$criteria->from.' to '.$criteria->to, ['Training Type', 'Records', 'Employees', 'Known Hours', 'Hours Not Recorded'], $trainingRows, ['Completion cutoff: '.min($criteria->to, $today).' (Manila); future end dates are excluded. Employees can appear in more than one training type; the total employee count is distinct across the period.'], ['Total', (string) count($trainings), (string) count($participants), number_format($hours, 2, '.', ''), (string) $missing], [1, 2, 3, 4]),
            self::section('Recorded Eligibility', ['Eligibility', 'Entries', 'Employees'], $eligibilityRows, ['Employees may hold several different eligibilities; the total counts distinct employees.'], ['Total', (string) count($eligibilities), (string) count($eligible)], [1, 2]),
            self::section('Eligibility Expiry as of '.$today, ['Expiry Status', 'Entries'], $expiryRows, [], ['Total', (string) count($eligibilities)], [1]),
            self::section('Development Record Coverage', ['Coverage', 'Employees', 'Share of Cohort'], [
                ['Education recorded', (string) $educationCount, self::share($educationCount, count($facts))],
                ['Eligibility recorded', (string) count($eligible), self::share(count($eligible), count($facts))],
                ['Training completed in period', (string) count($participants), self::share(count($participants), count($facts))],
            ], ['Coverage categories overlap and are not additive.'], [], [1, 2]),
        ];

        return [$summary, $sections];
    }

    /** @param list<array<string, mixed>> $facts
     * @return array{list<array{label: string, value: string}>, list<array{title: string, kind: 'table', columns: list<string>, rows: list<list<string>>, notes: list<string>, totals: list<string>, numericColumns: list<int>}>}
     */
    private static function service(ReportCriteria $criteria, array $facts): array
    {
        $months = [];
        $cursor = new DateTimeImmutable(substr($criteria->from, 0, 7).'-01');
        while ($cursor->format('Y-m') <= substr($criteria->to, 0, 7)) {
            $months[$cursor->format('Y-m')] = ['date_hired' => 0, 'date_regular' => 0, 'date_resigned' => 0];
            $cursor = $cursor->modify('+1 month');
        }
        $totals = ['date_hired' => 0, 'date_regular' => 0, 'date_resigned' => 0];
        $movements = [];
        $labels = ['date_hired' => 'Joined', 'date_regular' => 'Regularized', 'date_resigned' => 'Resigned'];
        foreach ($facts as $fact) {
            foreach ($labels as $key => $label) {
                $date = $fact[$key];
                if ($date !== '' && $date >= $criteria->from && $date <= $criteria->to) {
                    $month = substr($date, 0, 7);
                    $months[$month][$key] = ($months[$month][$key] ?? 0) + 1;
                    $totals[$key]++;
                    $movements[] = [EmployeeIdentity::number(['employee_id_no' => $fact['employee_id']]), $fact['employee'], $label, $date, $fact['department'], $fact['employment']];
                }
            }
        }
        usort($movements, static fn (array $left, array $right): int => [$left[3], $left[1], $left[2]] <=> [$right[3], $right[1], $right[2]]);
        $rows = [];
        foreach ($months as $month => $counts) {
            $rows[] = [$month, (string) ($counts['date_hired'] ?? 0), (string) ($counts['date_regular'] ?? 0), (string) ($counts['date_resigned'] ?? 0)];
        }
        $tenures = array_values(array_filter(array_column($facts, 'tenure'), static fn (mixed $value): bool => $value !== null));
        $summary = [self::metric('Employees', count($facts)), self::metric('Known Tenure', count($tenures)), self::metric('Average Tenure (years)', $tenures === [] ? '—' : number_format(array_sum($tenures) / count($tenures), 1, '.', '')), self::metric('Joined in Period', $totals['date_hired']), self::metric('Regularized in Period', $totals['date_regular']), self::metric('Resigned in Period', $totals['date_resigned'])];
        $sections = [
            self::distribution($facts, 'tenure'),
            self::section('Recorded Movements by Month', ['Month', 'Joined', 'Regularized', 'Resigned'], $rows, ['Only recorded dates within '.$criteria->from.' to '.$criteria->to.' are counted, including partial first and last months. These counts do not provide a historical headcount denominator.'], ['Total', (string) $totals['date_hired'], (string) $totals['date_regular'], (string) $totals['date_resigned']], [1, 2, 3]),
            self::section('Employees Behind Recorded Movements', ['Employee ID', 'Employee', 'Recorded Movement', 'Recorded Date', 'Current Department', 'Current Employment Status'], $movements, ['One employee may have more than one date in the period. Dates are current record values, not employment-history event counts.']),
        ];

        return [$summary, $sections];
    }

    /** @param list<array<string, mixed>> $facts
     * @return array{list<array{label: string, value: string}>, list<array{title: string, kind: 'table', columns: list<string>, rows: list<list<string>>, notes: list<string>, totals: list<string>, numericColumns: list<int>}>}
     */
    private static function quality(array $facts): array
    {
        $missing = array_fill_keys(array_keys(self::QUALITY_FIELDS), 0);
        $incomplete = [];
        $exceptions = [];
        $today = ManilaClock::todayKey();
        foreach ($facts as $fact) {
            $absent = [];
            foreach (self::QUALITY_FIELDS as $key => $label) {
                if ($fact[$key] === '') {
                    $missing[$key]++;
                    $absent[] = $label;
                }
            }
            if ($absent !== []) {
                $incomplete[] = [EmployeeIdentity::number(['employee_id_no' => $fact['employee_id']]), $fact['employee'], $fact['department'], (string) count($absent), implode(', ', $absent)];
            }
            $issues = [];
            if ($fact['birth_date'] > $today) {
                $issues[] = 'Birth date is in the future';
            }
            if ($fact['date_hired'] > $today) {
                $issues[] = 'Date joined is in the future';
            }
            foreach (['date_regular' => 'Regularized', 'date_resigned' => 'Resigned'] as $key => $label) {
                if ($fact[$key] !== '' && $fact[$key] < $fact['date_hired']) {
                    $issues[] = $label.' before date joined';
                }
            }
            if ($fact['active'] === 'Active' && $fact['date_resigned'] !== '' && $fact['date_resigned'] <= $today) {
                $issues[] = 'Active with a recorded resignation';
            }
            if ($fact['email'] !== '' && filter_var($fact['email'], FILTER_VALIDATE_EMAIL) === false) {
                $issues[] = 'Email format needs review';
            }
            if ($issues !== []) {
                $exceptions[] = [EmployeeIdentity::number(['employee_id_no' => $fact['employee_id']]), $fact['employee'], $fact['department'], implode('; ', $issues)];
            }
        }
        $rows = [];
        foreach (self::QUALITY_FIELDS as $key => $label) {
            $rows[] = [$label, (string) (count($facts) - $missing[$key]), (string) $missing[$key], self::share(count($facts) - $missing[$key], count($facts))];
        }
        $summary = [self::metric('Employees', count($facts)), self::metric('Complete Assessed Records', count($facts) - count($incomplete)), self::metric('Missing Any Assessed Field', count($incomplete)), self::metric('Missing Values', array_sum($missing)), self::metric('Records Needing Review', count($exceptions)), self::metric('Fields Assessed', count(self::QUALITY_FIELDS))];
        $sections = [
            self::section('Field Completeness', ['Field', 'Recorded', 'Missing', 'Recorded Share'], $rows, ['The denominator for every row is the complete filtered employee cohort. Fields overlap; missing counts must not be read as distinct employees.'], [], [1, 2, 3]),
            self::section('Employees with Missing Information', ['Employee ID', 'Employee', 'Department', 'Missing Fields', 'Information to Complete'], $incomplete, ['Optional unit is not assessed.'], [], [3]),
            self::section('Record Exceptions to Review', ['Employee ID', 'Employee', 'Department', 'Review Flags'], $exceptions),
        ];

        return [$summary, $sections];
    }

    /** @param list<array<string, mixed>> $facts
     * @return array{title: string, unit: string, points: list<array{label: string, value: int|float}>, note: string}
     */
    private static function chart(array $facts, string $dimension, string $title): array
    {
        $counts = [];
        foreach ($facts as $fact) {
            $label = self::bucket($fact, $dimension);
            $counts[$label] = ($counts[$label] ?? 0) + 1;
        }
        uksort($counts, static fn (string $left, string $right): int => ($counts[$right] <=> $counts[$left]) ?: strnatcasecmp($left, $right));
        $points = [];
        foreach (array_slice($counts, 0, 10, true) as $label => $count) {
            $points[] = ['label' => (string) $label, 'value' => $count];
        }

        return ['title' => $title, 'unit' => 'employees', 'points' => $points, 'note' => count($points).' of '.count($counts).' groups shown, largest first. Complete counts, including Not recorded, remain in the tables.'];
    }

    /** @param list<int> $values */
    private static function median(array $values): string
    {
        if ($values === []) {
            return '—';
        }
        sort($values, SORT_NUMERIC);
        $middle = intdiv(count($values), 2);
        $value = count($values) % 2 === 0 ? ($values[$middle - 1] + $values[$middle]) / 2 : $values[$middle];

        return number_format($value, 1, '.', '');
    }

    private static function share(int $count, int $total): string
    {
        return $total === 0 ? '—' : number_format($count / $total * 100, 1, '.', '').'%';
    }

    /** @return array{label: string, value: string} */
    private static function metric(string $label, int|string $value): array
    {
        return ['label' => $label, 'value' => (string) $value];
    }

    /**
     * @param  list<string>  $columns
     * @param  list<list<string>>  $rows
     * @param  list<string>  $notes
     * @param  list<string>  $totals
     * @param  list<int>  $numeric
     * @return array{title: string, kind: 'table', columns: list<string>, rows: list<list<string>>, notes: list<string>, totals: list<string>, numericColumns: list<int>}
     */
    private static function section(string $title, array $columns, array $rows, array $notes = [], array $totals = [], array $numeric = []): array
    {
        return ['title' => $title, 'kind' => 'table', 'columns' => $columns, 'rows' => $rows, 'notes' => $notes, 'totals' => $totals, 'numericColumns' => $numeric];
    }
}
