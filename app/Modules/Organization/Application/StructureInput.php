<?php

declare(strict_types=1);

namespace App\Modules\Organization\Application;

use App\Domain\Enums\ModuleCode;
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

    /** "up" or "down"; anything else is refused. */
    public static function direction(string $direction): string
    {
        if ($direction !== 'up' && $direction !== 'down') {
            throw new RuntimeException('Choose Move up or Move down.');
        }

        return $direction;
    }
}
