<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Models\ProjectCostCategory;
use App\Models\ProjectFinance;
use App\Modules\WorkManagement\Application\FinanceRules;
use App\Modules\WorkManagement\Domain\Projects\FinanceAmount;
use App\Support\Access;
use App\Support\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class SetupProjectFinance
{
    public function handle(Access $actor, string $projectId, string $currency): string
    {
        return DB::transaction(static function () use ($actor, $projectId, $currency): string {
            $project = FinanceRules::locked($actor, $projectId, admin: true);
            Validator::make(['currency' => $currency], ['currency' => ['required', Rule::in(array_keys(FinanceAmount::CURRENCIES))]])->validate();
            $existing = ProjectFinance::query()->where('project_id', $projectId)->first();
            if ($existing !== null) {
                if ($existing->currency !== $currency) {
                    if (FinanceRules::hasFinancialEntries($projectId, lock: true)) {
                        throw ValidationException::withMessages(['currency' => 'Currency is fixed after cost or cash entries are recorded.']);
                    }
                    $existing->currency = $currency;
                    $existing->save();
                    AuditService::record($actor, AuditEvent::PROJECT_FINANCE_CURRENCY_CHANGED, 'projects', $projectId, 'Project finance currency changed to '.$currency);
                }

                return $existing->id;
            }
            $settings = new ProjectFinance;
            $settings->forceFill(['project_id' => $projectId, 'currency' => $currency])->save();
            $category = new ProjectCostCategory;
            $category->forceFill(['project_id' => $projectId, 'name' => 'General'])->save();
            AuditService::record($actor, AuditEvent::PROJECT_FINANCE_ENABLED, 'projects', $projectId, 'Finance enabled for '.$project->code.' in '.$currency);

            return $settings->id;
        });
    }
}
