<?php

namespace App\Support\Tenancy;

/**
 * Explicit, auditable escape hatch for platform-level code paths
 * (authentication lookups, seeding, super-admin tooling).
 */
class TenantScopeBypass
{
    private static int $depth = 0;

    public static function run(callable $callback): mixed
    {
        self::$depth++;
        try {
            return $callback();
        } finally {
            self::$depth--;
        }
    }

    public static function active(): bool
    {
        return self::$depth > 0;
    }
}
