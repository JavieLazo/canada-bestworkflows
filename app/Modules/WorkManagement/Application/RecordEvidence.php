<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application;

use App\Models\ProjectCashEntry;
use App\Models\ProjectContributionDelivery;
use App\Models\ProjectResultEntry;
use App\Support\Access;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

final class RecordEvidence
{
    public static function locked(Access $actor, string $projectId, string $type, string $recordId, bool $edit): Model
    {
        if ($type === 'RESULT_ENTRY') {
            $project = ReportingRules::locked($actor, $projectId, edit: $edit);
            $record = ProjectResultEntry::query()->where('project_id', $project->id)->whereKey($recordId)->lockForUpdate()->first();
        } elseif ($type === 'CASH_ENTRY') {
            $project = FinanceRules::locked($actor, $projectId, edit: $edit);
            $record = ProjectCashEntry::query()->where('project_id', $project->id)->whereKey($recordId)->lockForUpdate()->first();
        } elseif ($type === 'CONTRIBUTION_DELIVERY') {
            $project = FinanceRules::locked($actor, $projectId, edit: $edit);
            $record = ProjectContributionDelivery::query()->where('project_id', $project->id)->whereKey($recordId)->lockForUpdate()->first();
        } elseif ($type === 'REPORT') {
            $record = ReportFieldValues::record($actor, $projectId, 'REPORT', $recordId, $edit);
        } else {
            abort(404);
        }
        abort_if($record === null, 404);
        if ($edit && $record->getAttribute('voided_at') !== null) {
            throw ValidationException::withMessages(['evidence' => 'Attach evidence to the current entry. This earlier entry stays in history.']);
        }

        return $record;
    }
}
