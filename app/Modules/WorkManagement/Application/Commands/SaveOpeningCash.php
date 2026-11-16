<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Modules\WorkManagement\Application\FinanceRules;
use App\Modules\WorkManagement\Application\FundingRules;
use App\Modules\WorkManagement\Domain\Projects\FinanceAmount;
use App\Support\Access;
use App\Support\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class SaveOpeningCash
{
    public function handle(Access $actor, string $projectId, int $revision, ?string $date, string $amount, string $reference, string $notes = '', string $reason = ''): void
    {
        DB::transaction(static function () use ($actor, $projectId, $revision, $date, $amount, $reference, $notes, $reason): void {
            $project = FinanceRules::locked($actor, $projectId, admin: true);
            $settings = FinanceRules::settings($project->id);
            if ($settings->opening_revision !== $revision) {
                throw ValidationException::withMessages(['opening' => 'The opening record changed. Reopen it before saving.']);
            }
            Validator::make(['reference' => trim($reference), 'notes' => trim($notes), 'reason' => trim($reason)], [
                'reference' => [trim($amount) !== '' ? 'required' : 'nullable', 'string', 'max:200'], 'notes' => 'nullable|string|max:4000',
                'reason' => [$revision > 0 ? 'required' : 'nullable', 'string', 'max:1000'],
            ])->validate();
            $minor = trim($amount) === '' ? null : FinanceAmount::signed($amount, 'opening');
            if ($minor !== null && $date === null) {
                throw ValidationException::withMessages(['openingOn' => 'Choose the start date of the opening cash record.']);
            }
            FundingRules::date($date, 'openingOn', true);
            $history = $settings->opening_history ?? [];
            if ($settings->opening_revision > 0) {
                $history[] = ['revision' => $settings->opening_revision, 'date' => $settings->opening_on, 'amount' => $settings->opening_minor,
                    'reference' => $settings->opening_reference, 'notes' => $settings->opening_notes, 'reason' => trim($reason), 'by' => (int) $actor->userId, 'at' => now()->toIso8601String()];
            }
            $settings->forceFill(['opening_on' => $minor === null ? null : $date, 'opening_minor' => $minor, 'opening_reference' => trim($reference) ?: null,
                'opening_notes' => trim($notes) ?: null, 'opening_history' => $history, 'opening_revision' => $revision + 1])->save();
            AuditService::record($actor, AuditEvent::PROJECT_OPENING_RECORDED, 'projects', $project->id, 'Recorded opening cash revised with source reference');
        });
    }
}
