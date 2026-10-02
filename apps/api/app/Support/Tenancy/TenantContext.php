<?php

namespace App\Support\Tenancy;

use App\Models\User;

/**
 * Request-scoped holder for the authenticated tenant and user.
 * Bound as a scoped singleton so it resets between requests/jobs.
 */
class TenantContext
{
    private ?string $organisationId = null;

    private ?User $user = null;

    public function set(User $user): void
    {
        $this->user = $user;
        $this->organisationId = $user->organisation_id;
    }

    /** Used by queued jobs/console commands acting on behalf of a tenant. */
    public function setOrganisation(string $organisationId): void
    {
        $this->organisationId = $organisationId;
    }

    public function clear(): void
    {
        $this->user = null;
        $this->organisationId = null;
    }

    public function organisationId(): ?string
    {
        return $this->organisationId;
    }

    public function user(): ?User
    {
        return $this->user;
    }

    public function hasTenant(): bool
    {
        return $this->organisationId !== null;
    }
}
