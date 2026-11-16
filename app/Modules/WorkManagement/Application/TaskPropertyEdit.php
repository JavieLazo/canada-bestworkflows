<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application;

/** One property, with the value the editor actually saw. */
final readonly class TaskPropertyEdit
{
    /** @param string|list<string> $value
     * @param  string|list<string>  $seen
     */
    public function __construct(public string $field, public string|array $value, public string|array $seen) {}
}
