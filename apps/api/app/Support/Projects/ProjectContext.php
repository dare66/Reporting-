<?php

namespace App\Support\Projects;

/**
 * Request-scoped: which projects the current person may see, and which one
 * they are working in. Inactive (no filtering beyond the tenant) in console
 * commands and queued jobs, which act on explicit records.
 */
class ProjectContext
{
    /** @var list<string>|null */
    private ?array $accessible = null;

    private ?string $current = null;

    /** @param  list<string>  $accessible */
    public function set(array $accessible, ?string $current): void
    {
        $this->accessible = $accessible;
        $this->current = $current;
    }

    public function clear(): void
    {
        $this->accessible = null;
        $this->current = null;
    }

    public function active(): bool
    {
        return $this->accessible !== null;
    }

    /** @return list<string> the projects queries are limited to: the current one, else every accessible one */
    public function visible(): array
    {
        return $this->current !== null ? [$this->current] : ($this->accessible ?? []);
    }

    /** @return list<string> */
    public function accessible(): array
    {
        return $this->accessible ?? [];
    }

    public function current(): ?string
    {
        return $this->current;
    }

    public function canSee(?string $projectId): bool
    {
        return ! $this->active() || ($projectId !== null && in_array($projectId, $this->accessible ?? [], true));
    }
}
