<?php

declare(strict_types=1);

namespace App\Modules\Organization\Application;

use App\Domain\Enums\ModuleCode;
use App\Models\OrgNode;
use App\Support\Access;
use RuntimeException;

/**
 * The checks every department, unit and position write repeats before it
 * opens its transaction: who may write, and the shape of a name and a
 * description. One copy, so the three editors refuse in the same words.
 */
final class StructureInput
{
    /** Department and unit names: the width of `org_nodes.name`. */
    public const int NODE_NAME_MAX = 160;

    /**
     * Position names: the width of `employee_profiles.position`, the copy
     * each holder's record keeps, so a name that fits here fits there.
     */
    public const int POSITION_NAME_MAX = 120;

    public const int DESCRIPTION_MAX = 2000;

    /** A head of office's name and designation: the width of each `org_nodes` column. */
    public const int HEAD_MAX = 200;

    public static function authorize(Access $access): void
    {
        if (! $access->mayReadAllData(ModuleCode::RECORDS)) {
            throw new RuntimeException('Your role cannot manage organization-wide data.');
        }
    }

    public static function name(string $name, int $max): string
    {
        $name = trim($name);
        if ($name === '') {
            throw new RuntimeException('Name is required.');
        }
        if (mb_strlen($name) > $max) {
            throw new RuntimeException("name: must be at most {$max} characters.");
        }

        return $name;
    }

    /** The trimmed description, or null when blank. */
    public static function description(string $description): ?string
    {
        $description = trim($description);
        if (mb_strlen($description) > self::DESCRIPTION_MAX) {
            throw new RuntimeException('description: must be at most '.self::DESCRIPTION_MAX.' characters.');
        }

        return $description === '' ? null : $description;
    }

    /**
     * The head of office to store. Nothing when the caller does not edit it
     * (both null), and nothing while the columns do not exist yet; blank
     * clears it.
     *
     * @return array{head_name?: string|null, head_designation?: string|null}
     */
    public static function head(?string $name, ?string $designation): array
    {
        if (($name === null && $designation === null) || ! StructureReads::headsReady()) {
            return [];
        }
        $name = trim((string) $name);
        $designation = trim((string) $designation);
        if (mb_strlen($name) > self::HEAD_MAX || mb_strlen($designation) > self::HEAD_MAX) {
            throw new RuntimeException('A head of office\'s name or designation must be at most '.self::HEAD_MAX.' characters.');
        }

        return ['head_name' => $name === '' ? null : $name, 'head_designation' => $designation === '' ? null : $designation];
    }

    /** What a save did to the head of office, for the audit sentence; '' when nothing. */
    public static function headSentence(OrgNode $node): string
    {
        if (! $node->wasChanged(['head_name', 'head_designation']) && ! ($node->wasRecentlyCreated && $node->head_name !== null)) {
            return '';
        }
        $who = trim((string) $node->head_name.($node->head_designation === null ? '' : ", {$node->head_designation}"));

        return '; head of office: '.($who === '' ? 'cleared' : $who);
    }

    /** "up" or "down"; anything else is refused. */
    public static function direction(string $direction): string
    {
        if ($direction !== 'up' && $direction !== 'down') {
            throw new RuntimeException('Choose Move up or Move down.');
        }

        return $direction;
    }
}
