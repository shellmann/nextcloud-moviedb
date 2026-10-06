<?php

declare(strict_types=1);

namespace OCA\MovieDB\Util;

/**
 * Cheap pre-check that runs before json_decode().
 *
 * A byte cap does not bound decode memory: every JSON object becomes a PHP
 * array (hundreds of bytes) and every element at least 16 bytes, so a 26 MB
 * file of tiny entries decodes to ~445 MB. Counting "{" and "," in the raw
 * string needs no extra memory and bounds the number of arrays and elements.
 *
 * The counts also include braces and commas inside strings, which can only
 * make the check stricter. Real exports stay far below the limits: a 25 MB
 * file of genuine data has well under 1 million commas and 300,000 objects.
 */
final class JsonComplexity {
    /** Upper bound for JSON objects ("{"): ~450 bytes each once decoded. */
    public const MAX_OBJECTS = 400000;

    /** Upper bound for array/object elements (","): ≥16 bytes each once decoded. */
    public const MAX_ELEMENTS = 2000000;

    public static function isTooComplex(string $json): bool {
        return substr_count($json, '{') > self::MAX_OBJECTS
            || substr_count($json, ',') > self::MAX_ELEMENTS;
    }
}
