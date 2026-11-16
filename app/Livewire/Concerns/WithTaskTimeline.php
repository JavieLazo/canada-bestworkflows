<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Modules\WorkManagement\Application\TaskTimeline;
use App\Modules\WorkManagement\Contracts\Views\ProjectTaskView;
use App\Support\ManilaClock;
use Livewire\Attributes\Url;

trait WithTaskTimeline
{
    #[Url(as: 'from', except: '')]
    public string $timelineFrom = '';

    #[Url(as: 'span', except: '28')]
    public string $timelineScale = '28';

    abstract protected function timelineTask(string $id): ?ProjectTaskView;

    protected function timelineWindow(): TaskTimeline
    {
        return TaskTimeline::window($this->timelineFrom, $this->timelineScale, ManilaClock::todayKey());
    }

    public function shiftTimeline(int $direction): void
    {
        $this->timelineFrom = $this->timelineWindow()->shifted($direction)->from;
    }

    public function zoomTimeline(int $direction, string $anchor = ''): void
    {
        $window = $this->timelineWindow()->zoomed($direction, $anchor);
        $this->timelineFrom = $window->from;
        $this->timelineScale = (string) $window->days;
    }

    public function todayOnTimeline(): void
    {
        $this->timelineFrom = '';
        $this->dispatch('work-timeline-focus-today');
    }

    public function showOnTimeline(string $id): void
    {
        $task = $this->timelineTask($id);
        if ($task === null) {
            return;
        }
        $date = $task->start_date ?? $task->due_date;
        if ($date !== null) {
            $this->timelineFrom = $this->timelineWindow()->around($date->format('Y-m-d'))->from;
        }
    }
}
