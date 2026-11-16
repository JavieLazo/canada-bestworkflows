<?php

declare(strict_types=1);

namespace App\Livewire\Projects\Support;

use App\Livewire\Concerns\TableDefaults;

trait WithPeopleList
{
    public string $peopleSearch = '';

    public string $peopleAccess = 'ALL';

    public string $peopleSort = 'name';

    public string $peopleDirection = 'asc';

    /** Independent member-list size; changing it must not move the background list. */
    public int $peoplePerPage = TableDefaults::DEFAULT_PAGE_SIZE;

    public function updatedPeoplePerPage(mixed $value): void
    {
        $this->peoplePerPage = in_array((int) $value, TableDefaults::PAGE_SIZE_OPTIONS, true)
            ? (int) $value : TableDefaults::DEFAULT_PAGE_SIZE;
        $this->resetPage('peoplePage');
    }

    public function updatedPeopleSearch(): void
    {
        $this->resetPage('peoplePage');
    }

    public function updatedPeopleAccess(): void
    {
        $this->resetPage('peoplePage');
    }

    public function sortPeople(string $key): void
    {
        if (! in_array($key, ['name', 'work'], true)) {
            return;
        }
        $this->peopleDirection = $this->peopleSort === $key && $this->peopleDirection === 'asc' ? 'desc' : 'asc';
        $this->peopleSort = $key;
        $this->resetPage('peoplePage');
    }

    protected function resetPeople(): void
    {
        $this->peopleSearch = '';
        $this->peopleAccess = 'ALL';
        $this->peopleSort = 'name';
        $this->peopleDirection = 'asc';
        $this->resetPage('peoplePage');
    }
}
