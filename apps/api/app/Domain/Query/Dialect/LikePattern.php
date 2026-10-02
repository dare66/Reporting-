<?php

namespace App\Domain\Query\Dialect;

/** Builds LIKE patterns from user text; backslash is the default escape in PostgreSQL and ClickHouse. */
final class LikePattern
{
    /** @param  'contains'|'starts_with'|'ends_with'  $mode */
    public static function for(string $needle, string $mode): string
    {
        $escaped = addcslashes($needle, '%_\\');

        return match ($mode) {
            'contains' => '%'.$escaped.'%',
            'starts_with' => $escaped.'%',
            'ends_with' => '%'.$escaped,
        };
    }
}
