<?php

namespace App\Domain\Query;

use App\Domain\Semantic\Catalog;
use App\Models\User;

/**
 * Everything the compiler needs to enforce authorisation inside SQL:
 * tenant, row-level predicates and column (dimension) visibility.
 */
final class SecurityContext
{
    /**
     * @param  array<int, array{dimension: string, values: array<string>}>  $rowFilters  values=[] means "no rows"
     */
    public function __construct(
        public readonly string $organisationId,
        public readonly ?string $userId,
        public readonly bool $canSeeSensitive,
        public readonly array $rowFilters = [],
    ) {}

    public static function forUser(User $user, Catalog $catalog): self
    {
        $roles = $user->roleKeys();
        $rowFilters = [];
        foreach ($catalog->policies as $policy) {
            if (array_intersect($roles, $policy['exempt_roles'])) {
                continue;
            }
            $values = $user->attribute($policy['user_attribute']);
            $rowFilters[] = ['dimension' => $policy['dimension_key'], 'values' => array_values(array_map('strval', (array) ($values ?? [])))];
        }

        return new self($user->organisation_id, $user->id, $user->hasPermission('data.sensitive'), $rowFilters);
    }

    /** Stable fingerprint so cached results are never shared across differing permissions. */
    public function fingerprint(): string
    {
        return hash('sha256', json_encode([$this->organisationId, $this->canSeeSensitive, $this->rowFilters]));
    }
}
