<?php

declare(strict_types=1);

namespace OCA\MovieDB\Util;

/**
 * Validation for TMDB image paths (poster, backdrop, still, profile).
 *
 * TMDB paths are a bare filename with an optional leading slash, e.g.
 * "/abc123.jpg". Anything else (URLs, directory traversal, markup) is
 * rejected. Shared by the image proxy and the library import.
 */
final class TmdbPath {
    private const PATTERN = '/^[a-zA-Z0-9_-]+\.(jpg|jpeg|png|webp|svg)$/';

    public static function isValid(string $path): bool {
        return preg_match(self::PATTERN, ltrim($path, '/')) === 1;
    }
}
