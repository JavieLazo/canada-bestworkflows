<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Project;
use App\Models\ProjectBudget;
use App\Models\ProjectIndicator;
use App\Models\ProjectOutcome;
use App\Models\ProjectRecordFile;
use App\Models\ProjectRecordValue;
use App\Models\ProjectReport;
use App\Models\ProjectReportField;
use App\Models\ProjectReportingPeriod;
use App\Models\ProjectReportSubmission;
use App\Models\ProjectReportTemplate;
use App\Models\ProjectResultEntry;
use App\Models\User;
use App\Modules\WorkManagement\Application\ReportDefinitions;
use App\Modules\WorkManagement\Application\ReportFieldValues;
use App\Modules\WorkManagement\Application\ReportSnapshotData;
use App\Modules\WorkManagement\Domain\Projects\ResultValue;
use App\Support\Access;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class PpmDemoReportingSeeder extends PpmDemoRecords
{
    /** @param Collection<int, User> $people */
    public function seedProject(Project $project, User $owner, Collection $people, int $number): void
    {
        $p = $project->id;
        $actor = Access::forUser($owner);
        $base = ['project_id' => $p, 'revision' => 1, 'created_by' => $owner->id, 'updated_by' => $owner->id];
        $objective = self::id($p.':objective');
        $this->keep(ProjectOutcome::class, $p.':objective', [...$base, 'name' => 'Improve delivery readiness', 'kind' => 'OBJECTIVE', 'status' => 'ACTIVE']);
        $this->keep(ProjectOutcome::class, $p.':deliverable', [...$base, 'name' => 'Verified handover package', 'kind' => 'DELIVERABLE', 'parent_id' => $objective, 'status' => 'ACTIVE']);
        foreach ([['Verified outputs', 'NUMERIC', 'SUM', 'outputs', '100'], ['Quality checks passed', 'NUMERIC', 'LATEST', '%', '95'], ['Handover accepted', 'QUALITATIVE', 'LATEST', null, null]] as $i => [$name, $kind, $aggregation, $unit, $target]) {
            $numeric = $kind === 'NUMERIC';
            $indicatorId = self::id($p.':indicator:'.$i);
            $this->keep(ProjectIndicator::class, $p.':indicator:'.$i, [...$base, 'outcome_id' => $objective, 'outcome_label' => 'Improve delivery readiness',
                'group_id' => self::id($p.':group:'.($i % 3)), 'work_label' => ['Discovery', 'Delivery', 'Acceptance'][$i], 'name' => $name,
                'kind' => $kind, 'aggregation' => $aggregation, 'direction' => 'AT_LEAST', 'unit' => $unit, 'target_scaled' => $target === null ? null : ResultValue::parse($target),
                'target_text' => $numeric ? null : 'Client acceptance recorded', 'starts_on' => $this->dateKey(60), 'ends_on' => $this->dateKey(-45),
                'status' => 'ACTIVE', 'evidence_required' => $i === 0, 'plan_version' => 1]);
            foreach (range(0, 2) as $j) {
                $key = $indicatorId.':entry:'.$j;
                $entryId = self::id($key);
                $this->keep(ProjectResultEntry::class, $key, ['project_id' => $p, 'indicator_id' => $indicatorId, 'plan_version' => 1, 'evidence_required' => $i === 0,
                    'occurred_on' => $this->dateKey($j === 2 ? 0 : 18 - $j * 10), 'value_scaled' => $numeric ? ResultValue::parse((string) ($i === 0 ? 20 + $j * 10 + $number % 9 : 75 + $j * 7)) : null,
                    'value_text' => $numeric ? null : ['Initial preparation', 'Acceptance checks underway', 'Ready for client review'][$j],
                    'state' => $numeric ? null : ['NOT_STARTED', 'PARTIAL', 'ACHIEVED'][($j + $number) % 3],
                    'narrative' => 'Demo accomplishment '.($j + 1).': verified against the delivery checklist.', 'issues' => $j === 1 ? 'Demo supplier timing needs attention.' : null,
                    'corrective_action' => $j === 1 ? 'Confirm the revised delivery date and responsible person.' : null, 'reference' => 'DEMO-RESULT-'.$number.'-'.$i.'-'.$j,
                    'created_by' => $owner->id, 'replaces_id' => $j === 1 ? self::id($indicatorId.':entry:0') : null,
                    'voided_at' => $j === 0 ? $this->at(7, 12, 0) : null, 'voided_by' => $j === 0 ? $owner->id : null,
                    'void_reason' => $j === 0 ? 'Demo correction: use the verified replacement.' : null]);
                if ($i === 0 && ! ($j === 2 && $number % 4 === 0)) {
                    $this->attachment($p, $entryId, $owner->id, 'RESULT_ENTRY');
                }
            }
        }
        $month = CarbonImmutable::parse($this->dateKey(0))->startOfMonth();
        foreach ([-1, 0, 1] as $i) {
            $date = $month->addMonths($i);
            $this->keep(ProjectReportingPeriod::class, $p.':period:'.$i, [...$base, 'name' => $date->format('F Y'), 'starts_on' => $date->format('Y-m-d'),
                'ends_on' => $date->endOfMonth()->format('Y-m-d'), 'due_on' => $date->endOfMonth()->addDays(7)->format('Y-m-d'), 'archived' => false]);
        }
        $finance = ProjectBudget::query()->where('project_id', $p)->exists();
        $specs = [['PROJECT', 'TEXT', 'Delivery location', null, 'PROJECT'], ['TASK', 'NUMBER', 'Inspection points', 'points', 'PROJECT'],
            ['REPORT', 'CHOICE', 'Delivery confidence', null, 'PROJECT'], ['REPORT', 'DATE', 'Next review', null, 'PROJECT'],
            ['REPORT', 'EVIDENCE', 'Supporting record', null, 'PROJECT']];
        if ($finance) {
            $specs[] = ['REPORT', 'MONEY', 'Forecast adjustment', $number % 7 === 0 ? 'USD' : ($number % 11 === 0 ? 'EUR' : 'CAD'), 'FINANCE'];
            $specs[] = ['REPORT', 'EVIDENCE', 'Financial supporting record', null, 'FINANCE'];
        }
        foreach ($specs as $i => [$entity, $type, $name, $unit, $visibility]) {
            $this->keep(ProjectReportField::class, $p.':field:'.$i, [...$base, 'entity_type' => $entity, 'name' => $name, 'type' => $type,
                'unit' => $unit, 'visibility' => $visibility, 'required' => false, 'total' => $type === 'NUMBER', 'archived' => false,
                'options' => $type === 'CHOICE' ? ['choices' => ['On track', 'At risk', 'Needs action']] : null]);
        }
        foreach ([0 => [$p, 'Demo delivery area'], 1 => [self::id($p.':task:3'), '12']] as $i => [$recordId, $input]) {
            $field = ProjectReportField::query()->findOrFail(self::id($p.':field:'.$i));
            $this->keep(ProjectRecordValue::class, $recordId.':answer:'.$field->id, ['project_id' => $p, 'field_id' => $field->id, 'record_id' => $recordId,
                'value' => ['value' => ReportFieldValues::normalize($actor, $field, $input, $p, $recordId)], 'revision' => 1, 'updated_by' => $owner->id]);
        }
        foreach (array_keys(ReportDefinitions::KINDS) as $kind) {
            if ($kind === 'FINANCIAL' && ! $finance) {
                continue;
            }
            $definition = ReportDefinitions::defaults($kind);
            if (! $finance) {
                $definition['sections'] = array_values(array_diff($definition['sections'], ['budget']));
            }
            $financial = array_intersect(['budget', 'cash', 'funding', 'contributions'], $definition['sections']) !== [];
            $fields = [self::id($p.':field:0'), self::id($p.':field:2'), self::id($p.':field:3'), self::id($p.':field:'.($financial ? 6 : 4))];
            if (in_array('work', $definition['sections'], true)) {
                $fields[] = self::id($p.':field:1');
            }
            if ($finance && in_array('budget', $definition['sections'], true)) {
                $fields[] = self::id($p.':field:5');
            }
            $definition['fields'] = $fields;
            $definition = ReportDefinitions::validate($p, $definition);
            $scope = ReportDefinitions::scope($definition);
            $this->keep(ProjectReportTemplate::class, $p.':template:'.$kind, [...$base, 'name' => 'Demo: '.ReportDefinitions::KINDS[$kind], 'kind' => $kind,
                'code' => null, 'definition' => $definition, 'work' => $scope['work'], 'financial' => $scope['financial'], 'archived' => false]);
        }
        foreach (['WORK_PLAN', 'PERIOD_RESULTS', 'FINAL_RESULTS', $finance ? 'FINANCIAL' : 'FINAL_RESULTS', 'FINAL_RESULTS'] as $index => $kind) {
            $template = ProjectReportTemplate::query()->findOrFail(self::id($p.':template:'.$kind));
            $reportId = self::id($p.':report:'.$index);
            $wanted = ['DRAFT', 'SUBMITTED', 'RETURNED', 'ACCEPTED', 'ACCEPTED'][$index];
            $budget = $template->financial ? self::id($p.':budget:'.($index === 0 ? 2 : 1)) : null;
            $this->keep(ProjectReport::class, $p.':report:'.$index, [...$base, 'template_id' => $template->id,
                'period_id' => self::id($p.':period:'.($index === 0 ? 1 : ($index === 2 || $index === 4 ? -1 : 0))), 'budget_id' => $budget,
                'title' => 'Demo: '.['Upcoming work plan', 'Current accomplishments', 'Returned handover review', 'Accepted update', 'Working amendment'][$index],
                'status' => 'DRAFT', 'work' => $template->work, 'financial' => $template->financial, 'summary' => 'Demo review of delivery, verified outputs and the next actions.',
                'issues' => $index === 2 ? 'Demo acceptance evidence needs a follow-up check.' : null, 'corrective_action' => $index === 2 ? 'Confirm the outstanding check before resubmitting.' : null]);
            $fileId = $this->attachment($p, $reportId, $owner->id, 'REPORT');
            foreach ([2 => ['On track', 'At risk', 'Needs action'][$number % 3], 3 => $this->dateKey(-7), 4 => [$fileId], 5 => '125.50', 6 => [$fileId]] as $fieldIndex => $input) {
                if (($fieldIndex >= 5 && ! $template->financial) || ($fieldIndex === 4 && $template->financial)) {
                    continue;
                }
                $field = ProjectReportField::query()->findOrFail(self::id($p.':field:'.$fieldIndex));
                $this->keep(ProjectRecordValue::class, $reportId.':answer:'.$fieldIndex, ['project_id' => $p, 'field_id' => $field->id, 'record_id' => $reportId,
                    'value' => ['value' => ReportFieldValues::normalize($actor, $field, $input, $p, $reportId)], 'revision' => 1, 'updated_by' => $owner->id]);
            }
            if ($wanted === 'DRAFT') {
                continue;
            }
            $report = ProjectReport::query()->findOrFail($reportId);
            $data = app(ReportSnapshotData::class)->build($actor, $report);
            if ($data['coverage']['blocking'] !== []) {
                continue;
            }
            $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $submission = self::id($reportId.':submission:1');
            $this->keep(ProjectReportSubmission::class, $reportId.':submission:1', ['project_id' => $p, 'report_id' => $reportId, 'version' => 1,
                'template_version' => 1, 'work' => $data['scope']['work'], 'financial' => $data['scope']['financial'], 'field_ids' => array_column($data['fields'], 'id'),
                'payload' => $payload, 'sha256' => hash('sha256', $payload), 'status' => $wanted, 'submitted_by' => $owner->id, 'reviewer_id' => $people[2]->id,
                'reviewer_history' => [], 'reviewed_by' => $wanted === 'SUBMITTED' ? null : $people[2]->id, 'reviewed_at' => $wanted === 'SUBMITTED' ? null : now(),
                'review_notes' => $wanted === 'RETURNED' ? 'Demo review: clarify the remaining acceptance check.' : null]);
            // These reports and files were created in this transaction; earlier submissions stay untouched.
            $this->upsert(ProjectReport::class, ['id' => $reportId], ['status' => $index === 4 ? 'DRAFT' : $wanted, 'current_submission_id' => $submission,
                'revision' => $index === 4 ? 4 : 3, 'amendment_reason' => $index === 4 ? 'Demo amendment: updated delivery narrative.' : null]);
            foreach (array_column($data['files'], 'id') as $id) {
                $this->upsert(ProjectRecordFile::class, ['id' => $id], ['retained_at' => now()]);
            }
        }
    }
}
