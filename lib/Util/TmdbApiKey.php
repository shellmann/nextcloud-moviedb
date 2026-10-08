<?php

declare(strict_types=1);

namespace OCA\MovieDB\Util;

/**
 * Format check for TMDB API keys (Read Access Tokens).
 *
 * Only checks the shape of the value; whether TMDB accepts it is checked
 * separately by TmdbService::verifyApiKey(). Shared by the personal and the
 * instance-wide key settings.
 */
final class TmdbApiKey {
    private const MAX_LENGTH = 500;
    private const PATTERN = '/^[a-zA-Z0-9._\-]+$/D'; // D: $ must not match before a trailing newline

    public static function isValid(string $key): bool {
        return strlen($key) <= self::MAX_LENGTH && preg_match(self::PATTERN, $key) === 1;
    }
}
