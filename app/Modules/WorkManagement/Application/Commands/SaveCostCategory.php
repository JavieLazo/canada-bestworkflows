<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Models\ProjectCostCategory;
use App\Modules\WorkManagement\Application\FinanceRules;
use App\Support\Access;
use App\Support\AuditService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class SaveCostCategory
{
    public function handle(Access $actor, string $projectId, ?string $categoryId, string $name): string
    {
        try {
            return DB::transaction(static function () use ($actor, $projectId, $categoryId, $name): string {
                FinanceRules::locked($actor, $projectId, edit: true);
                FinanceRules::settings($projectId);
                $name = trim($name);
                Validator::make(['categoryName' => $name], ['categoryName' => ['required', 'string', 'max:100']])->validate();
                $category = $categoryId === null ? new ProjectCostCategory : FinanceRules::category($projectId, $categoryId);
                $category->forceFill(['project_id' => $projectId, 'name' => $name]);
                if (! $category->exists || $category->isDirty()) {
                    $category->save();
                    AuditService::record($actor, AuditEvent::PROJECT_COST_CATEGORY_SAVED, 'projects', $projectId, 'Financial category '.$category->id.' saved');
                }

                return $category->id;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['categoryName' => 'This project already has a category with that name.']);
        }
    }
}
