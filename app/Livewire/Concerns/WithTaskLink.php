<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use Livewire\Attributes\Url;

trait WithTaskLink
{
    /** Navigation input, not #[Locked]; openTask revalidates it before setting the locked selection. */
    #[Url(as: 'task', except: null)]
    public mixed $linkedTask = null;

    public function updatedLinkedTask(): void
    {
        if (! $this->followTaskLink()) {
            $this->closeTaskPanel();
        }
    }

    protected function followTaskLink(): bool
    {
        $requested = $this->linkedTask;
        $this->linkedTask = null;
        if (! is_string($requested) || $requested === '' || strlen($requested) > 26) {
            return false;
        }
        $this->openTask($requested);

        return $this->linkedTask !== null;
    }
}
