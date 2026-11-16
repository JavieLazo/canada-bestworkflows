<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Commands;

use App\Models\ProjectReportTemplate;
use App\Modules\WorkManagement\Application\ReportDefinitions;
use App\Modules\WorkManagement\Application\ReportingRules;
use App\Support\Access;
use Illuminate\Support\Facades\DB;

final class UseGeneralReportTemplates
{
    public function handle(Access $actor, string $projectId): void
    {
        DB::transaction(static function () use ($actor, $projectId): void {
            $project = ReportingRules::locked($actor, $projectId, admin: true);
            foreach (ReportDefinitions::KINDS as $kind => $name) {
                if (! ProjectReportTemplate::query()->where('project_id', $project->id)->where('code', $kind)->exists()) {
                    app(SaveReportTemplate::class)->handle($actor, $project->id, null, 0, $name, $kind, ReportDefinitions::defaults($kind), code: $kind);
                }
            }
        });
    }
}
