<?php

declare(strict_types=1);

namespace App\Platform\ReadModels;

/** Detached view data: property access cannot lazy-load or mutate persistence. */
abstract readonly class Record
{
    /** @param array<string, mixed> $values
     * @param  array<string, mixed>  $serialized
     */
    public function __construct(private array $values, private array $serialized = []) {}

    public function __get(string $name): mixed
    {
        return $this->values[$name] ?? null;
    }

    public function __isset(string $name): bool
    {
        return isset($this->values[$name]);
    }

    public function getAttribute(string $name): mixed
    {
        return $this->__get($name);
    }

    public function getKey(): mixed
    {
        return $this->__get('id');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->serialized !== [] ? $this->serialized : $this->values;
    }
}
