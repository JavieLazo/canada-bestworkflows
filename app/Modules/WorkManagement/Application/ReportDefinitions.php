<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application;

use App\Models\ProjectReport;
use App\Models\ProjectReportField;
use App\Models\ProjectReportTemplate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ReportDefinitions
{
    public static function protectDrafts(ProjectReportTemplate $template): void
    {
        $reports = ProjectReport::query()->where('project_id', $template->project_id)->where('template_id', $template->id)->where('status', 'DRAFT');
        if ($template->work) {
            (clone $reports)->update(['work' => true]);
        }
        if ($template->financial) {
            (clone $reports)->update(['financial' => true]);
        }
    }

    public const KINDS = ['WORK_PLAN' => 'Work and budget plan', 'PERIOD_RESULTS' => 'Period accomplishments', 'FINAL_RESULTS' => 'Final accomplishments', 'FINANCIAL' => 'Financial report'];

    public const SECTIONS = ['profile' => 'Project details', 'work' => 'Work plan', 'results' => 'Accomplishments', 'budget' => 'Approved budget', 'cash' => 'Recorded cash', 'funding' => 'Funding', 'contributions' => 'Contributions'];

    public const ENTITIES = ['PROJECT' => 'Project', 'TASK' => 'Work plan task', 'OUTCOME' => 'Objective / deliverable', 'INDICATOR' => 'Indicator', 'RESULT_ENTRY' => 'Accomplishment', 'BUDGET_LINE' => 'Budget cost', 'CASH_ENTRY' => 'Cash entry', 'REPORT' => 'Report', 'FUNDING' => 'Funding', 'CONTRIBUTION' => 'Contribution', 'CONTRIBUTION_DELIVERY' => 'In-kind delivery'];

    public const TYPES = ['TEXT' => 'Text', 'NUMBER' => 'Number', 'MONEY' => 'Money', 'DATE' => 'Date', 'CHOICE' => 'Choice', 'EVIDENCE' => 'Evidence'];

    /** @param array<string, mixed> $definition
     * @return array{sections: list<string>, fields: list<string>}
     */
    public static function validate(string $projectId, array $definition): array
    {
        $sections = $definition['sections'] ?? [];
        $fields = $definition['fields'] ?? [];
        Validator::make(['sections' => $sections, 'fields' => $fields], [
            'sections' => 'required|array|min:1|max:7', 'sections.*' => ['required', 'string', Rule::in(array_keys(self::SECTIONS))],
            'fields' => 'present|array|max:40', 'fields.*' => 'required|string',
        ])->validate();
        if (! array_is_list($sections) || ! array_is_list($fields)) {
            throw ValidationException::withMessages(['definition' => 'Use ordered section and field lists.']);
        }
        $canonical = [];
        foreach ($fields as $id) {
            $field = ProjectReportField::query()->where('project_id', $projectId)->whereKey($id)->where('archived', false)->first();
            if ($field === null) {
                throw ValidationException::withMessages(['definition' => 'Choose active fields from this project.']);
            }
            $section = match ($field->entity_type) {
                'TASK' => 'work', 'OUTCOME', 'INDICATOR', 'RESULT_ENTRY' => 'results', 'BUDGET_LINE' => 'budget', 'CASH_ENTRY' => 'cash',
                'FUNDING' => 'funding', 'CONTRIBUTION', 'CONTRIBUTION_DELIVERY' => 'contributions', default => null,
            };
            if ($section !== null && ! in_array($section, $sections, true)) {
                throw ValidationException::withMessages(['definition' => 'Enable the matching report section before adding its fields.']);
            }
            $canonical[] = $field->id;
        }

        return ['sections' => array_values(array_unique($sections)), 'fields' => array_values(array_unique($canonical))];
    }

    /** @param array{sections: list<string>, fields: list<string>} $definition
     * @return array{work: bool, financial: bool}
     */
    public static function scope(array $definition): array
    {
        $fields = ProjectReportField::query()->whereKey($definition['fields'])->get();

        $financial = array_intersect(['budget', 'cash', 'funding', 'contributions'], $definition['sections']) !== [] || $fields->contains('visibility', 'FINANCE');

        return ['work' => ! $financial || array_intersect(['work', 'results'], $definition['sections']) !== [] || $fields->contains(static fn (ProjectReportField $field): bool => $field->visibility === 'PROJECT' && $field->entity_type !== 'REPORT'),
            'financial' => $financial];
    }

    /** @return array{sections: list<string>, fields: list<string>} */
    public static function defaults(string $kind): array
    {
        return ['sections' => match ($kind) {
            'WORK_PLAN' => ['profile', 'work', 'budget'], 'FINAL_RESULTS' => ['profile', 'work', 'results'], 'FINANCIAL' => ['profile', 'budget', 'cash', 'funding', 'contributions'], default => ['profile', 'results'],
        }, 'fields' => []];
    }
}
