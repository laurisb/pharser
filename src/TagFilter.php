<?php

declare(strict_types=1);

namespace App;

use function str_starts_with;
use function strtolower;

final class TagFilter
{
    private const EXACT_MATCHES = [
        'source' => true,
        'source_ref' => true,
        'created_by' => true,
        'attribution' => true,
        'note' => true,
        'fixme' => true,
        'naptan' => true,
        'naptan_ref' => true,
        'addr:TW:dataset' => true,
        "survey" => true,
    ];

    private const EXACT_MATCHES_LOWER = [
        'gns' => true,
    ];

    private const PREFIXES = [
        '_',
        'source:',
        'note:',
        'nysgissam:',
        'naptan:',
        'linz:',
        'LINZ:',
        'LINZ2OSM:',
        'gnss:',
    ];

    private const PREFIXES_LOWER = [
        'gns:',
        'gns_',
    ];

    public static function shouldSkip(string $tagName): bool
    {
        if (isset(self::EXACT_MATCHES[$tagName])) {
            return true;
        }

        foreach (self::PREFIXES as $prefix) {
            if (str_starts_with($tagName, $prefix)) {
                return true;
            }
        }

        $lower = strtolower($tagName);

        if (isset(self::EXACT_MATCHES_LOWER[$lower])) {
            return true;
        }

        foreach (self::PREFIXES_LOWER as $prefix) {
            if (str_starts_with($lower, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
