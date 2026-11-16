<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Commands;

use App\Domain\Projects\ProjectPolicy;
use App\Models\Project;
use App\Modules\IdentityAccess\Contracts\AccountNames;
use App\Modules\Organization\Contracts\OrganizationDirectory;
use App\Modules\WorkManagement\Application\ProjectDraft;
use App\Modules\WorkManagement\Application\Queries\WorkExports;
use App\Modules\WorkManagement\Application\WorkRules;
use App\Modules\WorkManagement\Infrastructure\WorkScope;
use App\Platform\Transfer\ImportLayout;
use App\Platform\Transfer\ImportOutcome;
use App\Platform\Transfer\ImportRow;
use App\Platform\Transfer\TableImport;
use App\Support\Access;
use RuntimeException;

/**
 * The projects list read back from its CSV — the export's own columns, less
 * the derived Progress and Health. Managers only (all_data + PROJECTS, the
 * create gate).
 *
 * A blank Code creates through {@see CreateProject} (its minted PRJ- code,
 * leader and site rules, PROJECT_CREATED audit); a known Code saves through
 * {@see UpdateProject}, which keeps every task inside the new timeline and
 * moves the status only along the manual map. The description is not in the
 * file and is kept. Site and Task Leader are named as the table names them.
 */
final class ImportProjects implements TableImport
{
    /** @var array<string, list<string>>|null lower-cased site name => ids */
    private ?array $sites = null;

    /** @var array<string, list<string>>|null lower-cased account name => ids */
    private ?array $accounts = null;

    public function layout(Access $actor, string $context): ImportLayout
    {
        return new ImportLayout('projects', 'Code', WorkExports::PROJECT_IMPORT_COLUMNS, [
            'Site names a site from Site Settings. Task Leader names the project lead account; either may be blank.',
            'Status is one of '.implode(', ', ProjectPolicy::STATUSES).', and moves only as the project screen allows. Descriptions stay as they are.',
        ]);
    }

    public function allows(Access $actor, string $context): bool
    {
        return WorkRules::managesProjects($actor);
    }

    public function row(Access $actor, string $context, ImportRow $row): ImportOutcome
    {
        abort_unless($this->allows($actor, $context), 403);
        $code = $row->identifier('Code');
        $project = null;
        if ($code !== '') {
            $project = WorkScope::projects($actor)->where('code', $code)->first();
            if ($project === null) {
                throw new RuntimeException("No project has code {$code}. Leave Code blank to add a new project.");
            }
        }

        $status = $row->choice('Status', ProjectPolicy::STATUSES);
        $draft = new ProjectDraft(
            $row->text('Project'),
            (string) ($project->description ?? ''),
            $this->site($actor, $row->text('Site')),
            $status === '' ? null : $status,
            $row->date('Start date') ?? '',
            $row->date('Due date') ?? '',
            $this->leader($actor, $row->text('Task Leader')),
        );

        if ($project === null) {
            app(CreateProject::class)->handle($actor, $draft);

            return ImportOutcome::CREATED;
        }

        if ($project->name === trim($draft->name)
            && (string) $project->site_id === ($draft->siteId === 'NONE' ? '' : $draft->siteId)
            && (string) $project->manager_id === ($draft->managerId === 'NONE' ? '' : $draft->managerId)
            && WorkRules::dateKey($project->start_date) === $draft->startDate
            && (WorkRules::dateKey($project->due_date) ?? '') === $draft->dueDate
            && ($draft->status === null || $draft->status === $project->status)) {
            return ImportOutcome::UNCHANGED;
        }

        // Null: the project left the actor's scope or was deleted meanwhile.
        app(UpdateProject::class)->handle($actor, (string) $project->getKey(), $draft)
            ?? throw new RuntimeException('That project no longer exists.');

        return ImportOutcome::UPDATED;
    }

    /** A site named in the file → its id, or NONE for a blank cell. */
    private function site(Access $actor, string $name): string
    {
        if ($name === '') {
            return 'NONE';
        }
        if ($this->sites === null) {
            $this->sites = [];
            foreach (app(OrganizationDirectory::class)->sites($actor) as $site) {
                $this->sites[mb_strtolower(trim($site->name))][] = (string) $site->getKey();
            }
        }

        return self::one($this->sites[mb_strtolower($name)] ?? [], "Site: no work site is named {$name}.", "Site: more than one work site is named {$name}. Rename one in Site Settings first.");
    }

    /** An account named in the file → its id, or NONE for a blank cell; CreateProject/UpdateProject judge whether it may lead. */
    private function leader(Access $actor, string $name): string
    {
        if ($name === '') {
            return 'NONE';
        }
        if ($this->accounts === null) {
            $this->accounts = [];
            foreach (app(AccountNames::class)->list($actor) as $account) {
                $this->accounts[mb_strtolower(trim((string) $account->name))][] = (string) $account->id;
            }
        }

        return self::one($this->accounts[mb_strtolower($name)] ?? [], "Task Leader: no account is named {$name}.", "Task Leader: more than one account is named {$name}; choose the leader on the project screen.");
    }

    /** @param list<string> $ids */
    private static function one(array $ids, string $none, string $many): string
    {
        $ids = array_values(array_unique($ids));

        return match (count($ids)) {
            1 => $ids[0],
            0 => throw new RuntimeException($none),
            default => throw new RuntimeException($many),
        };
    }
}
